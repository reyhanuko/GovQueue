# GovQueue

Online Appointment and Queue Management System for the Local Government Unit (LGU) of
Tupi, South Cotabato. IT323 capstone project.

GovQueue replaces the LGU's manual, walk-in-only, first-come-first-served process with an
appointment-driven queue system for clients, office staff, and administrators.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2, Laravel 12, Eloquent ORM |
| Frontend | Blade, Livewire 4, Alpine.js, Tailwind CSS v4, Vite |
| Database | MySQL 8 |
| Realtime | Laravel Reverb (WebSockets) via Laravel Echo + Pusher protocol |
| Mail / SMS | Laravel queued Mailables + SMS driver behind a provider interface |
| Tests | PHPUnit, Pest-style feature tests, SQLite in-memory for the test suite |

> **Note on the Laravel version:** the original plan specified Laravel 11. Composer refuses to
> install it because every `11.x` release carries an unresolved security advisory. The project
> therefore targets Laravel 12 (the current supported line). No API or syntax differences affect
> this codebase.

## Sprint status

| Sprint | Scope | Status |
|---|---|---|
| S0 | Foundation: scaffold, Reverb, MySQL, layout, navigation shell | **Done** |
| S1 | Database schema, migrations, models, roles, auth | **Done** |
| S2 | Service & slot catalogue, client profile, priority eligibility | **Done** |
| S3 | Appointment booking with slot capacity control | **Done** |
| S4 | Queue lifecycle, wait-time estimation, priority verification, staff console | **Done** |
| S5 | Real-time queue tracking and digital display | **Done** |
| S6 | Automated client notifications | **Done** |
| S7 | Admin configuration | Next |
| S8 | Analytics and reporting dashboard | Planned |
| S9 | Client self-service screens | Planned |
| S10 | ISO 25010 testing, hardening, deployment runbook | Planned |

## Requirements

- PHP 8.2+ with `pdo_mysql`
- Composer 2
- Node 20+ and npm
- MySQL 8 running locally
- `composer install` then `npm install && npm run build`

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# create the MySQL database, then set DB_* in .env
php artisan migrate
php artisan db:seed

npm run build
```

## Slot capacity control (feature 1)

Capacity is enforced by `BookingService`, not by the UI. The slot row is re-read
inside a transaction with `SELECT ... FOR UPDATE`, so two clients racing for the
last place serialise on that lock and the second one is refused.

`BookingConcurrencyTest` proves this by spawning six real PHP processes that all
target one slot simultaneously:

```bash
php artisan test --filter=BookingConcurrencyTest
```

It asserts that exactly `capacity` clients win, `booked_count` never exceeds
capacity, and no two bookings are issued the same queue number. To confirm the
test is not vacuous, set `GOVQUEUE_DISABLE_ROW_LOCK=true` and re-run: the
capacity assertions fail, because without the lock every racer wins. Never set
that variable outside a test.

## Queue lifecycle

`QueueService` is the only place an appointment's status may change. The legal
moves live in `AppointmentStatus::canTransitionTo()`, so every state change goes
through one validated path that also writes the audit trail and the client's
visible history.

```
pending ─> confirmed ─> checked_in ─> serving ─> completed
                             │            │
                             └────────────┴──> skipped ──┐
                                            └─> no_show  │ (back into the line)
                                                        └──────────────┘
```

Two edges exist for natural counter behaviour: `serving -> no_show` (a client can
walk away mid-transaction) and `skipped -> skipped` ("not ready" pressed twice
must not error).

### Ordering

`QueueOrderingService` is the single source of truth for who is next, shared by
the staff console, the client view, and the public display. Order is by stage
first, then **verified** statutory priority, then arrival, then reference number:

1. The client at the window
2. Clients waiting at the counter
3. Clients who asked to wait longer (skipped)
4. Clients who have not arrived yet

Step 3 sits below step 2 deliberately: a client who says "not ready" has
deferred their own turn, so they go behind the people actually waiting.

A priority claim only counts once staff verify it. `QueueService::verifyPriority()`
records the decision and who made it; `refusePriority()` clears the claim with a
reason. GovQueue never checks eligibility against an external database.

## Real-time (Reverb)

Three surfaces subscribe to the queue:

| Surface | Component | Channel | Kind |
|---|---|---|---|
| Lobby display (`/queue-display`) | `display.public-queue-display` | `queue.{WINDOW}` | public |
| Client's ticket page | `client.queue-status` | `appointment.{id}` | private |
| Staff console (`/staff`) | `staff.queue-console` | `queue.{WINDOW}` | public |

**The lobby display is public on purpose** - it is not signed in and must not be.
Its privacy therefore comes from the *payload*, not from channel authorisation:
`QueueUpdated` carries only the window code, window name, queue date, and a
timestamp. No client name, service, queue reference, or priority claim ever
travels on that channel, and `RealtimeAndSecurityTest` asserts that.

Only private channels are registered in `routes/channels.php`, because
`/broadcasting/auth` is the only path Echo asks the server to authorise -
`Echo.channel()` for a public channel connects with the app key alone.
Registering a public channel callback was actively harmful: Laravel's
`validAuthenticationResponse()` calls `method_exists()` on the authenticated user
regardless of channel type, so authorising one for a guest threw and returned 500.

Queue events use `ShouldBroadcastNow`, not `ShouldBroadcast`. A queued event sits
in the jobs table until a worker runs, which made the public display depend on
`queue:work` being up. Email and SMS (Sprint 6) stay queued, where the delay is
fine.

### Why a synchronous broadcast needed a circuit breaker

Broadcasting synchronously is what makes the lobby display correct, but it also
puts the socket inside the client's request. With Reverb not running, cURL's
default has no timeout, so **every booking cost 2.7 seconds** - two triggers per
booking, each burning a full connect timeout.

Two changes removed it:

- `config/broadcasting.php` sets `timeout`/`connect_timeout` to 1s for the Reverb
  client. That alone took a single trigger from 2.3s to 1.0s.
- `QueueBroadcaster` puts a **circuit breaker** in front of the transport. The
  first failure records it for `govqueue.broadcast_failure_cooldown` (30s);
  everything after that is skipped. A successful broadcast clears it.

Measured with Reverb deliberately stopped: **2.7s -> 57-114ms per booking**.

The breaker's cache reads are wrapped, because GovQueue's cache is the database -
the same store most likely to be the thing that is broken. A cache failure
degrades to "always attempt" rather than taking broadcasting down with it.
`BroadcastCircuitBreakerTest` covers all of it, including that a booking still
commits when every broadcast throws.

Verify the whole path, including a live socket round trip:

```bash
php artisan reverb:start                      # in another terminal
php artisan govqueue:verify-realtime          # connects, subscribes, dispatches, asserts
```

## Error pages never leak internals

A database outage used to surface as a stack trace that dumped the request
context as JSON - including a client's birthdate, phone number, and disability
status. That was reported as "weird UI showing a TimeSlot".

`bootstrap/app.php` now matches MySQL connection failures and returns a plain
**503 "temporarily unavailable"** page (`errors/unavailable.blade.php`), with the
detail going to the log. API and AJAX callers get a JSON 503 instead of HTML.

That page extends the site layout, which surfaced a second bug: the flash partial
read `$errors->any()` and `session()` unconditionally. Error pages are rendered by
the exception handler, where `$errors` may not have been shared by middleware yet
- and since sessions live in the database, `session()` throws during exactly the
outage this page exists for. Either failure replaced a readable message with a
blank 500 and hid the real cause, so both are now guarded.
`DatabaseOutageTest` pins the behaviour.

## Client notifications

`ClientNotificationService` is the only place notifications are dispatched, so
channel isolation and the delivery log cannot be bypassed.

| Channel | Transport | Notes |
|---|---|---|
| Email | Queued mailables | Requires `php artisan queue:work` |
| SMS | `SmsManager` gateway | `log` (default), `semaphore`, or `textme` |
| In-app | Database notifications | Always on |

Four message types: confirmation, reminder (24h and 1h before), rescheduled, cancelled.

**A failed channel never breaks the others, or the booking.** Each attempt is
written to `notification_logs`, which is why `/admin/notifications` exists: the
project explicitly does not promise delivery, so staff need to see what was
attempted and why it failed.

### SMS gateway

```env
GOVQUEUE_SMS_ENABLED=true
GOVQUEUE_SMS_DRIVER=semaphore      # log | semaphore | textme
SEMAPHORE_API_KEY=...
```

`log` is the default so a fresh checkout demonstrates the whole feature with no
paid account; messages go to the application log and the admin screen names the
driver so log output is never mistaken for a real text.

### Reminders

```bash
php artisan govqueue:send-reminders --dry-run   # report without sending
php artisan govqueue:send-reminders             # send
```

Scheduled hourly. Offsets already sent are recorded on
`appointments.reminders_sent`, so an appointment can never be reminded twice for
the same offset even if two runs overlap. Cancelled, completed, and already
checked-in tickets are never reminded.

## Testing

```bash
php artisan test                          # full suite (381 tests, ~4 min)
php artisan test --filter=BookingServiceTest
./vendor/bin/pint --test                  # code style
```

Tests run against **MySQL** (`govqueue_test`), not SQLite, because the booking
lock is only meaningful with real row-level locking.

The test database is shared, so any test that commits rows must clean up in
`tearDown` or it leaks into whatever runs next.

## Demo accounts

All seeded accounts use the password `password`.

| Email | Role | Notes |
|---|---|---|
| `admin@govqueue.test` | Administrator | Full back office |
| `staff1@govqueue.test` | Office Staff | Window 1 |
| `client@govqueue.test` | Client | Ordinary resident |
| `senior@govqueue.test` | Client | RA 9994 senior citizen |
| `pwd@govqueue.test` | Client | RA 10754 person with disability |
| `pregnant@govqueue.test` | Client | Pregnant client |

## Useful commands

```bash
php artisan govqueue:queue                    # print today's queue in service order
php artisan govqueue:queue --window=WINDOW_1  # limit to one window
php artisan govqueue:generate-slots --days=30 # expand slot templates into bookable slots
```

`govqueue:generate-slots` is idempotent and never lowers a slot's capacity below
the number of clients already booked into it.

## Running (three terminals)

```bash
php artisan serve                 # web app           -> http://127.0.0.1:8000
php artisan reverb:start          # websockets        -> ws://127.0.0.1:8080
php artisan queue:work            # queued mail + notification jobs
php artisan schedule:work         # reminders + slot generation
```

For production, run the scheduler from cron instead of `schedule:work`:

```cron
* * * * * cd /path/to/govqueue && php artisan schedule:run >> /dev/null 2>&1
```

## Scope

In scope: appointment scheduling with slot capacity control, real-time queue tracking and
digital display, priority-based queue automation, automated client notification, analytics and
reporting, and role-based account management.

Out of scope (per the approved scope): tax collection and internal HR systems, booking for
clients without internet access (they use the existing walk-in process), independent
verification of priority claims against external government databases, and any guarantee of
SMS/email delivery.

## Legal basis

Priority handling implements **RA 9994** (Expanded Senior Citizens Act of 2010) and
**RA 10754** (Persons with Disability Act).