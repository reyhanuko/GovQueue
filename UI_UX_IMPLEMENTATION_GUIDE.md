# GovQueue UI/UX Implementation Guide

## Status

Every item below was checked against the code on 19 October 2026. The statuses are
**verified, not carried over**.

This file was written before several of these items were implemented, and it went on
listing them as outstanding. It also listed one item as outstanding in the same
breath as listing it as done. If you are picking up work from here, the table is the
part that matters; the rest of the file explains what is left and why.

| # | Item | Status |
|---|---|---|
| 1 | Visual feedback on slot selection | **Implemented** |
| 2 | Manual retry button for queue status errors | **Implemented** |
| 3 | Loading states for staff console actions | **Implemented** |
| 4 | Public display offline warning | **Implemented** - this file used to call it outstanding |
| 5 | Progressive disclosure for the priority reference field | **Outstanding** |
| 6 | Keyboard shortcuts for the staff console | **Outstanding** - but not with the keys originally proposed, see below |
| 7 | Confirmation before cancelling an appointment | **Implemented, differently** - inline two-step, not a modal |
| 8 | Mobile menu behaviour | **Implemented** - and the "improved" code this file used to propose is a regression, see below |
| 9 | Focus management after Livewire actions | **Partly** - the skip link exists, focus-to-feedback does not |
| 10 | Responsive queue number sizing | **Outstanding** |
| - | Touch target audit | **Outstanding**, and not yet measured |
| - | Colour contrast | **Done**, and pinned by `tests/Browser/contrast.spec.ts` |
| - | Service worker for offline support | **Not recommended**, and not implemented - see below |

---

## ✅ Implemented

### Issue #1: Visual feedback on slot selection

`resources/views/client/booking/create.blade.php`

Alpine state (`selectedSlot`, `selectedDate`, `selectedTime`, `selectSlot`) drives a
green confirmation banner and a summary of the chosen slot, so the client can see
what they picked without scrolling to the confirm panel. On mobile that panel sits
below the fold, and this is the difference between a client who knows they picked
09:00 and one who wonders whether the tap registered.

### Issue #2: Manual retry button for queue status errors

`resources/views/livewire/client/queue-status.blade.php`

A `role="alert"` panel with a **Try again** button calling `refreshPosition`, with
`wire:loading` / `wire:loading.remove` on the label so the button shows *Retrying...*
while it works. Previously the only recovery was waiting out the automatic retry.

### Issue #3: Loading states for staff console actions

`resources/views/livewire/staff/queue-console.blade.php`

`wire:target="callNext"` and the equivalent on Complete, Skip and No-show, each
swapping the label for a spinner. The target matters: without it the spinner is
attached to the whole component, so one button's wait dims the entire console.

### Issue #4: Public display offline warning

`resources/views/livewire/display/public-queue-display.blade.php`

**This was listed here as outstanding and is not.** It is implemented, and it
distinguishes two states that matter to somebody standing in a lobby:

- `state === 'offline'` - the socket is down but the Livewire poll is still running,
  so the numbers on the board are current. Rose banner, and it says so:
  *"Offline - updates every 10 seconds"*.
- `state !== 'offline'` - the connection dropped and the board may be stale. Amber
  banner: *"Connection interrupted"*.

Both carry the last-updated stamp. The original proposal here was a single amber
banner for every non-live state, which would have been wrong: it would have told
people the board was out of date when the poll was keeping it right.

### Issue #7: Confirmation before cancelling an appointment

`resources/views/client/appointments/show.blade.php`

Implemented as a **two-step inline disclosure** rather than the modal proposed
below: the "Cancel appointment" button reveals a reason field and a "Yes, cancel it"
confirmation, all in the flow of the page.

This is a deliberate difference from the proposal, and the inline version is the
better one on a phone. The modal in the original proposal puts a full-screen
overlay above the page, on a 375px-wide screen, for a form with one optional text
field in it. It also had to invent a `cancel_reason` field name that does not exist
in the controller, so the proposal as written would have submitted a reason the
server never reads.

### Issue #8: Mobile menu behaviour

`resources/views/layouts/app.blade.php` and `resources/js/app.js`

**This was listed here as outstanding and is not.** The navbar collapses into a
native `<details>` disclosure below `md`, and `app.js` closes it on an outside
click and on `Escape`.

**The "improved" code this file used to propose is worse than what is there.** It
added an early return when the click landed on a `summary`:

```javascript
// DO NOT SHIP THIS - it is worse than the current code.
const clickedSummary = event.target.closest('summary');
if (clickedSummary) return;
```

With that guard, opening the staff menu while the notifications disclosure is open
leaves the notifications disclosure open behind it. The current code closes every
open disclosure the click did not land inside, which is the behaviour a disclosure
menu is expected to have. The guard was solving a problem the existing code does
not have.

### Issue #9 (partly): Focus management

`resources/views/layouts/app.blade.php:17` already carries a **Skip to main content**
link, and `resources/js/govqueue.js:49` hooks `morph.updated` - but only to restamp
the display's "last updated" time, not to move focus.

What is missing is the other half: after a Livewire action, focus is not moved to
the feedback message. A keyboard user who activates "Complete" has their focus left
on a button that is about to be re-rendered, and the announcement that the
transaction closed is made to a screen reader that may not be looking at the right
place.

The proposal in the original version of this file did two things, and only one of
them is still missing:

- ~~Skip links~~ - already there.
- **Focus to the feedback message after a Livewire update.** Still outstanding.

```javascript
// resources/js/livewire-focus.js
/**
 * Move focus to the message a Livewire update produced.
 *
 * A keyboard user who activates an action has their focus left on a control that
 * is about to be re-rendered out from under them. Moving it to the message that
 * reports the outcome is the difference between hearing what happened and not.
 *
 * Only role="status" and role="alert" are considered, and only when the focused
 * element is not already inside one - moving focus while somebody is typing in a
 * field inside the updated region would be worse than leaving it alone.
 */
document.addEventListener('livewire:init', () => {
    Livewire.hook('morph.updated', ({ el }) => {
        const feedback = el.querySelector('[role="status"], [role="alert"]');

        if (!feedback || feedback.contains(document.activeElement)) {
            return;
        }

        feedback.setAttribute('tabindex', '-1');
        feedback.focus({ preventScroll: false });

        feedback.addEventListener('blur', () => {
            feedback.removeAttribute('tabindex');
        }, { once: true });
    });
});
```

Then add it to `resources/js/app.js`:

```javascript
import './livewire-focus';
```

The `aria-live` half of the original proposal - adding `aria-live="polite"` to every
Livewire root on init - is **not** worth doing. It puts every dynamically updated
region of every component into a live region, and a screen reader then announces
whatever changed in a table that repaints every ten seconds. The feedback elements
that matter already carry `role="status"` or `role="alert"`; they are the ones that
should be live.

---

## 🟡 Outstanding

### Issue #5: Progressive disclosure for the priority reference field

`resources/views/client/booking/create.blade.php`

The "Supporting reference" field is always visible, even for a client with no
priority claim to support. It is required server-side whenever a PWD or pregnancy
claim is made, and optional otherwise, so the honest version of this issue is "the
field is visible when it is not needed", not "the field is not validated".

**The rule already exists on the staff side.** The walk-in panel at
`resources/views/livewire/staff/queue-console.blade.php:131` works out whether a
reference is needed from the claims actually made, and renders the field only then:

```php
$needsReference = array_intersect(
    [PriorityType::Pwd->value, PriorityType::Pregnant->value],
    $walkInClaims,
) !== [];
```

`tests/Browser/walkin-priority.spec.ts` pins the behaviour: *"a reference is asked
for only where one cannot be inferred"*. So this is not a new rule to invent - it
is one rule stated in two places, and the client booking form is the place that has
not caught up.

The booking form's claim checkboxes are already in the template, so the disclosure
only needs the reference block wrapped and the requirement toggled from the claim
state:

```blade
{{-- The claim checkboxes already carry the claim state; the reference block
     below is revealed by it, so a client with no claim is never shown a field
     they have no reason to fill in. The server still requires the reference
     whenever a PWD or pregnancy claim is made - this is presentation only. --}}
<div x-show="needsRef" x-cloak>
    <label for="priority_reference" class="block text-sm font-medium text-slate-700">
        Supporting reference
        <span class="text-rose-600">*</span>
    </label>
    <input id="priority_reference"
           name="priority_reference"
           type="text"
           placeholder="e.g. PWD-1234567 or clinic referral no."
           value="{{ old('priority_reference') }}"
           :required="needsRef"
           class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
    <p class="mt-1 text-xs text-amber-700">
        Required to verify your PWD or pregnant priority claim.
    </p>
    @error('priority_reference')
        <p class="mt-1 text-sm font-medium text-rose-700">{{ $message }}</p>
    @enderror
</div>
```

`needsRef` has to be derived from the two checkboxes that require a reference
(`claims_pwd` and `claims_pregnant`), and it has to survive a validation failure -
after a failed submit the checkboxes are re-rendered from `old()` and the reference
field must still be visible, or the client is shown an error they cannot see the
cause of.

**Test:** claim PWD, confirm the field appears; submit without a reference, confirm
the error is shown *and* the field is still visible.

### Issue #6: Keyboard shortcuts for the staff console

**Not with the keys originally proposed.** `Ctrl+N` is the browser's new-window
shortcut and `Ctrl+S` is save-page; `preventDefault()` cannot stop a browser-level
shortcut the way it stops a page-level one, and swallowing `Ctrl+S` on a form that
somebody wants to save is its own bug.

`Alt` is the modifier browsers leave alone, and it is not a chord anybody types by
accident. These are the ones worth having:

| Action | Key |
|---|---|
| Call next client | `Alt+N` |
| Complete the transaction in front of you | `Alt+C` |
| Skip the client in front of you | `Alt+S` |
| Close whatever is open | `Escape` |

```javascript
// resources/js/staff-shortcuts.js
/**
 * Keyboard shortcuts for the staff queue console.
 *
 * Alt rather than Ctrl: Ctrl+N is the browser's new-window and Ctrl+S is
 * save-page, and a counter that hijacks those keys is a counter staff work
 * around. Alt is not a chord anybody types by accident, and browsers leave it
 * alone.
 *
 * Shortcuts are live only on a console screen, and never while somebody is
 * typing - a shortcut that fires while a name is being typed into a field is
 * worse than no shortcut at all.
 */
document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('[data-window-code]')) {
        return;
    }

    document.addEventListener('keydown', (e) => {
        if (!e.altKey || e.ctrlKey || e.metaKey) {
            return;
        }

        if (e.target.matches('input, textarea, select, [contenteditable]')) {
            return;
        }

        const actions = {
            n: 'callNext',
            c: 'complete',
            s: 'skip',
        };

        const action = actions[e.key.toLowerCase()];

        if (!action) {
            return;
        }

        const button = document.querySelector(`button[wire\\:click="${action}"]`);

        if (button && !button.disabled) {
            e.preventDefault();
            button.click();
        }
    });
});
```

And the hint, in the console, so the shortcuts are discoverable rather than
mysterious:

```blade
<div class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
    <details>
        <summary class="font-semibold">Keyboard shortcuts</summary>
        <dl class="mt-2 space-y-1">
            <div class="flex justify-between gap-4">
                <dt>Call next:</dt>
                <dd class="font-mono">Alt+N</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt>Complete:</dt>
                <dd class="font-mono">Alt+C</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt>Skip:</dt>
                <dd class="font-mono">Alt+S</dd>
            </div>
        </dl>
    </details>
</div>
```

The `data-window-code` selector has to exist on the console root, and the buttons
have to carry the `wire:click` names above. Check both before shipping - a shortcut
that silently does nothing is worse than no shortcut.

### Issue #10: Responsive queue number sizing

`resources/views/livewire/display/public-queue-display.blade.php`

The serving number is rendered at a fixed `text-7xl sm:text-8xl`. A queue number is
`PREFIX-NNN`, and the prefix is set by an administrator per window - `A-001` is five
characters, but a window whose prefix is `CLEARANCE` issues `CLEARANCE-001` at
thirteen. The fixed size was chosen for the short case and overflows the board for
the long one.

Size by the length of the number rather than by the viewport alone:

```blade
@php
    $queueNumber = $this->serving->queue_number;
    $sizeClasses = match (strlen($queueNumber)) {
        0, 1, 2, 3, 4, 5 => 'text-7xl sm:text-8xl',
        6, 7 => 'text-6xl sm:text-7xl',
        default => 'text-5xl sm:text-6xl',
    };
@endphp

<p class="font-black tracking-tight tabular-nums {{ $sizeClasses }} break-words">
    {{ $queueNumber }}
</p>
```

`tabular-nums` matters as much as the size: the board is watched for a number that
changes, and a proportional digit makes it jitter as the number changes.

The same treatment applies to the "Next in line" cards, at a smaller scale.

---

## 📋 Future considerations

### Touch target size audit - outstanding, and not yet measured

WCAG 2.2 asks for 24x24 CSS pixels at AA and 44x44 at AAA; iOS asks for 44px and
Android for 48dp. The app has not been measured against either, and this file used
to suggest a console snippet to do it.

The console snippet works, but it is a one-off: it measures the screen you happen
to have open, it measures nothing on a real phone, and the result is a list in a
console that nobody reads twice. The project already has a Playwright suite with a
shared seeded database, so the right home for this is a spec file that measures
every interactive element on a 375px viewport and fails on anything under the
threshold - the same way `contrast.spec.ts` already pins colour contrast.

That is a genuine piece of work, not a checkbox.

### Colour contrast - done

Contrast is audited by `tests/Browser/contrast.spec.ts`, which measures computed
colours on the rendered pages rather than trusting the Tailwind class names. It is
part of the browser suite, so a regression fails the build rather than waiting for
a person to notice.

### Service worker for offline support - not recommended

**This file used to propose one. Do not add it.**

A service worker caches the application shell and serves it when the network is
gone. For this application that means a public lobby board served from cache,
showing a queue position that was correct when it was cached. A queue number that
has moved on, displayed with no indication that it is stale, is worse than an
honest "the display is not updating" - it sends people to the wrong window.

The offline case is already handled in the direction that matters. When the socket
drops, the display falls back to a Livewire poll and says so: *"Offline - updates
every 10 seconds"* in rose, and *"Connection interrupted"* in amber when even that
has stopped. That is the correct behaviour for a public queue board - it keeps
working while it can, and it says plainly when it cannot.

A cached booking form is a different question, and a defensible one, but it needs
its own decision about what a client sees when the slot they chose may have gone
while they were offline. That is a product decision, not a code snippet.

---

## Implementation priority order

Re-ordered against what is actually left. The original order in this file put
already-implemented items first, which is how a reader could spend a morning
re-implementing something that was already there.

1. **Issue #10**: Responsive queue numbers (30 minutes) - small, and a visible
   overflow on the board people stare at
2. **Issue #5**: Progressive disclosure for the priority reference (45 minutes)
3. **Issue #6**: Keyboard shortcuts, with `Alt` keys (2 hours) - includes checking
   the selector and the button names
4. **Issue #9**: Focus to the feedback message after a Livewire update (1 hour)
5. **Touch target audit**: as a browser test, not a console snippet (2 hours)

Total: about 6.5 hours of work, plus whatever the touch target audit turns up.

---

## Acceptance criteria

Each change should meet these:

- [ ] Code follows the existing project conventions
- [ ] A test covers the behaviour, and the test fails without the change
- [ ] Keyboard navigation works, and the change does not steal a browser shortcut
- [ ] No console errors
- [ ] Loading states give feedback
- [ ] Error states allow recovery
- [ ] The status table at the top of this file is updated in the same commit

The last one is the one that was missing before.

---

## Resources

### Documentation

- [Tailwind CSS](https://tailwindcss.com/docs)
- [Alpine.js](https://alpinejs.dev/)
- [Livewire](https://livewire.laravel.com/)
- [WCAG 2.2 Guidelines](https://www.w3.org/WAI/WCAG22/quickref/)

### Tools

- [Lighthouse](https://developer.chrome.com/docs/lighthouse) - accessibility audit
- [axe DevTools](https://www.deque.com/axe/devtools/) - accessibility testing
- [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)

### Testing

- [NVDA Screen Reader](https://www.nvaccess.org/) - free for Windows
- [VoiceOver](https://www.apple.com/accessibility/voiceover/) - built into macOS/iOS
- [BrowserStack](https://www.browserstack.com/) - cross-browser testing

---

*Last verified against the code on 19 October 2026. Statuses in the table are
verified against the source, not copied forward from an earlier draft.*
