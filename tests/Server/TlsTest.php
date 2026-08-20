<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Certificate;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Collab\Server\SocketServer;
use Hemp\Collab\Server\TlsContext;
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

it('does not ask a browser for a client certificate', function () {
    // PHP's verify_peer default was written for clients. On a server it turns
    // the handshake into a CertificateRequest, which Chrome answers on a
    // WebSocket by failing the connection — so a daemon that worked in every
    // curl probe fails in every browser. Explicit configuration still wins.
    $context = TlsContext::resolve([
        'local_cert' => __FILE__,
        'local_pk' => __FILE__,
    ]);

    expect($context['verify_peer'])->toBeFalse();
});

it('leaves an explicit mutual-TLS choice alone', function () {
    $context = TlsContext::resolve([
        'local_cert' => __FILE__,
        'local_pk' => __FILE__,
        'verify_peer' => true,
    ]);

    expect($context['verify_peer'])->toBeTrue();
});

it('answers a wss client with no proxy in front of it', function () {
    $path = selfSignedCertificate();

    $hub = new Hub(new SharedSessionFactory(authenticatorGranting(Scope::ReadWrite), memoryStore()));
    $server = new SocketServer($hub, tls: ['local_cert' => $path]);

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

it('refuses to resolve rather than failing at the first handshake', function () {
    // A path that does not resolve otherwise shows up as a TLS error in a
    // browser console and nothing at all in the server log.
    expect(fn () => TlsContext::resolve(['local_cert' => '/nowhere/missing.pem']))
        ->toThrow(RuntimeException::class, 'does not exist or cannot be read');
});

it('decides on TLS from the certificate rather than a separate switch', function () {
    // Reverb's rule. A boolean beside the certificate is one more thing that
    // can disagree with it.
    expect(TlsContext::secures([]))->toBeFalse()
        ->and(TlsContext::secures(['local_cert' => '/tmp/c.pem']))->toBeTrue()
        ->and(TlsContext::secures(['local_pk' => '/tmp/k.pem']))->toBeTrue()
        ->and(TlsContext::secures(['verify_peer' => true]))->toBeFalse();
});

it('drops empty configuration rather than treating it as a path', function () {
    // Unset environment variables arrive as null or an empty string, and either
    // one would otherwise turn TLS on and then fail to find the file.
    expect(TlsContext::resolve(['local_cert' => null, 'local_pk' => '']))->toBe([])
        ->and(TlsContext::secures(TlsContext::resolve(['local_cert' => null])))->toBeFalse();
});

it('passes through any option PHP understands', function () {
    $path = selfSignedCertificate();

    $context = TlsContext::resolve([
        'local_cert' => $path,
        'verify_peer' => false,
        'ciphers' => 'HIGH:!aNULL',
    ]);

    expect($context)->toBe([
        'local_cert' => $path,
        'verify_peer' => false,
        'ciphers' => 'HIGH:!aNULL',
    ]);

    @unlink($path);
});

it('finds a local certificate from the hostname alone', function () {
    // Herd and Valet issue one per secured site. A developer who has already
    // secured their site should not have to name any paths.
    $host = 'collab-fixture-'.getmypid().'.test';
    $directory = Certificate::herdPath();

    if (! is_dir($directory)) {
        test()->markTestSkipped('No Herd certificate directory on this machine.');
    }

    $certificate = $directory.$host.'.crt';
    $key = $directory.$host.'.key';
    file_put_contents($certificate, 'x');
    file_put_contents($key, 'x');

    try {
        expect(Certificate::exists($host))->toBeTrue()
            ->and(Certificate::resolve("https://{$host}"))->toBe([$certificate, $key]);

        $context = TlsContext::resolve([], $host);

        expect($context['local_cert'])->toBe($certificate)
            ->and($context['local_pk'])->toBe($key)
            ->and(TlsContext::secures($context))->toBeTrue();
    } finally {
        @unlink($certificate);
        @unlink($key);
    }
});

it('leaves a configured certificate alone rather than replacing it', function () {
    $path = selfSignedCertificate();

    $context = TlsContext::resolve(['local_cert' => $path], 'anything.test');

    expect($context['local_cert'])->toBe($path);

    @unlink($path);
});

it('stays plain when the hostname has no certificate', function () {
    expect(TlsContext::resolve([], 'no-certificate-for-this.test'))->toBe([]);
});
