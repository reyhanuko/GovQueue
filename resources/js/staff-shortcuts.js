/**
 * Keyboard shortcuts for the staff queue console.
 *
 * Alt rather than Ctrl, and that is deliberate. Ctrl+N is the browser's new
 * window and Ctrl+S is save-page, and a counter that hijacks those is a
 * counter staff work around. Alt is not a chord anybody types by accident, and
 * the two accelerators browsers really do own under Alt - F, E, V, A, H - are
 * not among these.
 *
 * The targets are declared in the markup with data-gq-shortcut rather than
 * found by matching Livewire's own attribute syntax. Two of the three actions
 * take an argument - skip(12), openComplete(12) - so there is no fixed string
 * to match, and a selector that guesses at wire:click breaks the day somebody
 * adds a space.
 *
 * The shortcuts are live only on the console, and never while somebody is
 * typing: a shortcut that fires while a name is being typed into a field is
 * worse than no shortcut at all.
 */
document.addEventListener('keydown', (event) => {
    if (!event.altKey || event.ctrlKey || event.metaKey) {
        return;
    }

    if (
        event.target instanceof Element &&
        event.target.matches('input, textarea, select, [contenteditable]')
    ) {
        return;
    }

    // Only on the console. The attribute is on the console's root, and
    // nowhere else in the application.
    const console = document.querySelector('[data-window-code]');

    if (!console) {
        return;
    }

    const shortcuts = {
        n: 'call-next',
        c: 'complete',
        s: 'skip',
    };

    const name = shortcuts[event.key.toLowerCase()];

    if (!name) {
        return;
    }

    /*
     * The first one that is actually on screen.
     *
     * The console shows the same action in more than one place - skip is on
     * the client being served and on every client waiting - and only one of
     * them is the one a clerk means.
     */
    const target = Array.from(
        console.querySelectorAll(`[data-gq-shortcut="${name}"]`),
    ).find((el) => el.getBoundingClientRect().height > 0);

    if (!target || target.disabled) {
        return;
    }

    event.preventDefault();

    target.click();
});
