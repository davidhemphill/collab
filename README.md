# collab

A Yjs collaboration server for Laravel applications, speaking the Hocuspocus
provider protocol, running entirely in PHP.

An unmodified `@hocuspocus/provider` in the browser connects to `collab:start`
in your Laravel application. There is no Node process in the path.

> **Status: not in production anywhere.** The protocol, the session state
> machine, the daemon and the Laravel wiring are built and tested. What is
> missing is listed under [Not built yet](#not-built-yet) — most importantly
> debounced persistence and a handshake test against a real provider. Do not
> put this in front of documents you cannot afford to lose.

---

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [The two seams](#the-two-seams)
- [Configuration](#configuration)
- [Running the daemon](#running-the-daemon)
- [Deployment](#deployment)
- [Connecting a client](#connecting-a-client)
- [Architecture](#architecture)
- [The frame format](#the-frame-format)
- [Limits and untrusted input](#limits-and-untrusted-input)
- [Testing](#testing)
- [Compatibility](#compatibility)
- [Not built yet](#not-built-yet)

---

## Installation

Not on Packagist yet. Add the repository explicitly:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/davidhemphill/collab" },
        { "type": "vcs", "url": "https://github.com/davidhemphill/yjs-php" }
    ]
}
```

```bash
composer require hemp/collab:@dev
```

Both repositories are needed: `hemp/collab` depends on
[`hemp/yjs`](https://github.com/davidhemphill/yjs-php), which is where the Yjs
binary format lives and which is likewise unreleased.

Requires **PHP 8.4** on a **64-bit** platform, and Laravel 11 or 12. The service
provider is discovered automatically.

Publish the config if you want to edit it in your application:

```bash
php artisan vendor:publish --tag=collab-config
```

## Quick start

Three steps: say who may open a document, say where documents live, then start
the server.

**1. Implement the two seams** (detailed [below](#the-two-seams)):

```php
namespace App\Collaboration;

use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\{Authenticated, AuthenticationFailed, Authenticator};

class DocumentAuthenticator implements Authenticator
{
    public function authenticate(string $documentName, string $token): Authenticated
    {
        $claims = JWT::decode($token, ...) ?: throw AuthenticationFailed::invalidToken();

        // The token and the address have to agree, or a valid token for one
        // document opens every other one.
        if ((string) $claims->document_id !== $documentName) {
            throw AuthenticationFailed::documentMismatch();
        }

        return new Authenticated(
            $claims->may_edit ? Scope::ReadWrite : Scope::ReadOnly,
            identity: $claims->user_id,
        );
    }
}
```

**2. Point config at them**, in `config/collab.php` or `.env`:

```php
'authenticator' => App\Collaboration\DocumentAuthenticator::class,
'store' => App\Collaboration\EloquentDocumentStore::class,
```

**3. Run it**:

```bash
php artisan collab:start
```

## The two seams

This package holds no application policy. It reaches yours through exactly two
interfaces, and knows nothing else about your application — not what a user is,
not what a document is, not where either is stored.

Both are resolved from the container, so constructor injection works normally.

### `Authenticator` — who may open this document, and how

```php
namespace Hemp\Collab\Server;

interface Authenticator
{
    public function authenticate(string $documentName, string $token): Authenticated;
}
```

`$documentName` is the address the client put on the frame. `$token` is whatever
the client sent — treat it as hostile.

Return an `Authenticated`:

```php
new Authenticated(
    scope: Scope::ReadWrite,   // or Scope::ReadOnly
    identity: $user->id,       // opaque to this package; yours to use in logs and events
);
```

Refuse by throwing `AuthenticationFailed`. **The message is sent to the client
verbatim**, so it must be safe to say out loud:

| Constructor | Message |
|---|---|
| `AuthenticationFailed::invalidToken()` | `Invalid token.` |
| `AuthenticationFailed::documentMismatch()` | `Token does not match document.` |
| `AuthenticationFailed::because($reason)` | your string |

Prefer a message that does not distinguish an expired token from a forged one.
Telling an unauthenticated caller which of the two it holds is more than it has
earned; that detail belongs in your log.

Two things worth knowing:

- **Authentication is per document, not per connection.** A provider
  multiplexes every open document over one socket, and each gets its own
  session with its own scope. Authenticating for one grants nothing for
  another.
- **`Scope::ReadOnly` is the only write boundary that holds.** A read-only
  client's updates are refused and — importantly — never relayed to anyone
  else. Anything finer-grained than "may write this document" is a browser
  affordance, not an authorization boundary: it does not survive a modified
  client, which can send any update the protocol allows. Never put state in a
  Yjs document that some connected client must not be able to rewrite.

### `DocumentStore` — where a document's state lives

```php
namespace Hemp\Collab\Server;

use Hemp\Yjs\Update\Update;

interface DocumentStore
{
    public function load(string $documentName): Update;

    public function store(string $documentName, Update $update): void;
}
```

An `Update` is the Yjs binary update from `hemp/yjs`. `Update::decode($bytes)`
and `$update->encode()` move between it and a blob you can put in a column;
`Update::empty()` is a document that does not exist yet, which is a normal
return rather than an error.

```php
class EloquentDocumentStore implements DocumentStore
{
    public function load(string $documentName): Update
    {
        // Read fresh every time. A session holds its connection for as long as
        // the client is present, so by the time a second update arrives another
        // writer may have moved the document on — and merging onto stale state
        // silently discards everything written in between.
        $bytes = Document::query()->whereKey($documentName)->value('content_yjs');

        return $bytes ? Update::decode($bytes, DecodeLimits::trusted()) : Update::empty();
    }

    public function store(string $documentName, Update $update): void
    {
        Document::query()->whereKey($documentName)->update(['content_yjs' => $update->encode()]);
    }
}
```

Two properties this package relies on:

- **`load()` must return current state.** The comment above is not decoration —
  caching the row for the life of a session loses concurrent edits.
- **`store()` is on the acknowledgement path.** The client is told its update
  was accepted only after `store()` returns, so a client that hears "accepted"
  can stop holding the update. If `store()` throws, the acknowledgement is not
  sent. Today it is called on **every** accepted update; see
  [Not built yet](#not-built-yet).

If you would rather bind the seams yourself than name them in config, bind the
interfaces in a service provider and leave the config keys empty:

```php
$this->app->bind(Authenticator::class, DocumentAuthenticator::class);
```

## Configuration

`config/collab.php`, publishable with `--tag=collab-config`.

| Key | Env | Default | Meaning |
|---|---|---|---|
| `host` | `COLLAB_HOST` | `127.0.0.1` | Address to bind |
| `port` | `COLLAB_PORT` | `1234` | Port to bind |
| `authenticator` | `COLLAB_AUTHENTICATOR` | — | Class implementing `Authenticator` |
| `store` | `COLLAB_STORE` | — | Class implementing `DocumentStore` |
| `limits.frame_bytes` | `COLLAB_MAX_FRAME_BYTES` | 16 MiB | Largest single WebSocket frame |
| `limits.awareness_clients` | `COLLAB_MAX_AWARENESS_CLIENTS` | 512 | Cursors one awareness update may carry |
| `limits.awareness_state_bytes` | `COLLAB_MAX_AWARENESS_STATE_BYTES` | 64 KiB | Largest single client's awareness state |

The default bind address is deliberately **localhost**. A collaboration server
reachable from the internet without a proxy in front of it is almost never what
was intended.

Leaving `authenticator` or `store` unset fails at resolution with a message
naming the key — not with a container error about an interface you have never
heard of.

## Running the daemon

```bash
php artisan collab:start
php artisan collab:start --host=0.0.0.0 --port=4000
```

Options override config; config falls back to the defaults above.

```text
  INFO  Collaboration server listening on tcp://127.0.0.1:1234

  Compatibility ... Profile 1: @hocuspocus/provider 3.4.4, yjs 13.6.29, …
```

`SIGINT` and `SIGTERM` stop accepting new connections and let in-flight work
drain before the process exits.

This is a long-running process. It does not reload your code between requests,
so **restart it on deploy**.

It is also single-process and holds all state in memory, so **you cannot run
two of them behind a load balancer** — two clients on different instances would
never see each other. One process per document set, until the multi-node work
in [Not built yet](#not-built-yet) lands.

## Deployment

Supervisor:

```ini
[program:collab]
command=php /var/www/app/artisan collab:start
autostart=true
autorestart=true
user=www-data
stopwaitsecs=10
```

`stopwaitsecs` should exceed your drain time so supervisor does not `SIGKILL`
a server that was shutting down cleanly.

The daemon speaks plain WebSocket and terminates no TLS. Put a reverse proxy in
front of it:

```nginx
location /collab {
    proxy_pass http://127.0.0.1:1234;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    # Editing sessions idle for long stretches. The default 60s read timeout
    # will hang up on a user who stepped away mid-document.
    proxy_read_timeout 3600s;
}
```

It coexists with Laravel Reverb. The two share your container but not a wire
protocol, a connection registry, or a failure domain — this package imports
nothing from Reverb, and neither one restarting affects the other. They do need
different ports.

## Connecting a client

Nothing special on the client. An unmodified provider:

```js
import { HocuspocusProvider } from '@hocuspocus/provider'

const provider = new HocuspocusProvider({
  url: 'wss://example.com/collab',
  name: String(documentId),   // becomes $documentName in your Authenticator
  token: collaborationToken,  // becomes $token
  document: ydoc,
})
```

The request path is ignored — the document name comes from the frame, not the
URL — so route the proxy wherever suits you.

Use **provider 3.x**. See [Compatibility](#compatibility).

## Architecture

```text
src/Protocol/       the Hocuspocus provider frames
src/Server/         the session state machine, routing, and the daemon
src/Laravel/        the service provider, collab:start, publishable config
tools/oracle/       transcript generation, pinned to Profile 1
fixtures/profile-1/ committed transcripts, so the PHP suite runs without Node
```

The Yjs binary format itself — updates, merging, diffing, sync and awareness
codecs — lives in [`hemp/yjs`](https://github.com/davidhemphill/yjs-php) and is
consumed as a dependency. That split is deliberate: `hemp/yjs` knows nothing
about WebSockets, Laravel, or Hocuspocus, and this package adds no CRDT logic
of its own.

Inside `src/Server/`, each layer knows less than the one above it:

| | Holds | Knows about |
|---|---|---|
| `SocketServer` | the event loop and WebSocket framing | sockets |
| `Hub` | connections, subscriptions, broadcast | frames as strings |
| `Connection` | one client's sockets and its per-document sessions | its send closure |
| `Session` | authentication, authorization, merge | your two interfaces |

Only `SocketServer` touches the network. `Connection` takes its `send` and
`disconnect` as closures rather than a socket, so routing and broadcast are
exercised in tests without opening a port — and the daemon stays thin enough
that everything it does is moving bytes.

Two rules in `Hub` are worth stating outright, because both are invisible until
they are wrong:

- **Only updates the session accepted are relayed.** An update refused for any
  reason must reach nobody, or a read-only client could broadcast through a
  server that declined to store what it sent.
- **A departing connection retracts only the awareness clients it introduced.**
  Otherwise one client leaving evicts another from the cursor list.

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

## Limits and untrusted input

`FrameReader` is the outermost surface facing the network, and every frame
reaching it may come from a socket that has not authenticated yet. It inherits
`hemp/yjs`'s bounded decoder: declared lengths are checked before anything is
allocated, and every failure is a typed `Hemp\Yjs\Exception\DecodeException` a
connection handler can catch in one place. The suite asserts that over every
truncation and random corruption of every committed transcript.

The frame size limit is enforced *before* the payload is buffered, not after, so
a client announcing a huge frame is cut off rather than allocated for. A client
that violates a limit or sends an undecodable frame is disconnected on its own;
the daemon and every other session survive it. There is a test for exactly that,
because the failure mode — one stranger ending everyone else's editing session
— is not one you want to discover in production.

A frame that fails to decode closes the connection rather than being skipped:
the reader consumes a whole frame or nothing, so a failure means the client's
framing is lost and everything after it is guesswork.

## Testing

```bash
composer install    # hemp/yjs resolves from ../yjs-php as a path repository
composer test       # pint --test, then pest
```

The shipped suite runs without Node. Three groups, each proving something the
others cannot:

| Suite | What it runs against |
|---|---|
| `tests/Protocol` | committed transcripts, byte for byte |
| `tests/Server` | the session and hub with no framework, plus the daemon over a real port |
| `tests/Laravel` | a Testbench application, including `collab:start` serving real WebSocket clients |

Regenerating the transcripts needs the pinned JavaScript:

```bash
npm --prefix tools/oracle ci
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
Closing that gap — an unmodified provider reaching synced state against the
daemon — is the exit gate this package has not yet passed.

## Compatibility

Profile 1 is `@hocuspocus/provider` 3.4.4, yjs 13.6.29, y-protocols 1.0.7, and
lib0 0.2.117, named in `CompatibilityProfile` so a version bump is a decision
somebody makes rather than a dependency that drifted.

Provider v4 is a later profile. It changes enough to need its own entry —
session-aware addresses, an optional version field in authentication, bare
one-byte ping frames — and a v4 client is expected to fail visibly here rather
than half-work.

Node is a development and CI oracle only, never a runtime dependency. A test
asserts this.

## Not built yet

Named honestly, because the gap between "the tests pass" and "this is safe to
run" is exactly this list:

- **Debounced persistence.** `store()` is called on every accepted update. On a
  document under active editing that is a write per keystroke-batch.
- **Resident document lifecycle.** Documents are loaded per session rather than
  held once and shared, and nothing evicts them.
- **Backpressure.** A slow client's socket buffer is unbounded.
- **A real provider handshake test.** See
  [About the transcripts](#about-the-transcripts). This is the exit gate.
- **Multi-node scaling.** One process only; see
  [Running the daemon](#running-the-daemon).
- **Provider v4.**
