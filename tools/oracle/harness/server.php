<?php

declare(strict_types=1);

/**
 * The PHP daemon, run the way the differential needs it.
 *
 * Deliberately the smallest host application that is still one: an
 * authenticator that admits everyone at a fixed scope, and a store that keeps
 * documents in memory. Anything more would mean the differential was comparing
 * two host applications rather than two servers.
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\Authenticator;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Collab\Server\SocketServer;
use Hemp\Yjs\Update\Update;
use React\EventLoop\Loop;

$scope = Scope::from(getenv('SCOPE') ?: 'read-write');
$port = (int) (getenv('PORT') ?: 0);

$authenticator = new class($scope) implements Authenticator
{
    public function __construct(private Scope $scope) {}

    public function authenticate(string $documentName, string $token): Authenticated
    {
        return new Authenticated($this->scope, identity: $token);
    }
};

$store = new class implements DocumentStore
{
    /** @var array<string, Update> */
    private array $documents = [];

    public function load(string $documentName): Update
    {
        return $this->documents[$documentName] ?? Update::empty();
    }

    public function store(string $documentName, Update $update): void
    {
        $this->documents[$documentName] = $update;
    }
};

$server = new SocketServer(new Hub(new SharedSessionFactory($authenticator, $store)));
$address = str_replace('tcp://', '', $server->listen('127.0.0.1', $port));

fwrite(STDERR, "LISTENING {$address}\n");

Loop::run();
