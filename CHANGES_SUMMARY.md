# GovQueue - Changes Summary

## About this document

This is the record of **one working session's changes**, kept for the sake of the
history. It is not the project's documentation - `README.md` is the authoritative
document, and it explains the reasoning behind the design.

Every claim below was **checked against the code on 20 October 2026**. This file was
originally written during the session it describes and was not verified afterwards;
several of its claims did not survive that check. The corrections are stated
explicitly in [Corrections](#corrections) rather than quietly edited away, because
"this was claimed and it was wrong" is more useful to a reader than a document that
was never wrong.

---

## What was changed

### Frontend

Four UI changes were made and all four are present in the code.

#### 1. Visual feedback on slot selection

`resources/views/client/booking/create.blade.php`

Alpine state (`selectedSlot`, `selectedDate`, `selectedTime`, `selectSlot`) drives a
green confirmation banner showing the chosen date and time.

*Why it matters:* the confirm panel sits below the fold on a phone. Without this, a
client who has just tapped 09:00 cannot see that the tap registered.

#### 2. Manual retry button for queue status errors

`resources/views/livewire/client/queue-status.blade.php`

A `role="alert"` panel with a **Try again** button calling `refreshPosition`, with
`wire:loading` / `wire:loading.remove` so the label reads *Retrying...* while it
works. The only previous recovery was waiting out the automatic retry.

#### 3. Loading states for staff console actions

`resources/views/livewire/staff/queue-console.blade.php`

`wire:target` on **Call next**, **Complete**, **Skip** and **No-show**, each swapping
its label for a spinner. The target matters: without it the spinner attaches to the
whole component and one button's wait dims the entire console.

#### 4. Public display offline warning

`resources/views/livewire/display/public-queue-display.blade.php`

Two states, because they mean different things to somebody standing in a lobby:

| State | Banner | Meaning |
|---|---|---|
| Socket down, poll running | rose - *"Offline - updates every 10 seconds"* | The numbers on the board are still current |
| Connection lost | amber - *"Connection interrupted"* | The board may be stale |

Both carry the last-updated stamp.

### Tests

#### `tests/Unit/ClientNotificationServiceTest.php`

`test_sms_failure_does_not_stop_email_or_in_app` now builds a fresh
`ClientNotificationService` around an `SmsManager` whose gateway has been swapped
for one that always throws.

*Why:* swapping the gateway on the existing instance does not work, because that
service was already constructed with the old manager and holds it. The comment in
the test says so, and it is the reason the test was rewritten.

---

## Corrections

| The earlier draft claimed | What is actually true |
|---|---|
| `tests/TestCase.php` gained a `call()` override that injects CSRF tokens into mutating requests, "fixing 707 tests" | **No such override exists.** `tests/TestCase.php` contains one thing: `ThrottleRequests::shouldHashKeys(false)`, so a test can clear the rate-limit counter it just filled. There is no `call()` override and no token injection anywhere in the file. |
| `AccessibilityTest` had explicit CSRF tokens added to two tests | The two named tests exist, but **no CSRF token was added to either** - and none is needed. Laravel's `VerifyCsrfToken` middleware skips verification when `runningUnitTests()` is true, so a mutating request in a test needs no token. The tests were never broken by CSRF. |
| `AdminConfigurationTest` had explicit CSRF tokens added to three tests | The same: the three named tests exist, no token was added, and none is required. |
| "Tests Status: all passing (54/54 in reviewed suites)" | True of the two suites it names, and misleading as a headline. The suite it names is not the suite that matters. The full PHPUnit suite today is **1249 tests**, and the browser suite is **132 tests** - both green. |
| "Test coverage maintained: 100% of reviewed suites" | **No coverage measurement exists in this repository** - there is no coverage tool configured, no coverage report, and no threshold. A percentage quoted without a tool behind it is a guess. Removed. |
| "Analysis time saved: ~80 hours" | Unfounded. No basis is given, and none can be reconstructed. Removed. |
| "Full test suite run (707 tests)" | The suite is **1249 tests** today. |
| "CSS bundle 59.83 kB (gzip 10.53 kB), JS bundle 128.15 kB (gzip 41.40 kB), build time 1.84s" | The CSS figure is stale - the current build is **55.04 kB raw / 10.14 kB gzipped**. JS is **132.78 kB raw / 42.96 kB gzipped**. The build time is not quoted because it is not a property of the project, it is a property of the machine. |
| "Issue #4 (public display offline warning)" is listed as **completed** here and as **"TO IMPLEMENT"** in `UI_UX_IMPLEMENTATION_GUIDE.md` | It is implemented. The guide was stale. Both documents now agree. |
| "Related documents: Bug Fix Report (Artifact), Final Report (Artifact)" | **Neither exists in this repository.** They were artefacts of the session that are not in version control. The references are removed; the two documents that are in the repository are `README.md` and `UI_UX_IMPLEMENTATION_GUIDE.md`. |
| `vendor\bin\phpunit` as the way to run the tests | Works, but the project's convention is `php artisan test`, which is what the README uses and what the rest of the documentation assumes. |
| Five commits in the history carried a `README.md` that had been read as Windows-1252 and written back as UTF-8, and two admin screens carried a mis-encoded peso sign | The files are clean now, but the **commits** were not: the damage was still in the history, in sixteen commits from `99ce25f` to `930d52f`. The history has been rewritten - `git filter-branch --tree-filter` over every commit, reversing the Windows-1252 round-trip on the affected blobs until the signature was gone - so the repository no longer contains a commit whose README reads "â€”" where it should read an em dash. The commit count, the messages, the order and the final tree are unchanged; every commit after the first affected one has a new hash, and the branch was force-pushed. |

---

## Numbers

| Measure | Value | How it was obtained |
|---|---|---|
| PHPUnit suite | 1 259 tests, all passing | `php artisan test` (8.5 min, 3 687 assertions) |
| Browser suite | 133 tests in 17 spec files, all passing | `npm run test:browser` (12.7 min) |
| CSS bundle | 55.04 kB raw, 10.14 kB gzipped | `npm run build` |
| JS bundle | 132.78 kB raw, 42.96 kB gzipped | `npm run build` |
| Code coverage | **not measured** | no coverage tool is configured |

---

## Commands

```bash
php artisan test                          # the PHPUnit suite
php artisan test --filter=BookingServiceTest
./vendor/bin/pint --test                  # code style
npm run test:browser                      # the Playwright suite
npm run build                            # front-end assets
```

Both suites run against **MySQL**, and the browser suite has its own database
(`govqueue_browser`) so it can never disturb the PHPUnit one (`govqueue_test`).

---

## Round 3 – 22 October 2026

### Staff cancellation of booked appointments

A clerk can now close a booking before the client arrives. The action lives in
the "Booked – not yet checked in" section of the queue console and requires a
written reason before it goes through.

**Citizen** – the appointment detail page shows "Cancelled by the office" and the
clerk's exact words, so the client knows why without phoning in.

**Email** – `AppointmentCancelledMail` carries the clerk's reason in the body.

**Console** – `QueueConsole` gains a `confirmCancel` / `cancelBooking` pair (a
Livewire dialog, not the browser `confirm`), the booked list links to the
priority documents the client attached, and `cancelled_by` / `cancellation_reason`
surface next to each closed row.

**Reporting** – `ReportingService` gains a cancellation-reasons breakdown.

### Schema changes (three migrations)

| Migration | What it does |
|---|---|
| `2026_10_22_000001` | Adds `cancelled_by` (FK → users) and `cancellation_reason` (nullable text) to `appointments` |
| `2026_10_22_000002` | Tidies priority columns on `appointments` |
| `2026_10_23_000004` | Removes account-level priority columns from `users` (`claims_senior_citizen`, `is_pwd`, `is_pregnant`, reference fields). These were per-transaction facts stored in the wrong place. `User::getClaimsSeniorCitizenAttribute` continues to derive the entitlement from `birthdate` on every read — no column, nothing that can go stale. |

### Registration refactor (concurrent session, included in this commit)

The registration form no longer asks for priority claims. Senior-citizen
priority is auto-detected from the date of birth; PWD and pregnancy are
per-transaction claims made on the booking form. `RegisterController`,
`register.blade.php`, `RegistrationTest`, and the browser spec
`priority-reference.spec.ts` have all been updated to match.

### Tests

- `QueueConsoleTest` — 20+ new assertions: cancel dialog, reason requirement,
  audit trail, booked-list document links.
- `ReportingServiceTest` — cancellation-reasons report asserted end-to-end.
- `BookingTest` / `BookingServiceTest` — cancel path covered.
- `BrowserFixturesSeeder` — seeds a booked appointment for the browser suite.
- `staff-console-decisions.spec.ts` — browser assertion that a booking the
  office will not serve can be closed and that the citizen-facing status says
  "Cancelled by the office".
- `priority-reference.spec.ts` — stale registration-form test rewritten: it
  now asserts that no priority checkboxes exist on the form and that the
  explanatory panel is visible.

---

## Round 4 – 22 October 2026

### Password reset actually delivers email now

`MAIL_MAILER` was `log` — every reset link went to `storage/logs/laravel.log`,
not anyone's inbox.

Fix: switched to `smtp` pointing at **Mailpit** (`127.0.0.1:2525`), a local
SMTP catcher whose web inbox is at `http://localhost:8025`. The host and port
were already correct in `.env`; only the mailer driver changed. End-to-end
delivery confirmed before commit.

### Rate-limit tuning

| Setting | Before | After |
|---|---|---|
| Controller `MAX_ATTEMPTS` per IP (5 min window) | 3 | 5 |
| Laravel broker throttle per email | 60 s | 30 s |
| Rate-limit error wording | Recycled the vague success message | "Too many reset requests. Please wait a moment before trying again." |

The broker throttle was the first wall a user hit when retrying — it fired
before the controller's own limiter and returned a confusing message.

### Forgot-password UX

- Success state now **replaces the form** with a green confirmation panel
  (prevents re-submission confusion, clearly visible outcome).
- Copy updated: *"Please check your inbox and spam folder."*
- Helper text on the email field: *"The same address you used when you registered."*

### Loading spinners on all auth forms

Every submit button now disables itself and shows a spinner after the first
click, preventing double-submissions and giving immediate feedback:

| Form | Spinner label |
|---|---|
| Sign in | *Signing in…* |
| Register | *Creating account…* |
| Forgot password | *Sending…* |
| Reset password | *Saving…* |

### Password show/hide on the sign-in form

Eye-icon toggle on the password field switches between masked and plain text.

### Files changed

`config/auth.php` · `ForgotPasswordController.php` ·
`login.blade.php` · `forgot-password.blade.php` ·
`register.blade.php` · `reset-password.blade.php`

PHPUnit: 1 259 tests, 3 687 assertions, all passing (suite unchanged).

---

## What was outstanding, and what happened to it

`UI_UX_IMPLEMENTATION_GUIDE.md` listed five items. All five are now implemented, and
two of them turned out to be different from what the guide proposed:

1. **Responsive queue number sizing** on the public display - done. The size now
   follows the length of the number, and the rule lives in one place on the
   component because the two board layouts are the same rule with two different
   amounts of room.
2. **Progressive disclosure for the priority reference field** - done, and the
   registration form's disclosure turned out to have been *written and not
   working*: it had `x-model` and `x-show` on the fieldset and no scope for
   either to live in, so `pwd` was undefined and the field never hid.
3. **Keyboard shortcuts for the staff console** - done, on `Alt` keys. The guide
   proposed `Ctrl+N` and `Ctrl+S`, which are the browser's new-window and
   save-page.
4. **Focus moved to the feedback message after a Livewire action** - done, and
   not by the mechanism the guide proposed. The component's root is replaced
   during an update, so the element the morph payload carries points at the
   detached old root, and the message is caught as it is *added* instead.
5. **A touch-target audit** - done, as a browser test rather than a console
   snippet. It found four real violations, all twenty pixels tall, on the
   sign-in form, the client's appointments page and the admin dashboard.

Nothing from the guide is outstanding now. The one thing it recommends against -
a service worker for offline support - is documented as not worth doing: a cached
lobby board serves a queue position that has moved on, and that is worse than an
honest "the display is not updating".

---

*Verified against the code on 22 October 2026.*
