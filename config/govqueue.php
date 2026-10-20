<?php

/*
|--------------------------------------------------------------------------
| GovQueue domain configuration
|--------------------------------------------------------------------------
| Tunables for the LGU of Tupi. Everything an administrator might reasonably
| want to adjust lives here so the legal and operational rules stay in one
| reviewable file rather than being scattered through controllers.
*/

return [

    /*
    | RA 9994 sets the senior-citizen threshold at 60 years.
    */
    'senior_citizen_age' => env('GOVQUEUE_SENIOR_AGE', 60),

    /*
    | Lower number = served earlier, among clients who hold a *verified* claim.
    | PWD and pregnant clients rank ahead of senior citizens because their
    | circumstances are time-sensitive.
    */
    'priority_weights' => [
        'pwd' => 10,
        'pregnant' => 10,
        'senior_citizen' => 20,
    ],

    /*
    | Fallback estimate used until enough real service data exists (Sprint 9
    | replaces this with a measured average per window).
    */
    'default_service_minutes' => 10,

    /*
    | How far ahead clients may book, and how far ahead the display shows.
    */
    'booking_horizon_days' => 30,
    'queue_display_rows' => 8,

    /*
     * The days the office is open (decision D3).
     *
     * Monday to Friday. The LGU's working week; nothing in the system's history
     * suggested otherwise, and no template can be created for a day outside this
     * list.
     *
     * Configurable rather than hard-coded because a Philippine LGU may hold
     * weekend services - a Saturday barangay assembly, or a Saturday market day
     * that brings a queue with it. If this office does that, changing this list
     * is the whole fix, and no code needs to know.
     *
     * Carbon's day constants: 0 Sunday, 6 Saturday.
     */
    'open_days' => [1, 2, 3, 4, 5],

    /*
     * How much measured history a service needs before its real duration is
     * quoted to clients in place of the configured one.
     *
     * Five is deliberately small. The alternative - demanding a large sample -
     * means a young service quotes a guess for weeks, and a guess is the thing
     * this is meant to stop. Five completed appointments is enough to tell a
     * service guessed at 20 minutes that it really takes 35.
     */
    'duration_min_samples' => 5,

    /*
     * Seconds to stop attempting broadcasts after a failure.
     *
     * Queue events are broadcast synchronously so the lobby display updates the
     * instant staff call a number. That makes the socket part of a client's
     * request latency, so when Reverb is down the first attempt pays a connect
     * timeout and everything after it is skipped for this long. Without the
     * cooldown a dead Reverb added ~2.7s to every booking.
     */
    'broadcast_failure_cooldown' => 30,

    /*
    | A ticket skipped more than this many times is flagged for staff attention
    | and can be marked as a no-show instead of being called forever.
    */
    'max_skips' => 2,

    /*
    | How far back a verified priority client is re-inserted after being skipped.
    |
    | A senior citizen or PWD client who was not at the window currently drops
    | behind every regular client waiting, which is the correct outcome for an
    | ordinary ticket but not for a statutory one: the next person served may
    | have arrived after them.
    |
    | A skipped verified priority client is therefore re-inserted behind this
    | many of the clients who checked in after them, so they keep their place
    | relative to people who were not already waiting. Zero restores the old
    | behaviour of going to the very back.
    */
    'priority_skip_reinsert_after' => 2,

    /*
    | Client notification behaviour.
    */
    'notifications' => [
        /*
         * Master switch. Off means no confirmation, reminder, reschedule, or
         * cancellation message is generated at all, which is useful when the
         * office has no mail or SMS gateway configured yet.
         */
        'enabled' => env('GOVQUEUE_NOTIFICATIONS_ENABLED', true),
        'email' => env('GOVQUEUE_EMAIL_ENABLED', true),
        'sms' => env('GOVQUEUE_SMS_ENABLED', false),
        'in_app' => true,
        'reminder_hours_before' => [24, 1],
        'max_attempts' => 3,
    ],

    /*
     | End-of-day close-out.
     |
     | govqueue:close-stale-appointments shuts tickets whose slot finished without
     | anyone completing, cancelling or no-showing them. Without it a ticket that
     | was checked in and never called stayed "Checked in" indefinitely and kept
     | appearing under the client's "Upcoming & active".
     |
     | grace_minutes is the delay between a slot ending and the ticket being
     | closed, so a service that runs slightly over does not get closed from
     | under the clerk still finishing with it.
     */
    'close_out' => [
        'grace_minutes' => (int) env('GOVQUEUE_CLOSE_OUT_GRACE', 60),
    ],

    /*
     | The no-show policy (decision D6).
     |
     | A resident who does not turn up, does not cancel, and is closed out by
     | the hourly command has recorded an unexcused no-show. Three of those
     | block new bookings for a fortnight; after that the count starts again,
     | so a resident who keeps missing appointments is repeatedly given time
     | to put things right rather than being locked out for good.
     |
     | A no-show is *excused* when the client checked in: they came to the
     | office, and it is the office's call that lost them. Only a client who
     | never arrived and never cancelled counts towards the block.
     |
     | The block is on making *bookings*. A walk-in is not a booking, so a
     | blocked resident can still be served at the counter - which is the point
     | of a block rather than a ban.
     */
    'no_show_policy' => [
        'strike_threshold' => (int) env('GOVQUEUE_NO_SHOW_STRIKES', 3),
        'block_days' => (int) env('GOVQUEUE_NO_SHOW_BLOCK_DAYS', 14),
    ],

    /*
    | SMS provider abstraction. "log" writes messages to the application log so
    | the feature is demonstrable without a paid gateway; swap for "semaphore"
    | or "textme" and supply credentials to go live.
    */
    'sms' => [
        'driver' => env('GOVQUEUE_SMS_DRIVER', 'log'),
        'semaphore' => [
            'api_key' => env('SEMAPHORE_API_KEY'),
            'sender' => env('SEMAPHORE_SENDER', 'GovQueue'),
        ],
        'textme' => [
            'api_key' => env('TEXTME_API_KEY'),
        ],
    ],

    /*
    | Seeded office configuration for the Municipal Government of Tupi.
    */
    'office' => [
        'name' => 'Municipal Government of Tupi',
        'lgu' => 'Local Government Unit of Tupi',
        'province' => 'South Cotabato',
        'timezone' => 'Asia/Manila',
    ],
];
