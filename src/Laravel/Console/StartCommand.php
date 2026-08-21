<?php

declare(strict_types=1);

namespace Hemp\Collab\Laravel\Console;

use Hemp\Collab\Protocol\CompatibilityProfile;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SocketServer;
use Hemp\Collab\Server\TlsContext;
use Illuminate\Console\Command;
use React\EventLoop\Loop;

class StartCommand extends Command
{
    protected $signature = 'collab:start
        {--host= : Address to bind, defaulting to config}
        {--port= : Port to bind, defaulting to config}';

    protected $description = 'Start the Yjs collaboration server';

    public function handle(Hub $hub): int
    {
        $host = $this->option('host') ?: config('collab.host');
        $port = (int) ($this->option('port') ?: config('collab.port'));

        $server = new SocketServer(
            $hub,
            maxFrameBytes: (int) config('collab.limits.frame_bytes'),
            tls: TlsContext::resolve(config('collab.options.tls', []), config('collab.hostname')),
        );

        $address = $server->listen($host, $port);

        // y-protocols expires presence thirty seconds after its last message
        // and checks every three. Hocuspocus inherits that timer through its
        // Awareness instance; this daemon has to wind it by hand.
        $sweep = Loop::addPeriodicTimer(3.0, fn () => $hub->expireAwareness());

        // The debounced writes: every second, any document whose quiet or
        // max-wait timer has run out is written back. The check itself only
        // walks an in-memory map, so the cadence can be tighter than the
        // shortest timer it enforces.
        $flush = Loop::addPeriodicTimer(1.0, fn () => $hub->flushDocuments());

        $this->components->info("Collaboration server listening on {$address}");
        $this->components->twoColumnDetail(
            'Clients connect with',
            $server->isSecure() ? 'wss:// (this server terminates TLS)' : 'ws:// (no TLS here)',
        );
        $this->components->twoColumnDetail('Compatibility', CompatibilityProfile::one()->describe());
        $this->newLine();

        // Stop accepting, then let the loop drain what is already in flight,
        // rather than dropping connections mid-frame. The dirty documents are
        // flushed after the loop returns, when nothing can dirty them again.
        $stop = function (string $signal) use ($server, $sweep, $flush): void {
            $this->newLine();
            $this->components->info("Received {$signal}, draining…");

            $server->stop();
            Loop::cancelTimer($sweep);
            Loop::cancelTimer($flush);

            Loop::addTimer(1.0, fn () => Loop::stop());
        };

        if (defined('SIGINT')) {
            Loop::addSignal(SIGINT, fn () => $stop('SIGINT'));
            Loop::addSignal(SIGTERM, fn () => $stop('SIGTERM'));
        }

        Loop::run();

        $server->stop();

        // Every in-flight frame has been handled once the loop returns, so
        // what is dirty now is final. A store that is down right now gets two
        // more tries a second apart — enough for a blink, and a client that
        // saw the edits will hand them back on reconnect if the store stays
        // down beyond that.
        $stillDirty = $hub->drainDocuments();

        for ($attempt = 0; $stillDirty > 0 && $attempt < 2; $attempt++) {
            sleep(1);
            $stillDirty = $hub->drainDocuments();
        }

        if ($stillDirty > 0) {
            $this->components->error(
                "{$stillDirty} document(s) could not be written to the store; ".
                'their latest changes return when a client that holds them reconnects.',
            );

            return self::FAILURE;
        }

        $this->components->info('Collaboration server stopped.');

        return self::SUCCESS;
    }
}
