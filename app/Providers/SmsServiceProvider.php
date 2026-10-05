<?php

use App\Services\SmsManager;

// Registered as a singleton: the gateway is resolved once and reused, so the
// reminder job does not rebuild it for every appointment it notifies.
app()->singleton(SmsManager::class, fn () => new SmsManager);
