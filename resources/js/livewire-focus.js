/**
 * Move the keyboard to what just happened.
 *
 * A Livewire action reports its outcome in a live region - role="status" for
 * something that worked, role="alert" for something that did not - and a screen
 * reader announces that on its own. A sighted keyboard user gets nothing: the
 * message appears on the screen and the focus stays on the button they pressed,
 * so pressing Enter again repeats the action instead of reading the result.
 *
 * So when an update puts a message on the screen, the focus goes to it.
 *
 * Three things it will not do:
 *
 *  - It will not move focus while somebody is typing. The walk-in panel
 *    re-renders as a name is typed, and a caret thrown into the wrong field
 *    mid-name loses the rest of the name.
 *  - It will not move focus while a dialog is open. A <dialog> is modal, the
 *    page behind it is inert, and the dialog owns the focus.
 *  - It will not move focus to a message that is not on screen. The console
 *    carries a "Syncing..." live region that is in the DOM whenever a request
 *    is in flight, and focusing a hidden element does nothing at all: the
 *    focus call succeeds, no element has it, and the whole thing looks like it
 *    worked.
 */
document.addEventListener('livewire:init', () => {
    /*
     * The message is caught as it is ADDED, rather than by comparing the page
     * before and after the update.
     *
     * The obvious approach - remember which live regions were on the page,
     * then look for one that is not - does not work here, and the reason is
     * worth knowing: the console's component root is replaced during the
     * update, so the component's own element still points at the detached
     * old root afterwards. Anything looked up inside it is the page as it was
     * before the update, and the message that was just added is not in it.
     *
     * An element that was added to the page is unambiguous, and the hook that
     * says so is exact.
     */
    Livewire.hook('morph.added', ({ el }) => {
        if (!el || typeof el.matches !== 'function') {
            return;
        }

        const message = el.matches('[role="status"], [role="alert"]')
            ? el
            : el.querySelector('[role="status"], [role="alert"]');

        if (!message) {
            return;
        }

        focusTheMessage(message);
    });
});

/**
 * Put the focus on a message, unless now is the wrong moment.
 */
function focusTheMessage(message) {
    const active = document.activeElement;

    /*
     * Mid-keystroke.
     *
     * The walk-in panel re-renders as a name is typed, and a caret thrown out
     * of the field loses the rest of the name.
     */
    if (
        active &&
        active !== document.body &&
        active.matches('input, textarea, select, [contenteditable]')
    ) {
        return;
    }

    // A modal dialog owns the focus while it is open.
    if (document.querySelector('dialog[open]')) {
        return;
    }

    // A hidden message is not a message anybody can read.
    if (message.getBoundingClientRect().height === 0) {
        return;
    }

    /*
     * tabindex="-1" so the message can hold the focus without joining the
     * tab order. A live region in the tab order is a thing a keyboard user
     * tabs into for no reason.
     */
    if (!message.hasAttribute('tabindex')) {
        message.setAttribute('tabindex', '-1');
    }

    message.focus({ preventScroll: false });
}
