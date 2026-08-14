# collab

A Yjs collaboration server for Laravel applications, speaking the Hocuspocus
provider protocol, running entirely in PHP.

> **Status: the protocol layer.** The frames a provider exchanges are
> implemented and tested. The daemon that carries them — event loop, resident
> documents, persistence — is not built yet.

The Node sidecar this is meant to replace lives in its own repository and is
still what runs in production. It does not go away until the PHP path passes
the production gate.

## What is here

```text
src/Protocol/       the Hocuspocus provider frames
src/Server/         the session state machine and its seams
tools/oracle/       transcript generation, pinned to Profile 1
fixtures/profile-1/ committed transcripts, so the PHP suite runs without Node
```

The Yjs binary format itself — updates, merging, diffing, sync and awareness
codecs — lives in [hemp/yjs](../yjs-php) and is consumed as a dependency. That
split is deliberate: hemp/yjs knows nothing about WebSockets, Laravel, or
Hocuspocus, and this package adds no CRDT logic of its own.

## The frame format

Every WebSocket frame is one message, addressed to a document:

```text
varString  document name
varUint    message type
...        payload, per type
```

A provider multiplexes every document it has open over one socket, so the
address is the only thing that says which resident document a message belongs
to.

| Type | Message | Carries |
|---:|---|---|
| 0 | Sync | a y-protocols sync message |
| 1 | Awareness | a y-protocols awareness update |
| 2 | Auth | a token request, a token, a refusal, or a granted scope |
| 3 | QueryAwareness | nothing |
| 5 | Stateless | an application-defined string |
| 7 | Close | nothing |
| 8 | SyncStatus | whether the last update was accepted |

4 and 6 are unassigned. The reader rejects them rather than assuming the
numbering is dense, since a later provider version could fill them in.

### Two details that are easy to get wrong

**`Auth` type 0 means two different things.** From the server it is a bare
request — "send me your token" — with nothing after it. From the client it is
the answer, with the token appended. Only the presence of a payload separates
them, which is sound because a frame carries exactly one message.

**`SyncStatus` carries a *signed* varInt.** The provider reads it with
`readVarInt`. Writing it unsigned produces identical bytes for 0 and 1, so the
mistake would hold until it didn't.

## Reading untrusted frames

`FrameReader` is the outermost surface facing the network, and every frame
reaching it may come from a socket that has not authenticated yet. It inherits
yjs-php's bounded decoder: declared lengths are checked before anything is
allocated, and every failure is a typed `Hemp\Yjs\Exception\DecodeException` a
connection handler can catch in one place. The suite asserts that over every
truncation and random corruption of every committed transcript.

## Development

```bash
composer install                  # hemp/yjs is a path dependency of ../yjs-php
composer test

npm --prefix tools/oracle ci      # only needed to touch the transcripts
composer fixtures
```

### About the transcripts

The committed frames are assembled from `@hocuspocus/common`, `y-protocols`,
and lib0, following the frame construction in each of the provider's
`OutgoingMessage` classes. They are **not** captured from a running provider:
the provider does not export those classes, and driving a live one needs a
server to connect to.

That is a real limitation. These transcripts prove the reader agrees with the
protocol as written; they cannot prove the provider does what its source says.
Closing that gap is the next phase's exit gate — an unmodified provider reaching
synced state against the daemon — and it belongs there, where there is something
for it to connect to.

## Compatibility

Profile 1 is `@hocuspocus/provider` 3.4.4, yjs 13.6.29, y-protocols 1.0.7, and
lib0 0.2.117, named in `CompatibilityProfile` so a version bump is a decision
somebody makes rather than a dependency that drifted.

Provider v4 is a later profile. It changes enough to need its own entry —
session-aware addresses, an optional version field in authentication, bare
one-byte ping frames — and a v4 client is expected to fail visibly here rather
than half-work.
