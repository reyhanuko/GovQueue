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
| S4 | Queue lifecycle, wait-time estimation, priority verification | Next |
| S5 | Real-time queue tracking and digital display | Planned |
| S6 | Automated client notifications | Planned |
| S7 | Staff console | Planned |
| S8 | Admin configuration | Planned |
| S9 | Analytics and reporting dashboard | Planned |
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

## Testing

```bash
php artisan test                          # full suite (164 tests)
php artisan test --filter=BookingServiceTest
./vendor/bin/pint --test                  # code style
```

Tests run against **MySQL** (`govqueue_test`), not SQLite, because the booking
lock is only meaningful with real row-level locking.

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
php artisan queue:work            # queued mail/jobs
php artisan schedule:work         # appointment reminders
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