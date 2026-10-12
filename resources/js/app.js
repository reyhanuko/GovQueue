import './bootstrap';
import './echo';
import './govqueue';

// Prevent double submission: once a plain HTML form starts posting, disable
// its submit controls. Validation happens before this event, so legitimate
// validation failures never get this far.
//
// A submission that was CANCELLED is the exception, and skipping it is not an
// optimisation - it is the difference between a working and a broken button.
// govqueueConfirm() opens the confirmation dialog and returns false, which
// preventDefaults the event; this handler then disabled the button anyway and
// never re-enabled it. The result was a Remove button that worked exactly once:
// click, cancel, and it was dead until a page refresh. The same shape broke the
// windows/templates delete dialogs, where cancelling made the dialog look as
// though it had never worked at all.
//
// document is an ancestor of the form, so any inline onsubmit handler has
// already run by the time this fires and defaultPrevented is already accurate.
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (event.defaultPrevented) {
        return;
    }

    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((el) => {
        el.disabled = true;
        el.classList.add('opacity-60', 'cursor-not-allowed');
    });
});

// Close the native <details> mobile menu when the user taps elsewhere, so it
// does not stay open over the page content.
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[open]').forEach((el) => {
        if (!el.contains(event.target)) {
            el.removeAttribute('open');
        }
    });
});

// Also close the mobile menu when the user presses Escape (keyboard / tablet).
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('details[open]').forEach((el) => {
            el.removeAttribute('open');
        });
    }
});

// Plain HTML forms cannot use Livewire's wire:dirty, so mark any form that
// opts in with data-track-dirty; once the user edits anything inside it, the
// sibling [data-dirty-banner] becomes visible until save navigates away.
document.addEventListener('input', (event) => {
    const form = event.target.closest('form[data-track-dirty]');

    if (!form) {
        return;
    }

    form.querySelectorAll('[data-unsaved]').forEach((el) => {
        el.classList.remove('opacity-0');
        el.classList.add('opacity-100');
    });
});