<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use RuntimeException;

/**
 * The certificate the server presents, so it can speak wss:// on its own.
 *
 * A reverse proxy is the usual way to terminate TLS, and it is still the better
 * one when you have somewhere to put it. But on a platform that does not let
 * you configure a proxy — Laravel Cloud, and most managed hosts — there is
 * nowhere to put it, and a collaboration server that cannot serve wss:// is
 * unreachable from any page served over https.
 *
 * So this is not an optimisation. It is what makes the package deployable.
 */
final class TlsCertificate
{
    public function __construct(
        public readonly string $certificate,
        public readonly ?string $privateKey = null,
        public readonly ?string $passphrase = null,
    ) {}

    /**
     * Build from configuration, or null when TLS was not asked for.
     *
     * @param  array{certificate?: ?string, key?: ?string, passphrase?: ?string}  $config
     */
    public static function fromConfig(array $config): ?self
    {
        $certificate = $config['certificate'] ?? null;

        if (! is_string($certificate) || $certificate === '') {
            return null;
        }

        return new self(
            $certificate,
            ($config['key'] ?? null) ?: null,
            ($config['passphrase'] ?? null) ?: null,
        );
    }

    /**
     * Fail before binding rather than on the first connection.
     *
     * A missing file here produces a TLS handshake error in a browser console
     * and nothing at all in the server log, which is a miserable thing to
     * diagnose. The server refuses to start instead.
     */
    public function verify(): void
    {
        foreach (array_filter([$this->certificate, $this->privateKey]) as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException(
                    "The TLS file [{$path}] does not exist or cannot be read by the user running the server."
                );
            }
        }
    }

    /**
     * The stream context PHP wants.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $tls = ['local_cert' => $this->certificate];

        if ($this->privateKey !== null) {
            $tls['local_pk'] = $this->privateKey;
        }

        if ($this->passphrase !== null) {
            $tls['passphrase'] = $this->passphrase;
        }

        return $tls;
    }
}
