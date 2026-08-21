<?php

declare(strict_types=1);

namespace Hemp\Collab\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory;

/**
 * Ask the running daemon to drain and exit, wherever it is.
 *
 * The same arrangement as queue:restart and reverb:restart: this command
 * writes a timestamp into the cache, the daemon notices it on its next poll,
 * finishes what it owes the store, and exits cleanly — and the process
 * supervisor that keeps the daemon alive starts a fresh one on the new code.
 * Nothing here touches a process, a PID, or the supervisor itself, which is
 * what lets a deploy script restart a daemon it cannot see.
 *
 * The two processes must share a cache store (database, redis, file — not
 * array, which lives and dies with one process).
 */
class RestartCommand extends Command
{
    public const CACHE_KEY = 'hemp:collab:restart';

    protected $signature = 'collab:restart';

    protected $description = 'Signal the collaboration server to drain and exit, so its supervisor restarts it fresh';

    public function handle(Factory $cache): int
    {
        $cache->store()->forever(self::CACHE_KEY, microtime(true));

        $this->components->info(
            'Restart signal sent. The server will finish its writes and exit; its supervisor starts the fresh one.',
        );

        return self::SUCCESS;
    }
}
