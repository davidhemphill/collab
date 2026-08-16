<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use RuntimeException;

/**
 * The TLS stream context the server listens with.
 *
 * A page served over https cannot open a plain ws:// connection to anything but
 * 127.0.0.1. A reverse proxy is the usual way to provide wss://, but on a
 * platform that does not let you configure one — Laravel Cloud, and most
 * managed hosts — there is nowhere to put a proxy, and a server that cannot
 * terminate TLS cannot be reached at all.
 *
 * The shape here follows Reverb: raw PHP SSL context keys rather than names of
 * our own, so anything PHP accepts can be set, and whether TLS is on is decided
 * by whether a certificate is present rather than by a separate switch that can
 * disagree with it.
 */
final class TlsContext
{
    private function __construct() {}

    /**
     * Fill in a local certificate when one was not configured but exists.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function resolve(array $context, ?string $hostname = null): array
    {
        $context = array_filter($context, fn ($value) => $value !== null && $value !== '');

        if (! self::secures($context) && $hostname && Certificate::exists($hostname)) {
            [$certificate, $key] = Certificate::resolve($hostname);

            $context['local_cert'] = $certificate;
            $context['local_pk'] = $key;
        }

        self::verify($context);

        return $context;
    }

    /**
     * Whether this context makes the server speak wss://.
     *
     * @param  array<string, mixed>  $context
     */
    public static function secures(array $context): bool
    {
        return (bool) ($context['local_cert'] ?? false) || (bool) ($context['local_pk'] ?? false);
    }

    /**
     * Fail before binding rather than on the first connection.
     *
     * A path that does not resolve otherwise shows up as a handshake error in a
     * browser console and nothing at all in the server log.
     *
     * @param  array<string, mixed>  $context
     */
    private static function verify(array $context): void
    {
        foreach (['local_cert', 'local_pk', 'cafile'] as $key) {
            $path = $context[$key] ?? null;

            if (is_string($path) && $path !== '' && ! is_readable($path)) {
                throw new RuntimeException(
                    "The TLS file [{$path}] configured as [{$key}] does not exist or cannot be read ".
                    'by the user running the server.'
                );
            }
        }
    }
}
