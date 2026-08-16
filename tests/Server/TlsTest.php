<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Collab\Server\SocketServer;
use Hemp\Collab\Server\TlsCertificate;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * The server terminating TLS on its own.
 *
 * A reverse proxy is the better answer when there is somewhere to put one. On a
 * managed platform there is not, and a page served over https cannot open a
 * plain ws:// connection to anything but localhost — so without this the
 * package is undeployable rather than merely inconvenient.
 */

/** A self-signed certificate, written for one test and thrown away. */
function selfSignedCertificate(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $certificate = openssl_csr_sign(
        openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']),
        null,
        $key,
        days: 1,
        options: ['digest_alg' => 'sha256'],
    );

    openssl_x509_export($certificate, $publicPem);
    openssl_pkey_export($key, $privatePem);

    $path = tempnam(sys_get_temp_dir(), 'collab-tls-').'.pem';

    // One file holding both, which is what local_cert accepts when local_pk is
    // not given separately — and the shape most certificate providers hand you.
    file_put_contents($path, $publicPem.$privatePem);

    return $path;
}

it('answers a wss client with no proxy in front of it', function () {
    $path = selfSignedCertificate();

    $hub = new Hub(new SharedSessionFactory(authenticatorGranting(Scope::ReadWrite), memoryStore()));
    $server = new SocketServer($hub, tls: new TlsCertificate($path));

    expect($server->isSecure())->toBeTrue();

    $address = $server->listen('127.0.0.1', 0);

    expect($address)->toStartWith('tls://');

    $reply = null;

    // The certificate is self-signed, so the client is told not to verify it.
    // A real client verifies; this test is about the server's half.
    $connector = new Connector([
        'tls' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $connector->connect($address)->then(function (ConnectionInterface $socket) use ($address, &$reply) {
        $buffer = '';
        $upgraded = false;

        $socket->on('data', function (string $chunk) use (&$buffer, &$upgraded, $socket, &$reply) {
            $buffer .= $chunk;

            if (! $upgraded) {
                if (! str_contains($buffer, "\r\n\r\n")) {
                    return;
                }

                $upgraded = true;
                $buffer = '';

                $socket->write(clientFrame(
                    (new AddressedFrame('4711', Authentication::token('good')))->encode(),
                ));

                return;
            }

            $reply = (new FrameReader)->read(substr($buffer, 2));

            $socket->close();
            Loop::stop();
        });

        $socket->write(handshakeFor(str_replace('tls://', '', $address)));
    });

    until(function () use (&$reply) {
        return $reply !== null;
    });

    $server->stop();
    @unlink($path);

    expect($reply)->not->toBeNull('The TLS server never completed a handshake.')
        ->and($reply->message)->toBeInstanceOf(Authentication::class)
        ->and($reply->message->scope)->toBe(Scope::ReadWrite);
});

it('stays plain when no certificate is given', function () {
    $hub = new Hub(new SharedSessionFactory(authenticatorGranting(Scope::ReadWrite), memoryStore()));
    $server = new SocketServer($hub);

    $address = $server->listen('127.0.0.1', 0);

    expect($server->isSecure())->toBeFalse()
        ->and($address)->toStartWith('tcp://');

    $server->stop();
});

it('refuses to start rather than failing at the first handshake', function () {
    // A missing certificate otherwise shows up as a TLS error in a browser
    // console and nothing at all in the server log.
    $hub = new Hub(new SharedSessionFactory(authenticatorGranting(Scope::ReadWrite), memoryStore()));
    $server = new SocketServer($hub, tls: new TlsCertificate('/nowhere/missing.pem'));

    expect(fn () => $server->listen('127.0.0.1', 0))
        ->toThrow(RuntimeException::class, 'does not exist or cannot be read');
});

it('reads a certificate and a separate key out of configuration', function () {
    $tls = TlsCertificate::fromConfig([
        'certificate' => '/tmp/cert.pem',
        'key' => '/tmp/key.pem',
        'passphrase' => 'hunter2',
    ]);

    expect($tls->context())->toBe([
        'local_cert' => '/tmp/cert.pem',
        'local_pk' => '/tmp/key.pem',
        'passphrase' => 'hunter2',
    ]);
});

it('is off unless a certificate is named', function () {
    expect(TlsCertificate::fromConfig([]))->toBeNull()
        ->and(TlsCertificate::fromConfig(['certificate' => '']))->toBeNull()
        ->and(TlsCertificate::fromConfig(['certificate' => null]))->toBeNull();
});
