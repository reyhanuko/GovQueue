# GovQueue: Full-System Audit and Master Fix Prompt

**Source:** 156 screenshots (1 corrupt 122-byte file skipped), captured 10-10, 10-12, and 10-19 across Admin, Staff, and Client.
**What this file is:** Part A tells you what was and wasn't covered. Part B is the prompt to paste into your coding model (Kiro, Claude Code, Cursor, etc.). The findings are embedded in the prompt so the model has one source of truth.

## Part A: Coverage and limits (read before pasting)

**Seen:** public home, services list and detail, queue display, admin (dashboard, daily slots, services, slot templates, windows, holidays, staff accounts, reports, audit trail, notification log), staff console (check-in, walk-in, priority verification, call, complete), client (booking, appointment detail, cancel, move, profile, messages).

**Not seen (the prompt makes the model check these in code instead of assuming):** registration, forgot/reset password, forced password change, 404/403 pages, mobile layout, the "affected clients" list after a holiday closure, skip / no-show flows, queue display with several windows, what a staff user gets when opening an admin URL, and what a client gets when opening another client's appointment URL.

**Important:** these are findings from screenshots, so the "likely cause" lines are hypotheses. The prompt forces the model to confirm each one in code before changing anything.

---

## Part B: The master prompt (copy everything inside the block)

````
ROLE
You are a senior full-stack engineer and QA auditor working on an existing, running project called GovQueue: an online appointment and queue management system for the Local Government Unit of Tupi, South Cotabato (school capstone project). You are fixing and tightening it, not rebuilding it.

NON-NEGOTIABLE ANTI-HALLUCINATION RULES
1. Never assume the stack, file names, routes, models, columns, config keys, or package APIs. Discover them by reading the repository. Everything you state about the code must cite a real file path and line range you actually opened.
2. Label every claim with one tag: [CONFIRMED] (you saw it in code or ran it), [NOT-REPRODUCED] (the reported symptom does not exist in the code), or [UNKNOWN] (cannot determine from the repo). Never convert [UNKNOWN] into a guess. Ask me instead.
3. The "likely cause" lines below are hypotheses from screenshots. Treat them as unverified until you confirm them in code.
4. Do not invent libraries, framework methods, or config options. If you are unsure an API exists in the installed framework version, open the vendor source or the installed docs for that exact version and cite it. Check composer.lock / package.json for actual versions.
5. Do not add new dependencies, rename routes or URL parameters, or change database columns outside the finding you are fixing. If a fix needs one, stop and ask me first.
6. When a finding depends on a business rule that is not decided (listed in section DECISIONS), do NOT pick a behavior yourself. Complete every fix that does not depend on it, then ask me the decision questions in one batch.
7. If the instructions and the code disagree, say so explicitly and ask. Do not silently pick one.
8. Never claim a fix works unless you ran the relevant test or reproduced the flow. If you could not run it, say "NOT VERIFIED" and explain why.
9. Scope control: touch only what a finding requires. List every file you changed and why.

WORKFLOW (follow in order, do not skip)
PHASE 0: RECON (read-only, no edits)
- Detect the stack: framework and version, PHP version, DB engine, frontend approach (server-rendered templates vs SPA), auth method, queue/mail/scheduler config, test setup. Print what you found with file paths.
- Dump the full route table (for Laravel: run `php artisan route:list --json` or the equivalent for the detected framework). Build a table: method | URI | name | controller@action | middleware | policy/gate used.
- List models, migrations, enums/status constants, scheduled commands, queued jobs, notifications, policies, form requests, and existing tests.
- Output a one-page "System Map" and a list of anything in this prompt that does not match the repo.
- STOP and show me the System Map before Phase 1 only if it contradicts this prompt materially. Otherwise continue.

PHASE 1: VERIFY EVERY FINDING
For each finding ID below, reproduce it (test, tinker, or tracing the code path) and report [CONFIRMED] / [NOT-REPRODUCED] / [UNKNOWN] with file:line evidence and the real root cause. Write the results as a table before changing any code.

PHASE 2: ACCESS-CONTROL AND ROUTE AUDIT
Using the route table, produce a matrix of every route against four actors: guest, client, staff, admin. For each cell give: expected allowed/denied, actual (from middleware/policy), and a failing or passing automated test. Specifically verify:
- A client cannot open, move, or cancel another client's appointment by changing the numeric ID in the URL (/my-appointments/{id}, /move).
- Staff cannot open any /admin/* URL; clients cannot open /staff or /admin/*; guests are redirected to login and sent back after login.
- Staff can only operate their assigned window, including by editing the URL or query string (/staff?date=...), and cannot act on other windows' tickets through direct POST/PUT requests.
- Admin-only actions are enforced server-side, not just hidden in the UI.
- Identifier consistency: some routes use codes (services, windows) and some use numeric IDs (staff, appointments). Flag guessable-ID exposure; do not rename routes without asking.
- What each role sees in the top nav, the sidebar, and the home page, and whether any link leads to a screen the role cannot use.

PHASE 3: DECISIONS
Ask me every item in DECISIONS that blocks work, in one numbered batch, each with a recommended default and what changes if I pick otherwise. Then wait.

PHASE 4: FIX
Work in priority order (P0, P1, P2). For each finding: minimal change, a test that fails before and passes after, and a one-line before/after behavior note. Commit per finding or per tight group if git is available. Run the whole test suite after each priority tier.

PHASE 5: REPORT
Final output: (a) table of all findings with status FIXED / NOT-REPRODUCED / BLOCKED-ON-DECISION / DEFERRED, (b) files changed, (c) tests added, (d) anything NOT VERIFIED, (e) remaining risks, (f) migration or deploy steps (for example `php artisan migrate`, queue worker, scheduler cron).

========================================
FINDINGS (from screenshots; all unverified until Phase 1)
Format: ID | Severity | Side | Evidence (screenshot time) | Symptom | Hypothesis | Required outcome
========================================

--- P0: BROKEN OR EXPOSED ---

F-01 | P0 | BE+FE | client 10-19 234332 and 234421
Symptom: On "Move to another slot", submitting "Move my appointment" returns a 405 "Method Not Allowed" page: "POST method is not supported for route my-appointments/235/move. Supported methods: GET, HEAD, PUT."
Hypothesis: the form posts without method spoofing, or the route is declared PUT while the form sends POST.
Required: moving an appointment works end to end: new slot booked, old slot released, new queue number issued, client notified, audit entry written, race-safe against two clients taking the last place. Add a feature test for success, full slot, closed slot, holiday, past slot, someone else's appointment, and an already-checked-in appointment.

F-02 | P0 | BE (config) | client 10-19 234332
Symptom: A client sees the full debug exception page: stack trace, framework version (Laravel 12.69.3), PHP version (8.2.12), and file paths (e.g., app/Http/Middleware/SecurityHeaders.php).
Hypothesis: debug mode is on in the environment used for testing.
Required: confirm the debug flag is off for any non-local environment and document it; add friendly error pages (403, 404, 405, 419, 500) in the same visual style that show a short message, a reference code, and a link home. Never show a stack trace, SQL, or class names to any role. Verify the notification log (F-04) also never prints raw SQL or JSON payloads to admins.

F-03 | P0 | BE+FE | client 10-19 234105, 234118; staff 10-12 195210, 195223; walk-in 10-12 195155
Symptom: (a) A client ticked "Person with disability" AND "Pregnant client" with reference "PWD123, PREG123"; the saved appointment says "You claimed: Regular" while keeping the reference. The claimed categories were lost. (b) "Regular" is presented as if it were a priority claim ("Priority claimed: Regular", "Priority claim recorded (Regular)", "Hello World claims Regular"). (c) The staff verification panel for a "Regular" claim lets staff press "Verify and prioritise" and the ticket becomes "Verified: Senior Citizen", a category nobody claimed. (d) The walk-in ticket for someone who claimed nothing shows a "Verify claim" badge. (e) One reference field serves two different claims. (f) "born not recorded" appears in the verification panel.
Hypothesis: the claim is stored as a single enum/string that is overwritten by a default, and verification does not constrain the result to the claimed category.
Required: a clear data model where "no claim" is the absence of a claim (never a displayed "Regular claim"); each claimed category is stored, shown, and verified separately with its own reference; staff may verify only a claimed category, or must explicitly pick a different one with a mandatory reason that is audited; a reference/note is required when verifying or refusing; "Verify claim" appears only when a claim exists; labels and messages are rewritten accordingly. Add tests for each combination (none, senior, PWD, pregnant, PWD+pregnant, senior+PWD).

F-04 | P0 | BE | admin 10-12 192842, 192851, 192912; client profile 10-19 235531
Symptom: Notification log shows SENT 0, QUEUED 10, FAILED 20. Failed rows display raw SQL: "Base table or view not found: 1146 Table 'govqueue.notifications' doesn't exist" with the full insert statement and JSON payload. SMS rows fail with "SMS is disabled in configuration (GOVQUEUE_SMS_ENABLED)". Emails sit in "Queued" forever. Walk-in tickets queue emails to addresses ending in ".invalid". Phone "123123123123213" is accepted. Yet client Profile offers "Text me", says "Email and SMS notifications are on", and promises reminders. Later (10-19) the client's in-app Messages and bell do work, so the in-app table may have been fixed since.
Hypothesis: a missing migration at the time (database notifications table), no queue worker or mail transport configured, SMS feature flag off while the UI still offers it, no validation of destination.
Required: (1) verify migrations are complete on a clean database; (2) make email actually send (or clearly run synchronously) in the documented setup, with a documented worker/scheduler command; (3) hide or disable the SMS option and promise text everywhere when SMS is not enabled, or implement a provider behind the flag; (4) never create email jobs for placeholder addresses; (5) validate PH mobile numbers (09XXXXXXXXX or +639XXXXXXXXX) on registration, profile, staff form, and walk-in; (6) the admin log shows a short human reason (e.g., "SMS is turned off", "Email address invalid"), never SQL or payload; keep technical detail in the application log; (7) add a "retry failed" action and a filter that does not show the wrong empty-state text (filtering type=confirmed currently says "No notifications have been attempted yet" when the real answer is "none match this filter"); (8) verify a confirmation notification is actually recorded on every successful booking, since the log never showed one.

F-05 | P0 | BE+FE | client 10-19 233604, 234719, 234758, 234809
Symptom: An appointment for Oct 12 is still "Checked in", "You are next", and listed under "Upcoming & active" seven days later. The client can still cancel a checked-in appointment. A "Your number has been called" message is one week old.
Hypothesis: there is no end-of-day close-out; unattended or unserved tickets stay open forever; the cancel rule ignores status and date.
Required: define the appointment state machine (booked, confirmed, checked_in, called, serving, completed, skipped, no_show, cancelled, expired) and enforce allowed transitions server-side; add a scheduled end-of-day command that closes stale tickets with a clear terminal status and notifies where appropriate; "Upcoming & active" shows only today-or-future and genuinely active items; a client may not cancel or move once checked in or after the slot start (subject to decision D6); past-date appointments are never "active". Tests for every transition and for the scheduled job.

F-06 | P0 | BE+FE | admin 10-10 223313, 223329, 223350, 224147, 224235; staff list 10-12 191745 to 191940; window list 10-12 191102
Symptom: Capacity is set per service per slot ("4 places" each), but staff serve per window. Window 1 has one staff member and three services, so each 30-minute time can hold up to 12 clients against roughly 3. The oversubscription warning is computed per service, so lowering one service to 3 makes its warning disappear while the window is still oversubscribed. The daily slot page reports 520 places for one day. Deleting the only staff member of Window 2 (Jomar) leaves Window 2 active and bookable with zero active staff. Window "Avg. service" (e.g., 5 min on Window 1, edited from 10) conflicts with each service's own duration (10 to 15 min).
Hypothesis: the capacity model has no window-level budget and the warning logic looks at one template at a time; window average service time duplicates service duration.
Required: BLOCKED-ON-DECISION D1, D2, D3 for the model; but do now: (a) compute the warning at window level (sum of all open places across the window's services in the same time range versus staff x floor(slot_minutes / service_minutes)); (b) show that aggregate on Daily slots and Slot templates; (c) warn clearly when a window has zero active staff; (d) choose one source of truth for service time and use it for wait estimates (decision D3b); (e) block or require confirmation when deleting or deactivating the last active staff of a window that has future bookings.

F-07 | P0 | FE+BE | client 10-19 233753 and 233708; admin reports 10-12 192046
Symptom: A test service "Sample" priced at 10,000 pesos is visible to clients with the message "Fully booked for now. New slots are added daily. Walk-ins are still accepted at the office," although it has no slot templates at all (not fully booked, simply unscheduled). "Sample" and "Hello World" test data also show in reports.
Required: a service with no slot templates or no future slots must show "No schedule yet" (not "Fully booked") and must not be bookable; the admin service list must flag "No schedule" visibly; "Fully booked" only when slots exist and all are at capacity; "Walk-ins are still accepted" appears only if the office actually accepts walk-ins for that service. Provide a seed/cleanup path (documented command) so demo data never ships; add a warning on creating a service with fee above a sensible configurable cap (10,000 was accepted without question).

--- P1: LOGIC INCONSISTENCIES ---

F-08 | P1 | BE+FE | admin 10-10 225014 to 225452 (slot templates), 10-10 223416, 10-12 191331
Symptom: (a) Two identical Monday 8:00 to 12:00 templates were created for the same service with no complaint (duplicates, overlapping dates). (b) After adding or editing a template the yellow banner shows "Heads up" followed only by "1" (broken message rendering, probably an array/count printed instead of text). (c) Removing a template says "Existing slots already generated are left alone", so removed templates leave bookable slots. (d) Changes need a manual "Rebuild slots" on the dashboard; nothing tells you slots are out of date. (e) Saturday templates exist while Sunday is inactive, and today (Saturday Oct 10, also declared a holiday) still shows slots ("187 places", "Finished, read only") while Sunday has none. (f) Existing templates have odd end dates (9 Oct 2027). (g) The "This window cannot serve that many clients" warning is visible before any value is entered. (h) Slot rows on Daily slots are not in a stable order and a single "Remove" confirm says only "8:00 AM on 12 October" without the service name, although three services share that time.
Required: validate overlap and duplicates server-side with a clear message; fix the "Heads up" renderer (list the actual warnings); on template removal, offer to remove its future unbooked slots and list future booked ones; show a "slots out of date, rebuild" indicator or rebuild automatically and idempotently; resolve weekend/holiday behavior per D3; show the warning only when it applies; stable ordering (time, then service name) and the service name in every confirmation.

F-09 | P1 | BE+FE | admin 10-12 191322 to 191535 and 192608 (audit)
Symptom: Closures can be declared for past dates ("Weird Past Holiday?" on 1 Jan 2025 was accepted); national holidays could be seeded for 2020; the page lists 2025 to 2027 in one long page; messages mix date formats ("2020-01-01" vs "1 January 2020"); the placeholder says "Foundational Day". Declaring a closure for Mon Oct 12 did not stop the office queue, bookings, and walk-ins that same day. The page promises affected clients "are listed below", which was not shown in the screenshots.
Required: reject past dates and absurd years (configurable window); consistent date format everywhere; group closures by year with past ones collapsed; verify and test that a closure hides slots and blocks new bookings, and define what happens to existing bookings (decision D8); verify the "affected clients" list exists and that clients are notified; confirm walk-in behavior on a closure day (decision D3).

F-10 | P1 | FE+BE | admin 10-10 224655 vs 224644; 10-12 190927 vs 190936
Symptom: Delete confirmations promise things that then do not happen. Services: "Barangay Clearance and its slot templates will be removed. Bookings already made are kept." followed by an error that the service cannot be deleted because 34 appointments reference it. Windows: "Window 1 and its services will be removed" followed by "Cannot delete... 4 services still assigned". The window edit form allows changing the code that appears in public URLs and on the lobby display without the warning the service form has.
Required: pre-check deletability and, if blocked, disable Delete and show the reason beside it ("34 appointments, deactivate instead") instead of a misleading confirm; when allowed, the confirm text must state exactly what will happen. Add the same "changing the code breaks shared links" warning to windows or lock the code once used.

F-11 | P1 | BE | admin 10-10 223633 (windows), 224428 (services)
Symptom: The Services table column "Bookings" shows 34, 27, etc. while the dashboard says 0 upcoming bookings; the meaning (all-time? completed?) is unclear. "Templates" counts differ (10, 11, 12) without explanation.
Required: rename and define the columns ("All-time appointments", "Upcoming"); add upcoming counts.

F-12 | P1 | BE+FE | client profile 10-19 235242, 235308
Symptom: The profile says date of birth "Drives your RA 9994 senior priority automatically" and the booking form disables the senior checkbox from the stored DOB, while the home page promises "Claims are checked, not assumed" and staff must verify. DOB is freely editable by the client at any time, so anyone can self-qualify. Barangay and Address are separate free-text fields holding the same value ("Poblacion").
Required: single consistent priority policy (decision D5); log every DOB change in the audit trail and require staff to verify the ID at the counter regardless; consider locking DOB after first save (admin/staff correction only); barangay should be a dropdown of Tupi barangays (verify the list with me, do not invent it) and address a separate street/purok field.

F-13 | P1 | BE+FE | staff 10-12 193028 to 195303; client 10-12/10-19; admin 10-12
Symptom: (a) Queue numbers are issued at booking ("A-001 Numbered for Oct 20 at Window 2") and Window 1 and Window 2 both use the "A-" prefix, so a lobby display cannot tell them apart; walk-ins use "W-". (b) It is unclear whether call order follows booking number, check-in time, or priority; the staff list reorders when a claim is verified. (c) A client can have an appointment at 8:00 PM and 7:46 PM to 7:56 PM, outside the 8-to-12 and 1-to-5 templates (probably ad-hoc slots from walk-ins/check-ins, but unexplained). (d) Staff can check a client in at any time ("In at 7:50 PM" for an 8:00 PM booking is fine, but nothing stops early/late/other-day check-in). (e) "Mark no-show" is offered on a client currently being served. (f) "Call next client" is enabled with an empty queue. (g) The queue-date picker lets staff browse any past date and the walk-in form even appears while viewing 10/01/2026.
Required: decisions D4 and D7 first. Do now: window-specific ticket prefixes; the call order rule written down in code comments, UI help text, and tests; disable "Call next" when nothing is waiting; "Mark no-show" only before service starts; hide Walk-in on past dates; no ad-hoc slot creation without an explicit rule.

F-14 | P1 | BE | admin reports 10-12 192028 to 192109
Symptom: Numbers do not reconcile: Appointments 216, Bookings made 176, decided 208, Completed 181, No-show 27, Skipped 4, Cancelled 0 (and in the 7-day view 40 appointments vs 27+5+4=36 accounted). "Cancelled 0" while the client history has cancelled items and "Why appointments were cancelled" is empty because no reason was recorded. "Fees collected" shows 35,000 pesos for Window 2, but the system takes no payment (staff type an amount at completion). Utilisation is about 2% across the board. Reports include the "Sample" service.
Required: define each metric in a help tooltip; make the totals reconcile (show the remainder as "Still open/unresolved" so it adds up); rename to "Fees recorded"; ensure cancellations with optional reasons are counted; exclude inactive/test services or label them; make PDF/CSV carry the same definitions.

F-15 | P1 | FE+BE | public 10-10 222657; 10-12 194615 to 194710
Symptom: The queue display page shows a single window ("Window 1 - Certificates") and no way to see the others; the closed-office message is shown for the whole display.
Required: lobby display shows every active window's now-serving and next-in-line at once, in large type with high contrast, auto-refresh with a visible "last updated", and a clear closed/holiday state; optional per-window view by query parameter. No personal data on it (numbers only), which is correct today; keep it that way.

F-16 | P1 | FE+BE | various
Symptom: (a) Admin and staff land on the same marketing home page ("Book your appointment. Skip the long queue.") and see "Client accounts only" buttons on service pages; an admin viewing a service sees "Sign in as a client to reserve one of these slots" while already signed in as admin. (b) Staff and admin appear to have no profile or change-password page in the top nav (only clients have Profile). (c) Staff and admin "Messages" says "Alerts about the queues and windows you are responsible for" and was empty because nothing produces alerts. (d) There is no admin screen to manage client accounts (search, view, deactivate, reset password, see no-show history); "Staff accounts" covers staff and admins only, though the header copy says clients manage their own details.
Required: role-aware home (staff go to the console, admin to the dashboard, clients to My Appointments); remove client-only actions from staff/admin views or replace with role-appropriate links; add account/password pages for staff and admin; either implement meaningful staff alerts (e.g., window has zero staff, queue over threshold, failed notifications) or hide the Messages entry; decision D11 on client-account management.

F-17 | P1 | BE+FE | admin audit 10-12 192551 to 192724
Symptom: Record type filter shows class names (ServiceWindow, SlotTemplate, TimeSlot); action filter shows raw keys with counts; deleted records appear as "User #3" or "Holiday #42" with no name; window.updated rows show "Before: Sample, SAMPLE, 15, 1 / After: Sample, SAMPLE, 15, 1" (identical, unlabeled, no visible change); raw before/after values are unlabeled lists; login/logout events flood the list; "Nothing recorded" appears in details. Dashboard "Recent changes" shows the same raw keys.
Required: human labels (e.g., "Staff account created"), snapshot the display name at write time so deleted records stay readable, show a labeled field-level diff (only fields that changed), hide or collapse auth noise by default with a toggle, add a record-type and actor filter, paginate, and record failed sign-ins. Keep "raw payload" as an expandable developer detail.

F-18 | P1 | BE | admin staff form 10-12 191831; client 10-19 235823, 235843, 235631 to 235720
Symptom: Phone "0922ayawpagtuo" accepted for a staff account; the client changed their password to the literal word "password" and it was accepted (only 8 characters are required); an admin-set password is a shared secret and "Must change password at next sign-in" is unchecked by default; service description saved with trailing dots ("Tupi......"); password-change errors show at the page top while the failing field is far below, and no email/notification confirms a password change.
Required: server-side validation for phone, password strength (length plus a common-password denylist; do not over-engineer), default the force-change option on for admin-created or admin-reset accounts, field-level errors with scroll/focus to the first error, and a notification on password change. Do not invent a password-policy library; use what is installed or ask.

F-19 | P1 | FE+BE | client booking 10-19 233844 to 234026
Symptom: A validation failure clears the chosen time slot and the ticked priority boxes; the message shown is the raw "The time slot id field is required." instead of "Please choose a time"; the error banner is at the top while the broken field is far below; there is no date picker (30 days of slots in one long scroll); the "What to bring" checklist from the service page is not repeated on the confirm step; there is no stated cancel/move cutoff; whether a client can double-book the same service or hold many open bookings is unknown.
Required: preserve all input on validation failure; plain-language field messages; inline errors with scroll-to; a date strip or calendar that shows days with availability and keeps the selected slot highlighted and summarized in the confirm panel; show requirements and cancellation rules before confirming; decision D6 for limits. Test the double-submit and last-slot race.

F-20 | P1 | BE | unknown
Symptom: The system stores date of birth, PWD and pregnancy claims, and reference numbers, which are sensitive personal data under the Philippine Data Privacy Act (RA 10173). No consent notice, retention rule, export, or deletion path was visible; the registration page was not captured.
Required: inspect registration and profile in code; if missing, add a short privacy notice and consent checkbox at registration, minimize what is shown to staff, and restrict who can read claim references. Report as [UNKNOWN] where you cannot tell, and ask me (decision D12).

--- P2: POLISH AND CLARITY ---

F-21 | P2 | FE | admin sidebar, dashboard (first screenshot)
- Sidebar shows two stacked group labels at the top ("ADMINISTRATION" over "EVERYDAY"); only the active item shows an inline description ("Is the office open, and what is the queue doing?"), which wraps awkwardly and makes items inconsistent; no icons; group names are cute rather than clear ("Everyday", "Who works here", "Checking the work"); labels are small and low contrast; active state is faint; no divider between sidebar and content; page title says "Administration" while the nav item says "Dashboard".
- Dashboard: "Rebuild slots" is the most prominent button with no explanation or confirmation; live metrics (waiting, serving) and setup counts (windows, services, staff, holidays) have equal weight; "Recent changes" shows raw keys; service-window table rows have no actions; the zero state is bare.
Required: one label per group, consistent items with icons, plain group names, stronger active state, matching page title, confirm dialog on rebuild with what it will do and an "affects X slots, keeps existing bookings" summary, clearer grouping of live vs setup metrics, readable event sentences, clickable window rows, zero-state hints. Check color contrast (WCAG AA) for gray secondary text and green status pills. Keep the existing visual language and components.

F-22 | P2 | FE | everywhere
Symptom: Every action shows both an inline green banner and a top-right toast with the same text.
Required: keep one pattern (toast for success; inline for errors and for anything needing a decision). Make banners dismissible or auto-clear.

F-23 | P2 | FE | client 10-19 234809, 235934, 233604
Symptom: A cancelled appointment page still shows "On the day: come to Window 2...", the "Not yet verified" priority banner, and a "Book again" next to stale instructions; history is one long unpaginated list with future cancelled items at the bottom; messages from a week ago still look current.
Required: terminal-state pages show only what is relevant, with a short outcome summary; paginate or group history (Upcoming / Past) with status filter; relative dates for old messages.

F-24 | P2 | FE | admin daily slots 10-10 223313 to 224312
Symptom: Page is very long (hundreds of rows), saves per row, auto-submits when the Open checkbox changes, repeats the same warning text per service, and has no window collapse or summary row.
Required: per-window collapsible sections with a one-line summary (places offered, booked, serveable), sticky date header, one aggregated warning per window, undo or confirm for close/remove, and keyboard-friendly bulk edit.

F-25 | P2 | BE+FE | all
Symptom: IDs appear in some admin URLs (/admin/staff/19/edit) and in client URLs (/my-appointments/235) as sequential integers.
Required: only after Phase 2 confirms authorization is airtight, propose (do not apply without asking) opaque identifiers for client-facing appointment URLs.

========================================
DECISIONS (ask me; recommended default in brackets; do not pick for me)
========================================
D1 Capacity model: places per service per slot, or a shared places budget per window across its services? [Per-window budget = active staff x floor(slot minutes / average service minutes), split across services.]
D2 A window with zero active staff: still bookable, warn only, or unbookable? [Unbookable plus admin warning.]
D3 Office days and weekends: which days are open (templates currently include Saturday and Sunday)? Are walk-ins accepted on holidays/closed days? Which duration is the source of truth (service duration vs window average)? [Mon to Fri open; service duration is truth; window average removed or derived.]
D4 Call order: strictly by priority tier, then check-in time? Or by booked slot time? When is the queue number issued: at booking or at check-in? [Priority tier, then check-in time; keep the booking reference as ticket ID; issue the queue number at check-in with window prefixes.]
D5 Priority policy: can one appointment hold several claims? Does senior status from DOB pre-fill only, or grant priority automatically? [Pre-fill only; everything verified at the counter against an ID; multi-claims allowed, each verified separately.]
D6 Cancel/move cutoff hours, maximum active bookings per client, same-service double-booking, no-show strikes or temporary booking block. [Cutoff 2 hours before slot; 3 active bookings; no same-service duplicates on the same day; no block, only a report.]
D7 Check-in window (minutes before and after the slot) and automatic no-show after a grace period. [Check-in from 30 min before to 15 min after; auto no-show at end of slot plus grace.]
D8 Holiday closure effect on existing bookings: auto-cancel and notify, or list for manual rebooking? [Notify and list for one-click rebook; no silent auto-cancel.]
D9 Fees: record only, or integrate payment? [Record only; rename to "Fees recorded".]
D10 SMS: provide a real provider now, or hide SMS until configured? [Hide.]
D11 Client-account admin tools: which actions (view, deactivate, reset password, see history)? [View, deactivate, send reset link.]
D12 Privacy notice and retention: text and period. [Short notice at registration; I will supply the wording.]

========================================
REQUIRED OUTPUT DISCIPLINE
========================================
- Start with the System Map and the Phase 1 verification table. Do not edit code until Phase 1 is delivered.
- Keep the visual design consistent with the existing UI (same components, spacing, typography). Do not restyle what is not listed.
- Every user-facing message must be plain language a resident can understand. No class names, SQL, enum keys, or stack traces anywhere in the UI.
- Every new or changed rule gets an automated test and a short comment stating the rule in words.
- If a finding turns out to be correct behavior, say so and explain; do not change it.
- If you run out of context, stop at a clean boundary, summarize what is done, and list the next finding ID. Do not partially apply a fix.
````

---

## Quick reference: what to expect from the model

1. **First reply:** a System Map and a verification table (CONFIRMED / NOT-REPRODUCED / UNKNOWN) with file:line evidence. Nothing edited yet.
2. **Second reply:** the route and role matrix, plus a numbered batch of decision questions (D1 to D12). Answer these before it touches capacity, queue order, priority, or cancellation rules.
3. **After that:** fixes by priority with tests, then a final report.

Answer the decision questions in one message (even just "use all recommended defaults except D5: ...") so the model doesn't stall.
