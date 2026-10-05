<?php

namespace App\Console\Commands;

use App\Events\QueueUpdated;
use App\Models\ServiceWindow;
use Illuminate\Console\Command;

/**
 * Proves the realtime path actually works, end to end.
 *
 * Connects a raw WebSocket client to Reverb using the Pusher protocol, subscribes
 * to a window's public queue channel, then triggers a real broadcast and reports
 * whether the event arrived and whether the payload is safe to publish on an
 * unauthenticated channel.
 *
 * The feature tests cover the event classes in isolation; this covers the parts
 * they cannot: that Reverb is running, that the app key is correct, and that
 * the event is not sitting unprocessed in the jobs table.
 */
class VerifyRealtimeCommand extends Command
{
    protected $signature = 'govqueue:verify-realtime
                            {--window=WINDOW_1 : Window code to subscribe to}
                            {--timeout=8 : Seconds to wait for the event}';

    protected $description = 'Check that a queue broadcast reaches a live websocket subscriber';

    public function handle(): int
    {
        $windowCode = $this->option('window');
        $timeout = (int) $this->option('timeout');

        $window = ServiceWindow::where('code', $windowCode)->first();

        if (! $window) {
            $this->error("Unknown window: {$windowCode}");

            return self::FAILURE;
        }

        $host = config('broadcasting.connections.reverb.options.host');
        $port = (int) (config('broadcasting.connections.reverb.options.port') ?? 8080);
        $key = config('broadcasting.connections.reverb.key');

        $this->line("Connecting to Reverb at {$host}:{$port}");

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);

        if (! $socket) {
            $this->error("Cannot reach Reverb on {$host}:{$port} ({$errstr}).");
            $this->line('Start it with: php artisan reverb:start');

            return self::FAILURE;
        }

        $channel = 'queue.'.$windowCode;
        $path = "/app/{$key}?protocol=7&client=govqueue-verify&version=1.0.0";

        fwrite($socket, $this->handshake($path, $host, $port));
        stream_set_timeout($socket, $timeout);

        $response = $this->readFor($socket, $timeout);

        if (! str_contains($response, '101')) {
            $this->error('WebSocket handshake failed.');
            fclose($socket);

            return self::FAILURE;
        }

        $this->line('  handshake: 101 Switching Protocols');

        fwrite($socket, $this->encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => $channel],
        ]));

        $ack = $this->readFor($socket, $timeout);
        $subscribed = str_contains($ack, 'subscription_succeeded');

        $this->line('  subscribed to '.$channel.': '.($subscribed ? 'yes' : 'NO'));
        fclose($socket);

        if (! $subscribed) {
            $this->error('Reverb did not confirm the subscription.');

            return self::FAILURE;
        }

        // Reconnect and trigger the broadcast from another process so the
        // listener is already attached when the event fires.
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        fwrite($socket, $this->handshake($path, $host, $port));
        stream_set_timeout($socket, 1);
        $this->readFor($socket, 2);
        fwrite($socket, $this->encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => $channel],
        ]));
        $this->readFor($socket, 2);

        $this->line('  dispatching a real '.QueueUpdated::class);
        $this->dispatchInSubprocess($windowCode);

        stream_set_timeout($socket, $timeout);
        $frames = $this->readFor($socket, $timeout, stopWhen: 'queue.updated');
        fclose($socket);

        $received = str_contains($frames, 'queue.updated');

        $this->line('  event received: '.($received ? 'YES' : 'no'));

        if (! $received) {
            $this->error('The broadcast did not reach the subscriber.');

            return self::FAILURE;
        }

        // The channel is public, so assert nothing identifying went out on it.
        $leaks = array_values(array_filter(
            ['client_name', 'user', 'reference_number', 'priority', 'phone', 'birthdate'],
            fn ($needle) => str_contains($frames, $needle)
        ));

        if ($leaks !== []) {
            $this->error('Payload on a PUBLIC channel leaked: '.implode(', ', $leaks));

            return self::FAILURE;
        }

        $this->line('  payload on the public channel leaks nothing: yes');
        $this->info('Realtime verified.');

        return self::SUCCESS;
    }

    /**
     * Fire the broadcast from a separate process so this one stays the listener.
     */
    private function dispatchInSubprocess(string $windowCode): void
    {
        $script = tempnam(sys_get_temp_dir(), 'gq_verify').'.php';

        file_put_contents($script, sprintf(
            '<?php require %s; $app = require %s; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' app(App\Services\QueueBroadcaster::class)->queueChanged('
            .'App\Models\ServiceWindow::where("code", %s)->first(), today()->toDateString());',
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
            var_export($windowCode, true),
        ));

        $pipes = [];
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

        if (is_resource($process)) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        @unlink($script);
    }

    private function handshake(string $path, string $host, int $port): string
    {
        return "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}:{$port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";
    }

    /**
     * Client-to-server text frame, masked as the protocol requires.
     */
    private function encode(array $payload): string
    {
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $len = strlen($data);

        $frame = chr(0x81);

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126).pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $len; $i++) {
            $frame .= $data[$i] ^ $mask[$i % 4];
        }

        return $frame;
    }

    /**
     * Read frames for a bounded time, optionally stopping early.
     */
    private function readFor($socket, int $seconds, ?string $stopWhen = null): string
    {
        $buffer = '';
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $chunk = @fread($socket, 8192);

            if ($chunk === false || $chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            if ($stopWhen !== null && str_contains($buffer, $stopWhen)) {
                break;
            }
        }

        return $buffer;
    }
}
