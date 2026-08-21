<?php

declare(strict_types=1);

namespace Hemp\Collab\Laravel;

use Hemp\Collab\Laravel\Console\StartCommand;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Server\Authenticator;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\ResidentDocuments;
use Hemp\Collab\Server\ResidentStore;
use Hemp\Collab\Server\SessionFactory;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Yjs\Protocol\Awareness\AwarenessLimits;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Wires the collaboration server into a Laravel application.
 *
 * Deferred, because nothing here is needed until the daemon starts or an
 * artisan command runs — an application serving HTTP requests should not pay
 * for a WebSocket server it is not running.
 *
 * The two application seams are resolved from config by class name so that a
 * host can bind them itself and leave the config keys empty.
 */
class CollabServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/collab.php', 'collab');

        $this->app->singleton(Authenticator::class, function ($app) {
            return $app->make($this->requiredClass('authenticator', Authenticator::class));
        });

        $this->app->singleton(DocumentStore::class, function ($app) {
            return $app->make($this->requiredClass('store', DocumentStore::class));
        });

        $this->app->singleton(AwarenessLimits::class, fn ($app) => new AwarenessLimits(
            maxClientsPerUpdate: (int) $app['config']->get('collab.limits.awareness_clients'),
            maxStateBytes: (int) $app['config']->get('collab.limits.awareness_state_bytes'),
        ));

        // The resident layer decorating the host's store: the document is
        // loaded once per open, merged in memory per keystroke, and written
        // back on a debounce. One instance, shared by the session factory
        // (which uses it as the store) and the hub (which drives its flush
        // and unload lifecycle) — two instances would be two truths.
        $this->app->singleton(ResidentStore::class, fn ($app) => new ResidentStore(
            $app->make(DocumentStore::class),
            quietSeconds: (float) $app['config']->get('collab.persistence.quiet_seconds', 2.0),
            maxWaitSeconds: (float) $app['config']->get('collab.persistence.max_wait_seconds', 10.0),
            log: $app->make(LoggerInterface::class),
        ));

        $this->app->singleton(SessionFactory::class, fn ($app) => new SharedSessionFactory(
            $app->make(Authenticator::class),
            $app->make(ResidentStore::class),
        ));

        // Both halves of the same policy: the reader refuses an oversized
        // awareness update off the wire, and the store refuses to keep one.
        $this->app->singleton(Hub::class, fn ($app) => new Hub(
            $app->make(SessionFactory::class),
            new FrameReader(awarenessLimits: $app->make(AwarenessLimits::class)),
            $app->make(LoggerInterface::class),
            new ResidentDocuments($app->make(AwarenessLimits::class)),
            $app->make(ResidentStore::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/collab.php' => $this->app->configPath('collab.php'),
            ], 'collab-config');

            $this->commands([StartCommand::class]);
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            Authenticator::class,
            DocumentStore::class,
            AwarenessLimits::class,
            ResidentStore::class,
            SessionFactory::class,
            Hub::class,
        ];
    }

    /**
     * Read a class name out of config, failing with something actionable.
     *
     * A missing binding here is the most likely first-run mistake, and the
     * default error — "target is not instantiable" — says nothing about which
     * config key to set.
     */
    private function requiredClass(string $key, string $contract): string
    {
        $class = $this->app['config']->get("collab.{$key}");

        if (! is_string($class) || $class === '') {
            throw new RuntimeException(
                "collab.{$key} is not configured. Set it to a class implementing {$contract}, ".
                "or bind {$contract} in a service provider."
            );
        }

        return $class;
    }
}
