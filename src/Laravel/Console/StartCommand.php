<?php

declare(strict_types=1);

namespace Hemp\Collab\Laravel\Console;

use Hemp\Collab\Protocol\CompatibilityProfile;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SocketServer;
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

        $server = new SocketServer($hub, maxFrameBytes: (int) config('collab.limits.frame_bytes'));

        $address = $server->listen($host, $port);

        $this->components->info("Collaboration server listening on {$address}");
        $this->components->twoColumnDetail('Profile', CompatibilityProfile::one()->describe());
        $this->newLine();

        // Stop accepting, then let the loop drain what is already in flight,
        // rather than dropping connections mid-frame. Persistence happens per
        // accepted update today, so there is no dirty state to flush — when the
        // store becomes debounced, the flush belongs here.
        $stop = function (string $signal) use ($server): void {
            $this->newLine();
            $this->components->info("Received {$signal}, draining…");

            $server->stop();

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
