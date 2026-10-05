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
        connected: false,

        init() {
            this.connected = window.Echo?.connector?.pusher?.connection?.state === 'connected';

            document.addEventListener('govqueue:connection', (event) => {
                this.connected = Boolean(event.detail);
            });
        },
    };
};

/**
 * Bind Echo channels to Livewire components.
 *
 * Channel details come from data attributes on the component's root element
 * rather than from Livewire internals, which keeps this working across Livewire
 * versions.
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
     * @param {Function} resolve   (element) => Echo channel | null
     */
    const bind = (componentName, method, eventName, resolve) => {
        Livewire.hook('mount', ({ component, cleanup }) => {
            if (component.name !== componentName) {
                return;
            }

            const element = component.el ?? component.root;
            const channel = element ? resolve(element) : null;

            if (!channel) {
                return;
            }

            const handler = (payload) => {
                const target = Livewire.find(component.id);

                if (target) {
                    target.call(method, payload ?? {});
                }
            };

            channel.listen(`.${eventName}`, handler);

            cleanup(() => {
                if (typeof channel.stopListening === 'function') {
                    channel.stopListening(`.${eventName}`, handler);
                }
            });
        });
    };

    // Public lobby display: one channel per window, public.
    bind('display.public-queue-display', 'onQueueUpdated', 'queue.updated', (element) => {
        const code = element.dataset.windowCode;
        return code ? window.Echo.channel(`queue.${code}`) : null;
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