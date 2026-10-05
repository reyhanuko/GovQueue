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
| S7 | Admin configuration: windows, services, slot templates, capacity, holidays, staff, audit | **Done** |
| S8 | Analytics and reporting dashboard | **Done** |
| S9 | Client self-service screens | Next |
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

## Administration (Sprint 7)

`/admin` is a real back office, in its own `role:admin` group.

| Screen | Route | What it is for |
|---|---|---|
| Dashboard | `/admin` | Today's queue, and the things that need a human decision |
| Windows | `/admin/windows` | The counters clients are directed to |
| Services | `/admin/services` | The catalogue clients book from |
| Slot templates | `/admin/services/{code}/templates` | The recurring weekly rule per service |
| Daily slots | `/admin/slots` | Per-day capacity, open/close, bulk rescale |
| Holidays | `/admin/holidays` | Declared closures, plus who is affected |
| Staff | `/admin/staff` | Staff and administrator accounts |
| Audit trail | `/admin/audit` | Who changed what, including refusals |

Rules live in `ScheduleAdminService` and `StaffAdminService`, not in the
controllers, so they cannot be skipped by adding another entry point.

### Why the database constraints are not enough

The foreign keys do the same job far too late and far too bluntly:

| Constraint | Behaviour | What an administrator actually needs |
|---|---|---|
| `appointments.service_id` | `restrictOnDelete` | A booked service cannot be deleted at all - but the reason should read "deactivate it instead", not a stack trace |
| `services.service_window_id` | `cascadeOnDelete` | Deleting a window would silently delete **every service in it** |
| `time_slots.service_window_id` | `cascadeOnDelete` | Generated slots would vanish with their window |
| `holidays.date` | `unique` | A duplicate should be a form message, not an integrity error |

So the checks run first and the constraints stay as a last line of defence.
Refusals raise `AdminActionBlockedException`, whose message states the blocker and
whose `hint` states the way out - "no" on its own is useless at 5pm.

### Guarded actions

- A window with services or staff cannot be deleted; deactivate it instead.
- A service with any appointment, or with bookable slots still open, cannot be
  deleted.
- Slot capacity cannot drop below `booked_count`. Below that the slot is
  permanently over-subscribed: `isBookable()` goes false for everyone and
  `remainingCapacity()` goes negative, so the clients already booked would vanish
  from the slot they hold.
- A template whose slot length exceeds its opening window is refused - the
  generator drops any slot that would overrun, so it would yield nothing at all
  and the service would silently have no availability.
- The last active administrator cannot be demoted, deactivated, deleted, or have
  their password reset by themselves.
- Declaring a holiday does **not** cancel appointments. It hides those slots from
  every booking screen, so the screen names each affected client instead.

### Side effects that are easy to miss

Moving a service to a different window also moves its `time_slots` and its
**upcoming** appointments. Those rows are what the staff console and the lobby
display group by, so a service left behind would keep appearing on the old
window's board - a queue nobody is working. Completed appointments are left
alone: they record what actually happened, and rewriting them would put a
finished transaction on a window it was never served at.

### Refusals are audited too

A trail showing only successes reads as an all-clear. Blocked deletes, refused
capacity changes, rejected templates, and refused administrator demotions are all
recorded (`*_blocked`, `*_rejected`) with the reason - repeated attempts to demote
the last administrator are exactly the pattern worth surfacing.

### Availability must mean one thing

Three answers to "can a client book this?" were built by hand and drifted apart:

| Method | Used by | Drift found |
|---|---|---|
| `availabilityFor()` | booking grid, service page | correct |
| `openSlotCount()` | catalogue listing | ignored holidays |
| `nextAvailableSlot()` | service page "next open slot" hint | ignored the horizon **and** holidays |

The hint ignored the booking horizon, so with a 30-day horizon it advertised a slot
40 days out - a date the booking screen then refused to open, while a free slot
three days away went unmentioned. All three now share one `bookableQuery()`.
`SlotAvailabilityConsistencyTest` asserts they agree rather than testing each in
isolation, because the bug was precisely that they were tested separately.

Regenerating slots also had a related bug: capacity was clamped with
`max(existing, template)`, so the old number was always the larger one and an
administrator lowering a template from 4 to 2 changed nothing on any slot that had
ever been generated. The floor is `booked_count`, which is what the comment always
claimed.

## Reports (Sprint 8)

`/admin/reports` and a CSV export, built on `ReportingService`. Everything is
scoped to a period the administrator chooses (presets or explicit dates).

| Section | Question it answers |
|---|---|
| At a glance | How much came through, how much was served, cancelled, missed |
| Wait and service time | How long people actually waited, and how good the estimate is |
| Demand against capacity | Which services are over-subscribed and which are idle |
| Service windows | How each counter is coping, and fees collected |
| When clients arrive | Which hours need staffing |
| Priority claims | RA 9994 / RA 10754 compliance: claimed vs approved vs refused |
| Cancellation reasons | Why people give up |

### Three rules that shaped every number

**1. No data is null, never zero.** "Average wait: 0 minutes" reads as "we served
everyone instantly". Every average carries its sample size, and anything under
`RELIABLE_SAMPLE` (5) is labelled *indicative only* — the same threshold
`QueueService::averageServiceMinutes()` already used. A rate with no denominator
is `null`, not `0%`, because "no tickets" and "no cancellations" are different
statements.

**2. One date key per question.** Bookings made (`created_at`) and tickets that
belonged to a day (`queue_date`) are reported as separate, clearly labelled
figures. Everything compared against a slot calendar uses `queue_date`, so demand
and supply describe the same days. My first version mixed them and printed
"Booked 27, places offered 1600, utilisation 0%" — two unrelated sets of days.

**3. Occupancy is not demand.** `booked_count` is *current* occupancy and a slot
releases its place the moment a client is served, so every slot in a past window
reads as empty and "did we run out?" always answers no. Utilisation is demand over
supply; "slots filled" counts slots where appointments ever attached reached the
ceiling.

### The estimate column was dead

`appointments.estimated_wait_minutes` existed as a fillable, cast column that
**nothing ever wrote to**. The estimate was computed on the fly for the live
display and thrown away, so there was no record of what a client was actually
told — and "how good is our wait estimate?", the question a queue system is judged
on, could not be answered at all.

`QueueService::checkIn()` now stores the estimate. Check-in is the honest moment:
`actualWaitMinutes()` measures `checked_in_at → serving_started_at`, so the figure
recorded is measured over exactly the window the client was promised, making the
two directly comparable. A booking alone records nothing — a slot booked for next
week has no meaningful wait yet.

### Priority refusals are read from the audit trail

`QueueService::refusePriority()` deliberately clears all three claim flags. That is
correct for queue order — a refused claim must stop granting precedence
immediately — but it makes a refused claim indistinguishable from never having been
made. The audit log is the only place the decision, the reason, and the staff
member survive, so the compliance report reads refusals from there. An audit trail
that only exists for show is not one.

### Exported CSV

`/admin/reports/export` returns a UTF-8 CSV with a byte-order mark so Excel opens
the peso sign and Filipino text correctly. It is built into a string before being
returned: writing to a `php://output` handle and then handing that same handle to
`streamDownload()` would send an already-closed stream, producing an empty file
with a 200.

## Accessibility

A structural sweep over all 23 screens found and fixed:

- **Duplicate element ids on the slot-template page.** One form per template, all
  sharing field ids, so clicking the "Opens" label on the fourth template focused
  the *add* form's field and its help text was announced for the wrong control.
- **`old()` is global, not per-form.** A rejected save on the add form refilled
  every existing template's edit form with that rejected input, as though an
  administrator had typed it into each. The field component now takes
  `:use-old="false"` for the per-record forms.
- Four `<th>` with no `scope` on the client's appointment history.
- **No navigation at all below 768px.** The primary links were `hidden md:flex`
  with no mobile replacement, so anyone on a phone could only reach Home,
  Services, or the Queue Display by typing the URL. Now a native `<details>`
  disclosure - no JavaScript, keyboard operable.
- **Two different font weight lists.** The app layout requested 400-800 while the
  views use `font-black` (900), so every `font-black` heading silently rendered at
  800; the display had the mirror problem. One list now, in
  `layouts/partials/fonts.blade.php`.

`AccessibilityTest` asserts all of this structurally, so the whole class of mistake
is caught rather than the individual instance.

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
php artisan test                          # full suite (522 tests, ~5 min)
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