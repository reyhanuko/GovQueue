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
| S9 | Client self-service: password reset, notification preferences | **Done** |
| S10 | ISO 25010 evaluation, hardening, deployment runbook | **Done** |

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

For a real deployment rather than a laptop, follow the
[deployment runbook](#deployment-runbook) instead - it covers the four processes
that must be running, the order they start in, and how to tell each one is healthy.

## Deployment runbook

### The four processes

GovQueue is not a single process. Email, reminders and WebSockets each need one,
and booking still works without them but stops being correct.

| Process | Command | If it is down |
|---|---|---|
| Web | `php artisan serve --port=8000` (or nginx + PHP-FPM) | Nothing works |
| WebSockets | `php artisan reverb:start` | The lobby display freezes; booking still works |
| Workers | `php artisan queue:work` | No email or SMS is sent; bookings still work |
| Scheduler | `php artisan schedule:work` | No reminders; bookings still works |

The last two matter most, because a stopped worker fails *quietly*. Nothing errors;
clients simply stop hearing about their appointments. The dashboard and
`/admin/notifications` are where you notice.

### Deploying an update

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Take the site down for the schema change, or accept the deploy window.
php artisan down --render="errors::503" --retry=15

php artisan migrate --force        # never migrate:fresh on real data
php artisan config:cache          # after editing .env, or the new values are ignored
php artisan route:cache
php artisan view:cache

php artisan queue:restart         # makes long-running workers pick up new code
php artisan up
```

`queue:restart` is the step that is easy to forget. Without it, a worker started
before the deploy keeps running the *old* code in memory, so a bug fix appears not
to work and the next deploy is blamed.

### Verifying a deployment

```bash
curl -s localhost:8000/up          # 200 with "status":"ok", 503 if a dependency is down
php artisan govqueue:verify-realtime
php artisan govqueue:queue         # today's queue, in service order
```

`/up` is the health check. It reports whether each dependency *answers* and
deliberately never says what is inside it, so a load balancer can use it without
credentials and without leaking schema to the internet. It returns 503 when
something it depends on is unreachable, so a monitor can act on the status code
alone. A stale lobby display is reported as `warn`, not `fail` - the office can
still book and serve, so that must not take the deployment out of rotation.

### Before go-live

```bash
php artisan config:cache --env=production
php artisan about                  # confirm the environment is production
```

Set these in `.env`. The defaults are development values and shipping them is the
usual cause of a public queue system behaving oddly.

| Variable | Why it matters |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false` | Debug pages dump full model JSON, including clients' birthdates and phones |
| `APP_URL` | Signed password-reset links are built from it; wrong host and every link 403s |
| `SESSION_SECURE_COOKIE=true` | Over HTTPS only |
| `DB_*` | Wrong credentials and every page is a friendly 503 |
| `REVERB_*`, `BROADCAST_*` | Wrong host and the lobby display silently stops updating |
| `MAIL_MAILER=smtp`, `MAIL_*` | Unset and emails queue up and never send |
| `SMS_DRIVER` | `log` is the default and sends nothing |

### Common failures

| Symptom | Cause | Check |
|---|---|---|
| Every page is a "temporarily unavailable" page | MySQL unreachable | `curl -s localhost:8000/up` |
| Clients get no email or SMS | No worker running | `php artisan queue:work` |
| Lobby display frozen, booking fine | Reverb down, or circuit breaker tripped | `/admin` shows a realtime warning |
| Password-reset links all 403 | `APP_URL` does not match the host used | compare `APP_URL` to the real URL |
| `419` on any form | Session store unwritable | `DB_*` and `SESSION_DRIVER` |
| Deployment "didn't take" | Worker still running old code | `php artisan queue:restart` |
| Page renders, but no button does anything | A stale `public/hot` from `npm run dev` is still present, so Laravel points at a dev server that is not running and no JavaScript loads | `ls public/hot` — delete it, then `npm run build` |
| Full-screen error box over the staff console | A Livewire action threw. Livewire renders the response body into an overlay; with `APP_DEBUG` on, that body is the whole debug page, on a screen in a public office | Confirm `APP_DEBUG=false`, then read the exception from the log |
| Worker dies with `ProcessTimedOutException` after a few minutes | `queue:listen` was used instead of `queue:work`. `queue:listen` spawns a fresh `queue:work --once` child **per poll cycle**, each one paying a full framework boot — ~5s here, forever, even with an empty queue. The parent enforces a hard 60-second wall clock on each child and kills it, and the listener dies | Restart with `php artisan queue:work`. Do not use `queue:listen` |

### Rolling back

```bash
php artisan down --render="errors::503"
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm run build
# Migrations are not reversed automatically: if the release changed the schema,
# write and run a compensating migration rather than `migrate:rollback`, which
# would also undo every unrelated earlier migration.
php artisan queue:restart
php artisan up
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
  everything after that is skipped until the entry expires.

  Recovery is bounded by that cooldown, not by the first success. The breaker is
  checked before the transport is touched, so while it is tripped no broadcast is
  attempted at all - a success is never observed during that window to react to.
  A socket that comes back stays dark for at most 30s.

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

## Client self-service (Sprint 9)

### The profile screen was completely broken

The first thing found in Sprint 9 was not a missing feature. Both forms on
`/profile` POSTed to `PUT` routes with no `@method('PUT')` spoof. A browser `<form>`
can only send GET or POST, so **every profile submission returned 405 Method Not
Allowed** - no client could edit their name, phone, address or birthdate, and none
could change their password. Sprint 2 added the screen and Sprint 1's auth work
added the routes; nothing joined them up.

It survived because every existing profile test called `->put('/profile', ...)`
directly. That proves the controller works and says nothing about whether a person
can submit the form.

`ClientProfileFormTest` now parses the rendered page, extracts each form's *own*
hidden fields, and submits them as a browser would. Reading the page as a whole
would hand every form a `_method` it may not declare and hide the bug again - so
the parsing is per form. Verified by removing the spoof: 6 of the 7 tests fail
without it.

### Password reset

Before Sprint 9 there was no reset flow at all. A client who forgot their password
was locked out of booking, viewing and cancelling appointments permanently, because
no counter process could reset it for them.

| Route | Purpose |
|---|---|
| `GET /forgot-password` | Request a link |
| `POST /forgot-password` | Send it (throttled) |
| `GET /reset-password/{token}` | Choose a new one (**signed**) |
| `POST /reset-password` | Save it (throttled) |

The security decisions, each with a test:

- **The response never reveals whether an address is registered.** Known address,
  unknown address and deactivated account all get byte-identical wording. Saying
  "no account uses that email" would make the form a way to enumerate which
  residents hold accounts with the municipality - a privacy problem and a phishing
  aid. A deactivated account is silent rather than told it is deactivated, for the
  same reason.
- **The reset URL is signed**, so a token cannot be crafted from a partially-known
  URL; a stripped signature is rejected by middleware before any controller runs.
- **The token is single-use** and expires per `auth.passwords.users.expire`.
- **Every other session is ended** and the remember-me token rotated. The usual
  reason to reset is that someone else may have the password; leaving their
  session alive defeats the point entirely.
- **Rate limited** on both POSTs, per IP.
- Staff and administrators can reset their own password too - an administrator
  locked out of the back office is worse than a client locked out.

The whole path is verified in a real session: request a link, take the URL out of
the delivered mail, open it, submit, sign in with the new password, confirm the old
one stopped working, and confirm a replayed link is refused.

### Notification preferences

`ClientNotificationService::fanOut()` sent email and SMS on the strength of
office-wide config alone. A resident with no phone, no credit, or no interest in
texts had no way to opt out, and the office had no way to explain why people were
being texted.

`notify_email` and `notify_sms` are now per client, both defaulting to `true` so
existing accounts are unaffected. They live on their own screen and their own route
rather than inside the profile form, because an unticked checkbox is simply absent
from the payload - a client who unticked both and then changed their address would
otherwise silently get their preferences back.

Two rules are load-bearing:

- **A null preference reads as opted in, not out.** A partially applied migration
  must not silence every client.
- **In-app is never suppressible.** It is the record a client sees at the office,
  and a client who turned everything else off still has to be able to learn their
  appointment moved.

### One thing deliberately not changed

`ClientNotificationService` already recorded a *failed* SMS row, with the reason,
for a client with no number on file - a deliberate Sprint 6 decision, so staff can
see why someone cannot be texted. It is tempting to suppress that as log noise,
but it would remove the only signal that a client is unreachable, and it is not
what this preference is for. The preference is about **consent**, not reachability,
so the existing behaviour is preserved and tested alongside the new one.

## Quality evaluation (ISO/IEC 25010)

The evaluation is a command, not a paragraph of claims:

```bash
php artisan govqueue:quality-report          # human-readable
php artisan govqueue:quality-report --json   # machine-readable
```

It prints one row for each of the eight characteristics of ISO/IEC 25010 -
functional suitability, performance efficiency, compatibility, usability,
reliability, security, maintainability, portability - with **what was measured**,
the **measured value**, an assessment, and **the evidence**.

Two rules make it worth running rather than writing as prose:

- **It measures.** Timings come from real queries against the real database, counts
  from the real routing table. The performance row is warmed up first, because the
  cold figure measures PHP starting up rather than the code.
- **It admits what it cannot measure.** Where a characteristic is evidenced by a
  test rather than a number, it names the test. An evaluation that quietly invents
  a figure is worse than none, and `QualityReportCommandTest` asserts that every
  row carries a measure, a result *and* evidence.

### Measured this sprint

| Finding | Evidence |
|---|---|
| Sign-in and registration were **unthrottled** - password guessing and mass account creation | `AuthenticationRateLimitTest` |
| Catalogue issued **34 queries** where 6 suffice, growing with the service count | `CatalogueAvailabilityBatchingTest` |
| No health endpoint for deployment | `HealthEndpointTest` |

**Rate limiting.** Sign-in is limited per account (5 attempts) and per
account+address (10), because the per-account limit alone still allows one person
to spray across many addresses from a single connection, which is what credential
stuffing looks like. A successful sign-in clears the counter, so someone who
fat-fingers their password twice and then gets it right is never locked out.
Registration allows 5 accounts per address per hour, and only *successful*
registrations count. A locked-out person is told so **on the form**, not shown a
bare 429 page - otherwise the worst possible thing happens to someone locked out of
a public service.

**Catalogue query count: 34 to 6.** `bookableQuery()` took an optional service so
the catalogue could ask about every service in two grouped queries instead of two
per service. The risk that introduces is drift - a separately-built batched query
could advertise availability the booking screen refuses, the exact bug the shared
query was created to prevent - so
`CatalogueAvailabilityBatchingTest` holds the two paths against each other across
holidays, capacity, closure and the horizon.

Also: `closedDates()` is memoised per request. It was being re-queried once per
service, which was 16 holiday queries on one page. It is deliberately *not* cached
beyond the request, because an administrator can declare a holiday at any moment.

**An index that was considered and rejected.** `EXPLAIN` shows the catalogue
aggregate scanning 5,068 rows, so a composite index on `(is_open, slot_date)` was
tempting. The warm timing is 5.9ms, so the index would have added write cost to
every single booking for no measurable gain. Not added.

**`/up`, the health check.** Unauthenticated so a load balancer can use it, and
deliberately shallow: it reports whether each dependency *answers*, never what is
inside it, because the most exposed URL in the application must not leak table
names, driver credentials or framework versions. A dependency that throws is
reported as a class name, never its message. Realtime being down is a `warn`, not
a `fail` - the office can still book and serve, so that must not take the
deployment out of rotation.

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
php artisan test                          # full suite (1135 tests, ~7 min)
php artisan test --filter=BookingServiceTest
./vendor/bin/pint --test                  # code style
```

Tests run against **MySQL** (`govqueue_test`), not SQLite, because the booking
lock is only meaningful with real row-level locking.

The test database is shared, so any test that commits rows must clean up in
`tearDown` or it leaks into whatever runs next.

### Browser tests

```bash
npm run test:browser                      # 94 tests, ~11 min
```

A small Playwright suite for the defects the PHPUnit suite structurally cannot
see. Every bug it exists for was invisible to the 1135 passing tests: a submit
button left permanently disabled after a cancelled confirmation, delete dialogs
that looked missing because the second click hit a dead button, success messages
that never appeared for the same reason. All were script bugs — the endpoint
returned 200 and the markup was correct, and only a browser knows the button a
person clicks is `disabled`.

It is deliberately bounded: no journey coverage, and no second copy of the
business logic. Anything assertable without a browser is asserted in PHP, where
it is faster and far more thorough.

First run only, the environment has to exist:

```bash
npm run browser:setup                                  # writes .env.browser
mysql -e "CREATE DATABASE govqueue_browser"            # check DB_* above matches
npm run test:browser
```

`npm run browser:setup` is a script rather than a copy-paste line because none
of these values are defaults. An earlier version of this section said
`cp .env.example .env.browser` and "set APP_KEY and DB_DATABASE" — which leaves
eight further keys wrong, two of them silently: `APP_ENV` left as `local` means
the browser fixtures are never seeded, and `CACHE_STORE` left as `database`
makes the sign-in throttle count persist between runs until the suite locks
itself out. Neither explains itself when it fails.

`.env.browser` is gitignored because it holds an `APP_KEY`.

The suite runs against **built assets**, not a Vite dev server:
`npm run serve:browser` deletes `public/hot` first, because a stale one makes
Laravel point at a dev server that is not running, and the tests would silently
exercise an application with no JavaScript at all.

Serial by necessity — the tests share one seeded database and queue, so parallel
workers would have them issuing walk-ins from under each other.

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
php artisan govqueue:quality-report           # ISO/IEC 25010 evaluation, measured
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