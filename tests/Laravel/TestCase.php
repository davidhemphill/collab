<?php

declare(strict_types=1);

namespace Hemp\Collab\Tests\Laravel;

use Hemp\Collab\Laravel\CollabServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * A host application, small enough to see all of.
 *
 * Testbench does not run composer's package discovery, so the provider is named
 * here. That the shipped composer.json names the same class is asserted
 * separately — it is a different claim, and the one that actually breaks.
 */
abstract class TestCase extends Testbench
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CollabServiceProvider::class];
    }
}
