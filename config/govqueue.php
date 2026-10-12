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
