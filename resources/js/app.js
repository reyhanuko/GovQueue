import './bootstrap';
import './echo';
import './govqueue';

// Prevent double submission: once a plain HTML form starts posting, disable
// its submit controls. Validation happens before this event, so legitimate
// validation failures never get this far.
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
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