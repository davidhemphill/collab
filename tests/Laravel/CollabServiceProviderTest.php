<?php

declare(strict_types=1);

use Hemp\Collab\Laravel\CollabServiceProvider;
use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\Authenticator;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SessionFactory;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Collab\Tests\Laravel\TestCase;
use Hemp\Yjs\Update\Update;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The wiring into a host application.
 *
 * Everything below this is framework-free by design, so this is the only place
 * that knows Laravel exists — and the only place where a mistake shows up as a
 * daemon that will not boot rather than a failing assertion.
 */
uses(TestCase::class)->in(__DIR__);

/**
 * @return array{0: class-string, 1: class-string}
 */
function hostBindings(): array
{
    return [HostAuthenticator::class, HostStore::class];
}

class HostAuthenticator implements Authenticator
{
    public function authenticate(string $documentName, string $token): Authenticated
    {
        return new Authenticated(Scope::ReadWrite, identity: 'host');
    }
}

class HostStore implements DocumentStore
{
    public function load(string $documentName): Update
    {
        return Update::empty();
    }

    public function store(string $documentName, Update $update): void {}
}

beforeEach(function () {
    [$authenticator, $store] = hostBindings();

    config()->set('collab.authenticator', $authenticator);
    config()->set('collab.store', $store);
});

it('names a real provider for package discovery', function () {
    // Testbench registers the provider by hand, so nothing else in this file
    // would notice if the shipped composer.json pointed at a class that moved.
    $declared = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($declared['extra']['laravel']['providers'])->toBe([CollabServiceProvider::class])
        ->and(class_exists(CollabServiceProvider::class))->toBeTrue();
});

it('is loaded into the host application', function () {
    expect($this->app->getLoadedProviders())->toHaveKey(CollabServiceProvider::class);
});

it('resolves the seams the host named in config', function () {
    expect($this->app->make(Authenticator::class))->toBeInstanceOf(HostAuthenticator::class)
        ->and($this->app->make(DocumentStore::class))->toBeInstanceOf(HostStore::class)
        ->and($this->app->make(SessionFactory::class))->toBeInstanceOf(SharedSessionFactory::class);
});

it('shares one hub across the application', function () {
    // Two hubs would mean two connection registries, and a client would only
    // ever hear from the peers that happened to land on the same one.
    expect($this->app->make(Hub::class))->toBe($this->app->make(Hub::class));
});

it('builds sessions that carry the host policy', function () {
    $session = ($this->app->make(SessionFactory::class))('4711');

    $session->receive(new AddressedFrame(
        '4711',
        Authentication::token('anything'),
    ));

    expect($session->identity())->toBe('host')
        ->and($session->scope())->toBe(Scope::ReadWrite);
});

it('says which config key is missing rather than failing as a container error', function () {
    // An unconfigured seam is the likeliest first-run mistake, and "target is
    // not instantiable" names an interface the reader has never heard of.
    config()->set('collab.authenticator', null);

    expect(fn () => $this->app->make(Authenticator::class))
        ->toThrow(RuntimeException::class, 'collab.authenticator is not configured');
});

it('publishes a config file that exists', function () {
    $published = ServiceProvider::pathsToPublish(CollabServiceProvider::class, 'collab-config');

    expect($published)->not->toBeEmpty();

    foreach (array_keys($published) as $source) {
        expect(file_exists($source))->toBeTrue("Publishable config is missing: {$source}");
        expect(require $source)->toBeArray();
    }
});

it('registers the start command', function () {
    expect(array_keys($this->app[Kernel::class]->all()))
        ->toContain('collab:start');
});

it('defers until something actually needs the server', function () {
    // An application serving HTTP should not build a collaboration hub it is
    // never going to use.
    $provider = new CollabServiceProvider($this->app);

    expect($provider)->toBeInstanceOf(DeferrableProvider::class)
        ->and($provider->provides())->toContain(Hub::class);
});
