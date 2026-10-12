<?php

namespace App\Providers;

use App\Contracts\QueueAnnouncer;
use App\Contracts\QueueReader;
use App\Services\QueueBroadcaster;
use App\Services\QueueReaderService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Both queue collaborators are bound to their contracts.
         *
         * QueueService announces every change it makes, and QueueBroadcaster needs
         * to read the queue to build the event payload - so the two genuinely need
         * each other and binding one to the other directly is an infinite
         * construction loop. (Tried: it exhausts memory, which is a much louder
         * failure than the lazy container lookup it replaced, but the same bug.)
         *
         * What actually resolves it is the split by direction, not by name: the
         * broadcaster reads through QueueReader, which knows nothing about
         * transitions. The graph is then
         *
         *     QueueService -> QueueAnnouncer -> QueueBroadcaster -> QueueReader
         *
         * with no edge pointing back, so construction always terminates. These
         * bindings also give tests somewhere to put a fake that records what was
         * announced, without standing up a WebSocket to find out.
         *
         * Both are singletons because neither holds per-request state, and both are
         * read-mostly: the queue's answers do not change within a request.
         */
        $this->app->singleton(QueueAnnouncer::class, QueueBroadcaster::class);
        $this->app->singleton(QueueReader::class, QueueReaderService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->bookingRateLimit();
    }

    /**
     * Cap how fast one client can change slot occupancy.
     *
     * Named rather than inline (`throttle:10,1`) for two reasons.
     *
     * Laravel's default key is the authenticated user's id, falling back to the
     * IP - which is right, but invisible: it cannot be read off from the app, so
     * nothing can assert on it and a test cannot isolate itself from a previous
     * run's counter. Declaring the key here makes it a reviewable line.
     *
     * The named limiter also lets the response say something. The default is a
     * bare 429 page, which for someone standing at a municipal office mid-booking
     * is the same as "the system is broken". They are told to wait a minute and
     * try again, and given the same Retry-After any correct client would send.
     */
    private function bookingRateLimit(): void
    {
        RateLimiter::for(
            'booking',
            // Keyed per user, never per IP: a shared counter would let one client
            // lock out every other resident behind one office's NAT or a single
            // municipal connection.
            fn (Request $request) => Limit::perMinute(10)->by(
                'booking|'.($request->user()?->getAuthIdentifier() ?? $request->ip())
            ),
            fn (Request $request, array $headers) => throw new TooManyRequestsHttpException(
                (int) ($headers['Retry-After'] ?? 60),
                'You have made several booking changes in a short time. '
                .'Please wait a minute and try again - your existing booking is unaffected.',
                null,
                $headers,
            ),
        );
    }
}
