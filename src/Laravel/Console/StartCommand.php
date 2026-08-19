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

        $this->components->info("Collaboration server listening on {$address}");
        $this->components->twoColumnDetail(
            'Clients connect with',
            $server->isSecure() ? 'wss:// (this server terminates TLS)' : 'ws:// (no TLS here)',
        );
        $this->components->twoColumnDetail('Compatibility', CompatibilityProfile::one()->describe());
        $this->newLine();

        // Stop accepting, then let the loop drain what is already in flight,
        // rather than dropping connections mid-frame. Persistence happens per
        // accepted update today, so there is no dirty state to flush — when the
        // store becomes debounced, the flush belongs here.
        $stop = function (string $signal) use ($server, $sweep): void {
            $this->newLine();
            $this->components->info("Received {$signal}, draining…");

            $server->stop();
            Loop::cancelTimer($sweep);

            Loop::addTimer(1.0, fn () => Loop::stop());
        };

        if (defined('SIGINT')) {
            Loop::addSignal(SIGINT, fn () => $stop('SIGINT'));
            Loop::addSignal(SIGTERM, fn () => $stop('SIGTERM'));
        }

        Loop::run();

        $server->stop();

        $this->components->info('Collaboration server stopped.');

        return self::SUCCESS;
    }
}
