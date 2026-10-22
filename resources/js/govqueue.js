/**
 * GovQueue front-end helpers.
 */

/**
 * Connection indicator for the public display.
 *
 * Listens for the custom events dispatched from echo.js rather than poking at
 * Echo internals, so the indicator keeps working if the transport changes.
 */
window.govQueueDisplay = function () {
    return {
        /**
         * 'live'        socket connected, updates arrive by websocket
         * 'reconnecting' socket down but pusher-js is retrying
         * 'offline'     no socket and the poll fallback is what is keeping
         *               this board current
         */
        state: 'reconnecting',

        lastUpdated: null,

        now: null,

        init() {
            this.lastUpdated = this.stamp();

            // Echo is instantiated at module load, but its connection state is
            // only meaningful once Pusher has actually attempted a handshake.
            // Reading it once here meant the badge showed "Reconnecting" on a
            // perfectly healthy board until some later event happened to fire.
            const connection = window.Echo?.connector?.pusher?.connection;

            this.reflect(connection?.state);

            connection?.bind('connected', () => this.reflect('connected'));
            connection?.bind('disconnected', () => this.reflect('disconnected'));
            connection?.bind('unavailable', () => this.reflect('unavailable'));
            connection?.bind('failed', () => this.reflect('failed'));
            connection?.bind('state_change', ({ current }) => this.reflect(current));

            document.addEventListener('govqueue:connection', (event) => {
                this.reflect(event.detail ? 'connected' : 'disconnected');
            });

            // The Livewire poll is the fallback that keeps the board correct
            // when the socket is down. Every successful poll updates
            // data-last-updated, which is what the badge reports.
            this.$wire?.hook?.('morph.updated', () => {
                this.lastUpdated = this.stamp();
            });

            this.timer = window.setInterval(() => {
                this.now = new Date();
            }, 1000);
        },

        label() {
            return this.state === 'live'
                ? 'Live'
                : this.state === 'reconnecting'
                    ? 'Reconnecting'
                    : 'Offline (polling)';
        },

        dotClasses() {
            return this.state === 'live'
                ? 'bg-emerald-400'
                : this.state === 'reconnecting'
                    ? 'bg-amber-400'
                    : 'bg-rose-400';
        },

        /**
         * Human-readable age of the last successful refresh, so a frozen board
         * is obvious from across the lobby rather than only to the socket.
         */
        lastUpdatedLabel() {
            if (! this.lastUpdated) {
                return 'never';
            }

            const seconds = Math.max(
                0,
                Math.round(((this.now ?? new Date()) - new Date(this.lastUpdated)) / 1000),
            );

            if (seconds < 10) {
                return 'just now';
            }

            if (seconds < 60) {
                return `${seconds}s ago`;
            }

            return `${Math.floor(seconds / 60)}m ago`;
        },

        stamp() {
            return new Date().toISOString();
        },

        // Not #private: Alpine proxies component state, and the private-field syntax
        // does not survive that proxying.
        /**
         * Map Pusher's connection states onto the three the board shows.
         *
         * 'connecting' and 'initialized' are transient: the handshake is still
         * in flight or being retried, so the socket is expected back and the
         * poll is only insurance. 'disconnected', 'unavailable' and 'failed'
         * are terminal for this attempt, so the board says Offline and the
         * ~10s poll is what is actually keeping the numbers correct.
         */
        reflect(rawState) {
            if (rawState === 'connected') {
                this.state = 'live';

                return;
            }

            const transitory = ['connecting', 'initialized', 'unknown'];

            this.state = transitory.includes(rawState) ? 'reconnecting' : 'offline';
        },
    };
};

/**
 * Confirm before a destructive form submission, in the app's own dialog.
 *
 * Replaces window.confirm() on the admin screens. The native dialog is
 * unstyleable, cannot carry the consequence of the action, and on some browsers
 * and screen readers it is announced with no context at all - an administrator
 * heard "Confirm" and had to guess which of nine identical-looking delete
 * buttons they had just pressed.
 *
 * Progressive enhancement, deliberately:
 *   - a visitor with no JavaScript still gets window.confirm(), because the
 *     call site checks for this function's existence first;
 *   - the message text lives in the markup at the call site, so it stays
 *     translatable and reviewable rather than being assembled in a string here;
 *   - cancelling submits nothing, and the form is left exactly as it was, so a
 *     mis-click costs one extra press rather than a lost form.
 *
 * @param {HTMLFormElement} form
 * @param {string} message   What will happen, and whether it can be undone.
 * @param {string} [detail]  Optional second line naming the specific record.
 */
window.govqueueConfirm = function (form, message, detail) {
    /*
     * The answer we already gave.
     *
     * The dialog answers by calling requestSubmit(), which fires the submit event
     * again and therefore runs the caller's onsubmit handler again - without this
     * check the dialog would reopen itself and the form would never submit.
     * Removed on the way through, so a later genuine submission of the same form
     * asks again rather than sailing past.
     */
    if (form && form.hasAttribute('data-confirm-answered')) {
        form.removeAttribute('data-confirm-answered');

        return true;
    }

    if (!form || typeof form.requestSubmit !== 'function') {
        return true;
    }

    openConfirmDialog(form, message, detail, () => {
        /*
         * requestSubmit rather than form.submit().
         *
         * form.submit() bypasses both submit event handlers, which here means it
         * would skip the double-submit guard in app.js - so a slow request could
         * be posted twice - and it would skip any validation a caller has added.
         * The temporary attribute drops the caller's own confirm() from the
         * chain, so this does not re-open the dialog it is answering.
         */
        form.setAttribute('data-confirm-answered', '1');
        form.requestSubmit();
    });

    return false;
};

/**
 * The shared confirmation dialog.
 *
 * Built once, on first use, and reused. A <dialog> is used rather than a div
 * with role="dialog" because it gives the focus trap, the Escape key, the
 * backdrop and the inert background for free - all things a hand-rolled modal
 * usually gets wrong, and all of which a keyboard user notices immediately.
 */
function openConfirmDialog(form, message, detail, onConfirm) {
    let dialog = document.getElementById('govqueue-confirm-dialog');

    if (!dialog) {
        dialog = buildConfirmDialog();
        document.body.appendChild(dialog);
    }

    dialog.querySelector('[data-gq-message]').textContent = message || 'Are you sure?';

    const detailEl = dialog.querySelector('[data-gq-detail]');

    detailEl.textContent = detail || '';
    detailEl.hidden = !detail;

    const confirmButton = dialog.querySelector('[data-gq-confirm]');

    /*
     * Reassigned rather than accumulated. A dialog that keeps a stack of
     * pending callbacks would submit whichever form happened to open it first,
     * which is exactly the mistake this whole function exists to prevent.
     */
    confirmButton.onclick = () => {
        dialog.close();
        onConfirm();
    };

    // Dismissing the dialog means the form did not go. The submit guard in
    // app.js already leaves a prevented submission's buttons alone, so this is
    // belt and braces - but if the server returns an error after a confirmation
    // (no redirect), the buttons stay disabled until revive() re-enables them.
    dialog.addEventListener('close', () => revive(form), { once: true });

    dialog.showModal();

    // Focus lands on Cancel, not on the destructive action: a dialog that opens
    // with the dangerous button already focused means Enter destroys something.
    dialog.querySelector('[data-gq-cancel]').focus();
}

/**
 * Put a form's submit controls back the way they were.
 *
 * Reads the disabled state rather than assuming it: on the confirming path the
 * guard in app.js has just disabled them on purpose, and un-disabling there
 * would let a second click through.
 *
 * @param {HTMLFormElement} form
 */
function revive(form) {
    if (!form || !form.isConnected) {
        return;
    }

    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((el) => {
        el.disabled = false;
        el.classList.remove('opacity-60', 'cursor-not-allowed');
    });
}

function buildConfirmDialog() {
    const dialog = document.createElement('dialog');

    dialog.id = 'govqueue-confirm-dialog';
    dialog.className =
        'm-auto w-full max-w-md rounded-2xl border border-slate-200 bg-white p-0 shadow-xl backdrop:bg-slate-900/50';

    // Escape and click-on-backdrop both cancel. Closing on backdrop is a
    // deliberate choice: a destructive action should never be triggered by
    // clicking near it.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.innerHTML = `
        <div class="p-6">
            <h2 class="text-lg font-bold text-slate-900" data-gq-title>Please confirm</h2>
            <p class="mt-2 text-sm text-slate-700" data-gq-message></p>
            <p class="mt-1 text-sm text-slate-600" data-gq-detail hidden></p>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" data-gq-cancel
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="button" data-gq-confirm
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                    Confirm
                </button>
            </div>
        </div>
    `;

    dialog.querySelector('[data-gq-cancel]').addEventListener('click', () => dialog.close());

    return dialog;
}

/**
 * Bind Echo channels to Livewire components.
 *
 * Channel details come from data attributes on the component's root element
 * rather than from Livewire internals, which keeps this working across Livewire
 * versions.
 *
 * resolve may return one channel or several. The lobby display needs several:
 * showing every window means listening to every window, and returning only the
 * first would leave the board updating on its 10-second poll while looking
 * perfectly healthy. That failure is invisible, which is why it is handled here
 * rather than worked around at the call site.
 *
 * The cleanup on unmount matters: without it, a client who opens appointment A
 * and then navigates to appointment B keeps A's listener attached, and the page
 * keeps updating for a ticket they have closed.
 */
document.addEventListener('livewire:init', () => {
    /**
     * @param {string} componentName
     * @param {string} method      Livewire method to invoke
     * @param {string} eventName   Broadcast event, e.g. "queue.updated"
     * @param {Function} resolve   (element) => Echo channel | Echo channel[] | null
     */
    const bind = (componentName, method, eventName, resolve) => {
        Livewire.hook('mount', ({ component, cleanup }) => {
            if (component.name !== componentName) {
                return;
            }

            const element = component.el ?? component.root;
            const resolved = element ? resolve(element) : null;

            const channels = resolved
                ? Array.isArray(resolved)
                    ? resolved.filter(Boolean)
                    : [resolved]
                : [];

            if (channels.length === 0) {
                return;
            }

            const handler = (payload) => {
                const target = Livewire.find(component.id);

                if (target) {
                    target.call(method, payload ?? {});
                }
            };

            channels.forEach((channel) => {
                channel.listen(`.${eventName}`, handler);
            });

            cleanup(() => {
                channels.forEach((channel) => {
                    if (typeof channel.stopListening === 'function') {
                        channel.stopListening(`.${eventName}`, handler);
                    }
                });
            });
        });
    };

    // Public lobby display: one channel per window shown, public.
    //
    // The board either shows a single window (?w=CODE, so windowCode is set) or
    // every active window (windowCodes lists them all). Listening to only the
    // first would leave an all-windows board running on its 10s poll fallback
    // while looking perfectly healthy, which is the failure nobody notices.
    bind('display.public-queue-display', 'onQueueUpdated', 'queue.updated', (element) => {
        if (element.dataset.windowCode) {
            return window.Echo.channel(`queue.${element.dataset.windowCode}`);
        }

        const codes = (element.dataset.windowCodes || '')
            .split(',')
            .map((code) => code.trim())
            .filter(Boolean);

        return codes.map((code) => window.Echo.channel(`queue.${code}`));
    });

    // Client's own ticket: private channel, authorised by routes/channels.php.
    bind('client.queue-status', 'onStatusChanged', 'appointment.status', (element) => {
        const id = element.dataset.appointmentId;
        return id ? window.Echo.private(`appointment.${id}`) : null;
    });

    // Staff console: the window board, so the queue updates without polling.
    bind('staff.queue-console', 'onQueueUpdated', 'queue.updated', (element) => {
        const code = element.dataset.windowCode;
        return code ? window.Echo.channel(`queue.${code}`) : null;
    });
});
