<?php

declare(strict_types=1);

namespace Collab\Tests\Support;

use RuntimeException;

/**
 * Reads the committed provider transcripts.
 */
final class Transcripts
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $path = __DIR__.'/../../fixtures/profile-1/provider-transcripts.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Missing transcripts: {$path}. Run `composer fixtures`.");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every frame, keyed by name so a failure names the frame.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (self::load()['cases'] as $case) {
            $cases[$case['name']] = $case;
        }

        return $cases;
    }

    public static function bytes(string $name): string
    {
        return base64_decode(self::cases()[$name]['bytes'], strict: true);
    }
}
