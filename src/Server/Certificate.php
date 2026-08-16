<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

/**
 * Finds a local development certificate for a hostname.
 *
 * Herd and Valet both issue a certificate per secured site and put it in a
 * known directory, so a developer who has already secured their site does not
 * have to tell this package where the files are.
 *
 * This mirrors Laravel\Reverb\Certificate deliberately. Reverb solved the same
 * problem first, an application will often run both, and two servers that
 * disagree about where certificates live would be a needless thing to debug.
 */
final class Certificate
{
    private function __construct() {}

    public static function exists(string $url): bool
    {
        return self::resolve($url) !== null;
    }

    /**
     * The certificate and key for a hostname, if both are present.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function resolve(string $url): ?array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        foreach (self::paths() as $path) {
            $certificate = $path.$host.'.crt';
            $key = $path.$host.'.key';

            if (file_exists($certificate) && file_exists($key)) {
                return [$certificate, $key];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return [self::herdPath(), self::valetPath()];
    }

    public static function herdPath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return implode(DIRECTORY_SEPARATOR, [
                getenv('USERPROFILE') ?: $_SERVER['HOME'] ?? '',
                '.config', 'herd', 'config', 'valet', 'Certificates', '',
            ]);
        }

        return implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? '',
            'Library', 'Application Support', 'Herd', 'config', 'valet', 'Certificates', '',
        ]);
    }

    public static function valetPath(): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? '', '.config', 'valet', 'Certificates', '',
        ]);
    }
}
