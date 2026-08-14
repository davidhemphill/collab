<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\AuthMessageType;
use Hemp\Collab\Protocol\CompatibilityProfile;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\Close;
use Hemp\Collab\Protocol\Message\QueryAwareness;
use Hemp\Collab\Protocol\Message\Stateless;
use Hemp\Collab\Protocol\Message\SyncStatus;
use Hemp\Collab\Protocol\MessageType;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Tests\Support\Transcripts;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Exception\MalformedInput;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Protocol\Sync\SyncUpdate;

$cases = array_map(fn (array $case) => [$case], Transcripts::cases());
$reader = new FrameReader;

it('reads every frame in the transcript', function (array $case) use ($reader) {
    $frame = $reader->read(base64_decode($case['bytes'], strict: true));

    expect($frame->documentName)->toBe($case['documentName'])
        ->and($frame->type()->value)->toBe($case['type']);
})->with($cases);

it('re-encodes every frame to the identical bytes', function (array $case) use ($reader) {
    $bytes = base64_decode($case['bytes'], strict: true);

    expect($reader->read($bytes)->encode())->toBeBytes($bytes);
})->with($cases);

describe('message shapes', function () use ($reader) {
    it('reads the client token', function () use ($reader) {
        $message = $reader->read(Transcripts::bytes('auth-token'))->message;

        expect($message)->toBeInstanceOf(Authentication::class)
            ->and($message->authType)->toBe(AuthMessageType::Token)
            ->and($message->token)->toBe(Transcripts::load()['token'])
            ->and($message->isTokenRequest())->toBeFalse();
    });

    it('tells a bare token request apart from a token', function () use ($reader) {
        // Same type number, different meaning, separated only by whether
        // anything follows it.
        $message = $reader->read(Transcripts::bytes('auth-token-request'))->message;

        expect($message->authType)->toBe(AuthMessageType::Token)
            ->and($message->token)->toBeNull()
            ->and($message->isTokenRequest())->toBeTrue();
    });

    it('reads both granted scopes', function () use ($reader) {
        expect($reader->read(Transcripts::bytes('auth-authenticated-readwrite'))->message->scope)
            ->toBe(Scope::ReadWrite)
            ->and($reader->read(Transcripts::bytes('auth-authenticated-readonly'))->message->scope)
            ->toBe(Scope::ReadOnly);
    });

    it('reads a refusal and its reason', function () use ($reader) {
        $message = $reader->read(Transcripts::bytes('auth-permission-denied'))->message;

        expect($message->authType)->toBe(AuthMessageType::PermissionDenied)
            ->and($message->reason)->toBe('You may not open this document.');
    });

    it('reads each sync message as its own type', function () use ($reader) {
        expect($reader->read(Transcripts::bytes('sync-step1'))->message->message)
            ->toBeInstanceOf(SyncStep1::class)
            ->and($reader->read(Transcripts::bytes('sync-step2'))->message->message)
            ->toBeInstanceOf(SyncStep2::class)
            ->and($reader->read(Transcripts::bytes('sync-update'))->message->message)
            ->toBeInstanceOf(SyncUpdate::class);
    });

    it('reads a sync step two as an update it can decode', function () use ($reader) {
        $sync = $reader->read(Transcripts::bytes('sync-step2'))->message;

        expect($sync->message->update()->structCount())->toBeGreaterThan(0);
    });

    it('reads awareness as a decoded update', function () use ($reader) {
        $message = $reader->read(Transcripts::bytes('awareness'))->message;

        expect($message)->toBeInstanceOf(Awareness::class)
            ->and($message->update->entries)->toHaveCount(1)
            ->and($message->update->entries[0]->client)->toBe(900)
            ->and($message->update->entries[0]->state)->toContain('Ada');
    });

    it('reads the messages that carry nothing', function () use ($reader) {
        expect($reader->read(Transcripts::bytes('query-awareness'))->message)
            ->toBeInstanceOf(QueryAwareness::class)
            ->and($reader->read(Transcripts::bytes('close'))->message)
            ->toBeInstanceOf(Close::class);
    });

    it('reads a stateless payload verbatim', function () use ($reader) {
        $message = $reader->read(Transcripts::bytes('stateless'))->message;

        expect($message)->toBeInstanceOf(Stateless::class)
            ->and($message->payload)->toBe('{"kind":"ping","n":1}');
    });

    it('reads both sync statuses', function () use ($reader) {
        // The flag is a signed varInt, which is easy to write unsigned by
        // accident since 0 and 1 encode the same either way.
        expect($reader->read(Transcripts::bytes('sync-status-accepted'))->message->applied)->toBeTrue()
            ->and($reader->read(Transcripts::bytes('sync-status-rejected'))->message->applied)->toBeFalse();
    });
});

describe('writing', function () use ($reader) {
    it('produces the bytes the provider expects for each server frame', function (string $name, callable $build) {
        $expected = Transcripts::bytes($name);

        expect((new AddressedFrame('4711', $build()))->encode())->toBeBytes($expected);
    })->with([
        'token request' => ['auth-token-request', fn () => Authentication::tokenRequest()],
        'granted read-write' => ['auth-authenticated-readwrite', fn () => Authentication::authenticated(Scope::ReadWrite)],
        'granted readonly' => ['auth-authenticated-readonly', fn () => Authentication::authenticated(Scope::ReadOnly)],
        'refused' => ['auth-permission-denied', fn () => Authentication::permissionDenied('You may not open this document.')],
        'status accepted' => ['sync-status-accepted', fn () => SyncStatus::accepted()],
        'status rejected' => ['sync-status-rejected', fn () => SyncStatus::rejected()],
        'query awareness' => ['query-awareness', fn () => new QueryAwareness],
        'close' => ['close', fn () => new Close],
    ]);

    it('round-trips a frame it built itself', function () use ($reader) {
        $frame = new AddressedFrame('doc 😀 name', new Stateless('{"a":1}'));

        $read = $reader->read($frame->encode());

        expect($read->documentName)->toBe('doc 😀 name')
            ->and($read->message->payload)->toBe('{"a":1}');
    });
});

describe('malformed frames', function () use ($reader, $cases) {
    it('rejects an unassigned message type', function () use ($reader) {
        // 4 and 6 are gaps in this profile. Treating the numbering as dense
        // would let a later provider's message be misread as something else.
        //
        // All below 128, so each is a complete one-byte varUint. A larger
        // number would set the continuation bit and run out of input instead,
        // which is a truncation rather than an unknown type.
        foreach ([4, 6, 9, 100, 127] as $type) {
            $bytes = "\x03doc".chr($type);

            expect(fn () => $reader->read($bytes))->toThrow(MalformedInput::class);
        }
    });

    it('rejects an unknown authentication type', function () use ($reader) {
        expect(fn () => $reader->read("\x03doc\x02\x09"))->toThrow(MalformedInput::class);
    });

    it('rejects an unknown authorization scope', function () use ($reader) {
        // A scope we do not understand must not be treated as read-only by
        // default — it has to fail loudly.
        expect(fn () => $reader->read("\x03doc\x02\x02\x05admin"))->toThrow(MalformedInput::class);
    });

    it('rejects trailing bytes after a complete message', function () use ($reader) {
        expect(fn () => $reader->read(Transcripts::bytes('close')."\xFF"))
            ->toThrow(MalformedInput::class);
    });

    it('fails bounded on every truncation of every frame', function (array $case) use ($reader) {
        $bytes = base64_decode($case['bytes'], strict: true);

        for ($length = 0; $length < strlen($bytes); $length++) {
            try {
                $reader->read(substr($bytes, 0, $length));
            } catch (DecodeException) {
                continue;
            } catch (Throwable $unexpected) {
                throw new RuntimeException(sprintf(
                    '%s truncated to %d bytes leaked a %s: %s',
                    $case['name'],
                    $length,
                    $unexpected::class,
                    $unexpected->getMessage(),
                ), previous: $unexpected);
            }
        }

        expect(true)->toBeTrue();
    })->with($cases);

    it('fails bounded when a frame is corrupted', function (int $seed) use ($reader) {
        mt_srand($seed);

        $names = array_keys(Transcripts::cases());

        for ($iteration = 0; $iteration < 300; $iteration++) {
            $bytes = Transcripts::bytes($names[mt_rand(0, count($names) - 1)]);
            $position = mt_rand(0, strlen($bytes) - 1);
            $bytes[$position] = chr(mt_rand(0, 255));

            try {
                $reader->read($bytes);
            } catch (DecodeException) {
                continue;
            } catch (Throwable $unexpected) {
                throw new RuntimeException(sprintf(
                    'Corruption at offset %d leaked a %s: %s',
                    $position,
                    $unexpected::class,
                    $unexpected->getMessage(),
                ), previous: $unexpected);
            }
        }

        expect(true)->toBeTrue();
    })->with([1, 42, 1337, 20260813]);
});

it('names the profile it targets', function () {
    $profile = CompatibilityProfile::one();

    $packages = Transcripts::load()['packages'];

    expect($profile->provider)->toBe($packages['@hocuspocus/provider'])
        ->and($profile->yjs)->toBe($packages['yjs'])
        ->and($profile->yProtocols)->toBe($packages['y-protocols'])
        ->and($profile->lib0)->toBe($packages['lib0'])
        ->and($profile->messageTypes())->toBe(MessageType::cases());
});
