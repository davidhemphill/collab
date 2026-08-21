# collab

Add real-time collaborative editing to a Laravel application. No Node.js server.

Two persons open the same page. Each person sees the changes of the other person
immediately. Each person sees the cursor of the other person. Nobody overwrites
the work of anybody else.

Google Docs works like this. This package gives your Laravel application the
same behavior.

> **Do not use this in production yet.** A real `@hocuspocus/provider` connects,
> syncs, and edits correctly against this server, but the package is new and no
> application uses it yet. Read [Limits of this version](#limits-of-this-version)
> before you start.

---

## Contents

- [What you get](#what-you-get)
- [Before you start](#before-you-start)
- [Quick start](#quick-start)
- [Definitions](#definitions)
- [How the parts fit together](#how-the-parts-fit-together)
- [Inside the server](#inside-the-server)
- [The two classes you write](#the-two-classes-you-write)
- [Settings](#settings)
- [How to start the server](#how-to-start-the-server)
- [How to install the server on a production machine](#how-to-install-the-server-on-a-production-machine)
- [Commands](#commands)
- [Questions and answers](#questions-and-answers)
- [Troubleshooting](#troubleshooting)
- [Limits of this version](#limits-of-this-version)
- [How to run the tests](#how-to-run-the-tests)
- [How to work on this package](#how-to-work-on-this-package)

---

## What you get

You get these results:

- Two or more persons edit the same document at the same time.
- Each person sees the changes of the other persons in less than a second.
- Each person sees the name and the cursor of each other person.
- A person who loses the network connection keeps the work. The browser sends
  the work again when the connection comes back.
- A person with read-only permission cannot change the document.
- The document stays in your database, in a column that you control.

You do this work:

- You write two small PHP classes. One class says who can open a document. The
  other class says where to keep the document.
- You start one more process next to your web server.

You do **not** do this work:

- You do not write merge code. The browser and the server agree automatically.
- You do not install Node.js on the server.
- You do not change the JavaScript editor code.

## Before you start

You need these things:

| Item | Version |
|---|---|
| PHP | 8.4 or later, on a 64-bit machine |
| Laravel | 11 or 12 |
| A JavaScript editor with Yjs support | Tiptap, BlockNote, ProseMirror, Quill, Monaco, CodeMirror, or Slate |
| `@hocuspocus/provider` | Version 3. Version 4 does not work. |
| A machine that can run a process that does not stop | A virtual server, a container, or a dedicated machine |

Shared hosting is not sufficient. The server must run all the time. See
[Questions and answers](#questions-and-answers).

The package needs no PHP extension that a usual Laravel installation does not
have. It needs no Redis and no queue.

## Quick start

Follow these five steps. The full explanation of each step comes after.

### Step 1: Install the package

```bash
composer require hemp/collab
```

This brings `hemp/yjs` with it, which is where the binary format lives.

Laravel finds the package automatically. You do not register anything.

The version is below `1.0`, so the API may change between releases. Pin it if
that matters to you:

```bash
composer require hemp/collab:^0.1
```

### Step 2: Add a column for the document

The document is a block of bytes. Keep it in a binary column.

```bash
php artisan make:migration add_collab_state_to_documents_table
```

```php
Schema::table('documents', function (Blueprint $table) {
    $table->binary('collab_state')->nullable();
});
```

Run the migration:

```bash
php artisan migrate
```

### Step 3: Write the class that says who can open a document

Create `app/Collaboration/DocumentAuthenticator.php`:

```php
namespace App\Collaboration;

use App\Models\Document;
use App\Models\User;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\AuthenticationFailed;
use Hemp\Collab\Server\Authenticator;

class DocumentAuthenticator implements Authenticator
{
    public function authenticate(string $documentName, string $token): Authenticated
    {
        // $token is the value that the browser sends. Do not trust it.
        // This example uses a signed value from your own application.
        $user = User::find(decrypt($token));

        if ($user === null) {
            throw AuthenticationFailed::invalidToken();
        }

        $document = Document::find($documentName);

        if ($document === null || ! $user->can('view', $document)) {
            throw AuthenticationFailed::documentMismatch();
        }

        return new Authenticated(
            $user->can('update', $document) ? Scope::ReadWrite : Scope::ReadOnly,
            identity: $user->id,
        );
    }
}
```

### Step 4: Write the class that says where to keep the document

Create `app/Collaboration/DocumentStore.php`:

```php
namespace App\Collaboration;

use App\Models\Document;
use Hemp\Collab\Server\DocumentStore as DocumentStoreContract;
use Hemp\Yjs\Update\Update;

class DocumentStore implements DocumentStoreContract
{
    public function load(string $documentName): Update
    {
        $bytes = Document::query()->whereKey($documentName)->value('collab_state');

        if ($bytes === null) {
            return Update::empty();
        }

        return Update::decode($bytes);
    }

    public function store(string $documentName, Update $update): void
    {
        Document::query()->whereKey($documentName)->update([
            'collab_state' => $update->encode(),
        ]);
    }
}
```

Add the two classes to your `.env` file. Use single quotation marks:

```env
COLLAB_AUTHENTICATOR='App\Collaboration\DocumentAuthenticator'
COLLAB_STORE='App\Collaboration\DocumentStore'
```

Double quotation marks do not work here. Laravel reads `\C` as a special
character and stops with `Failed to parse dotenv file`.

### Step 5: Start the server and connect the browser

Start the server in a terminal:

```bash
php artisan collab:start
```

Connect the browser to it:

```js
import { HocuspocusProvider } from '@hocuspocus/provider'

const provider = new HocuspocusProvider({
  url: 'ws://127.0.0.1:1234',
  name: String(documentId),   // becomes $documentName in step 3
  token: userToken,           // becomes $token in step 3
  document: ydoc,             // the Yjs document of your editor
})
```

Open the page in two browser windows. Type in one window. The text appears in
the other window.

## Definitions

Read this section if a word in this document is new to you.

**Collaborative editing**
Two or more persons change the same document at the same time. Each person sees
the changes of the other persons.

**Yjs**
A JavaScript library. Yjs holds a document in the browser and merges changes
from different persons. Two browsers with the same changes always show the same
result, in any order. You do not write merge code, because Yjs does it.

**Update**
A small block of bytes from Yjs. An update holds one change or many changes. You
put updates together to make the full document. You do not read the bytes.

**Document**
The text and the format data that the persons edit together. In this package a
document is one `Update`.

**Document name**
The text that identifies one document. The browser sends this text. You choose
the value. A database ID is a good value.

**Hocuspocus**
A collaboration server for Yjs, written in JavaScript. Hocuspocus has a browser
part and a server part. This package replaces the server part. The browser part
does not change.

**Provider**
The browser part of Hocuspocus. The provider connects to the server, sends the
changes of this person, and receives the changes of the other persons. The name
of the JavaScript package is `@hocuspocus/provider`.

**WebSocket**
A connection between a browser and a server that stays open. Both sides send
data at any time. A usual HTTP request is not sufficient, because the server
must speak first when another person makes a change.

**Frame**
One message on the WebSocket connection. In this protocol each frame names one
document and holds one message about that document.

**Awareness**
The data that is not part of the document, but that the other persons must see.
The cursor position and the name of a person are awareness data. Awareness data
is not kept. It goes away when the person closes the page.

**Token**
A text that the browser sends to prove who the person is. You make the token in
your Laravel application. You read the token in your `Authenticator` class. This
package does not look inside the token.

**Scope**
The permission of one person for one document. There are two values:
`Scope::ReadWrite` and `Scope::ReadOnly`.

**Connection**
One open socket from one browser. A browser uses one connection for all the
documents that the person opens.

**Session**
The state of one connection for one document. One connection has one session for
each document that it opens. Each session has its own permission.

**The server**
The PHP process that `php artisan collab:start` starts. It stays open, it holds
the connections of all the persons, and it sends each change to the other
persons.

**Reverse proxy**
A web server, for example nginx, in front of your application. It receives the
requests from the internet and sends them to your application. It also does the
encryption (HTTPS and WSS).

## How the parts fit together

```text
Browser (Person A)  ─┐
                     ├─ WebSocket ─→  collab:start  ─→  your DocumentStore  ─→  Database
Browser (Person B)  ─┘                     │
                                           └─→ your Authenticator
```

The sequence for one change:

1. Person A types a letter. Yjs in the browser makes an update.
2. The provider sends the update to the server.
3. The server asks your `Authenticator` if person A can write. It asks one time
   for each document, not one time for each change.
4. The server adds the update to its copy of the document in memory. It read
   that copy with your `DocumentStore` one time, when the first person opened
   the document; it writes it back after the typing pauses, and at least every
   ten seconds — not for each letter.
5. The server tells person A that the change is accepted.
6. The server sends the update to person B.
7. Yjs in the browser of person B adds the update. The letter appears.

Two rules keep this correct:

- If the server refuses a change, no other person receives it. A person with
  read-only permission cannot send changes to the other persons.
- If a person closes the page, the server tells the other persons to remove that
  cursor.

## Inside the server

Read this section if you want to change this package, or if you must find the
cause of a problem. You do not need this section to use the package.

### The map of the code

```text
src/
├── Protocol/                     The message format. No sockets. No Laravel.
│   ├── AddressedFrame.php        One frame: a document name and one message.
│   ├── AuthMessageType.php       The three authentication numbers.
│   ├── CloseEvent.php            The close codes that the provider understands.
│   ├── CompatibilityProfile.php  The client versions that this server targets.
│   ├── FrameReader.php           Bytes to a frame. The untrusted surface.
│   ├── MessageType.php           The message numbers.
│   ├── Scope.php                 read-write or readonly.
│   └── Message/                  One class for each type of message.
│       ├── Authentication.php    The four shapes of the handshake.
│       ├── Awareness.php         A cursor update.
│       ├── Close.php             "I am done with this document."
│       ├── ProviderMessage.php   The interface that each message implements.
│       ├── QueryAwareness.php    "Tell me who else is here."
│       ├── Stateless.php         A string that the protocol does not read.
│       ├── Sync.php              A document update.
│       └── SyncStatus.php        "I accepted it" or "I refused it".
├── Server/                       The rules. One class touches a socket.
│   ├── Authenticated.php         The result of a good token.
│   ├── AuthenticationFailed.php  The result of a bad token.
│   ├── Authenticator.php         An interface. You write the class.
│   ├── Certificate.php           Finds a Herd or Valet certificate.
│   ├── Connection.php            One socket, and the documents open on it.
│   ├── DocumentStore.php         An interface. You write the class.
│   ├── Hub.php                   Sends each change to the other persons.
│   ├── Reception.php             A session's answer: replies, and news for the room.
│   ├── ResidentDocument.php      One open document's state, and what the store is owed.
│   ├── ResidentDocuments.php     The shared presence of each open document.
│   ├── ResidentStore.php         Decorates your DocumentStore: load once, write on a debounce.
│   ├── Session.php               One person, one document. The state machine.
│   ├── SessionFactory.php        An interface that builds a session.
│   ├── SharedSessionFactory.php  The usual one: the same classes for each document.
│   ├── SocketServer.php          The only class that touches a socket.
│   └── TlsContext.php            The certificate settings.
└── Laravel/
    ├── CollabServiceProvider.php Puts the parts in the Laravel container.
    ├── Console/StartCommand.php  The collab:start command.
    ├── Console/RestartCommand.php  The collab:restart command.
    └── config/collab.php         The settings file.
```

The `Protocol` and `Server` directories know nothing about Laravel. Only the
`Laravel` directory does. This is why the tests for the rules run with no
application under them.

### The path of one message

```text
bytes arrive on the socket
   │
   ▼
SocketServer     removes the WebSocket wrapper, gives the payload to the hub
   │
   ▼
Hub              decodes the frame, finds the connection
   │
   ▼
Connection       finds the session for that document name, or makes one
   │
   ▼
Session          decides what to answer
   │
   ├──→ your Authenticator     (one time for each document)
   └──→ your DocumentStore     (one load per open document; writes on a debounce)
   │
   ▼
Session          returns a list of frames
   │
   ▼
Hub              sends them to this person, then sends the change to the others
   │
   ▼
SocketServer     adds the WebSocket wrapper
   │
   ▼
bytes leave on the socket
```

Only `SocketServer` touches a socket. Everything above it takes strings and
returns strings, so the tests drive the full path with no port open.

### What each class does

| Class | Question it answers |
|---|---|
| `FrameReader` | What does this block of bytes say? |
| `AddressedFrame` | Which document, and which message? |
| `Session` | What do I answer this one person about this one document? |
| `Connection` | Which documents does this socket have open? |
| `Hub` | Who else must see this? |
| `ResidentStore` | What state does each open document have right now, and what does the database still owe it? |
| `SocketServer` | How do bytes get in and out? |

### The frame format

Each frame has the same three parts:

```text
varString   the document name
varUint     the message type
   ...      the payload, one shape for each type
```

`varString` and `varUint` are the lib0 number formats. The `hemp/yjs` package
reads and writes them.

One frame holds one message. This matters more than it looks: the reader uses
"nothing follows" as a fact, not as a guess, to tell two different
authentication messages apart. The reader also refuses a frame with bytes left
over, because leftover bytes mean it read the payload wrong.

### The message types

| Number | Name | Direction | Payload |
|---|---|---|---|
| 0 | Sync | both | A y-protocols sync message |
| 1 | Awareness | both | An awareness update, with its length in front |
| 2 | Auth | both | See [the authentication exchange](#the-authentication-exchange) |
| 3 | QueryAwareness | client to server | None |
| 4 | *unassigned* | — | The server refuses the frame |
| 5 | Stateless | both | A string. The server reads it and does nothing. |
| 6 | *unassigned* | — | The server refuses the frame |
| 7 | Close | client to server | None |
| 8 | SyncStatus | server to client | 1 for accepted, 0 for refused |

Numbers 4 and 6 have no meaning in this profile. The server refuses them instead
of ignoring them, because a later provider could give them a meaning that this
server does not understand.

The `SyncStatus` flag is a **signed** varInt, not the unsigned one that every
other count in this protocol uses. The provider reads it that way.

### The authentication exchange

Three numbers carry four messages, because number 0 travels in both directions:

| Number | Payload | Direction | Meaning |
|---|---|---|---|
| 0 | none | server to client | Send me your token. |
| 0 | a string | client to server | Here is my token. |
| 1 | a string | server to client | Refused, and this is the reason. |
| 2 | `read-write` or `readonly` | server to client | Accepted, with this permission. |

The two texts `read-write` and `readonly` are on the wire. The provider compares
them letter for letter.

### The session state machine

```text
                     a new session
                          │
     any message that is not an Auth message
                          │
                          └──→ the server answers "send me your token"
                          │
              the client sends its token
                          │
                 your Authenticator runs
                          │
       ┌──────────────────┴──────────────────┐
       │                                     │
  it throws                            it returns
  AuthenticationFailed                 Authenticated
       │                                     │
  "refused", with your text            "accepted", with the scope
  The session stays closed.            The session is open.
  The client can try again.
                                             │
                                             ▼
                                     an open session
```

An open session answers each message this way:

| Message from the browser | What the session does |
|---|---|
| Sync step 1 | Answers with the state the browser does not have, then asks a step 1 of its own |
| Sync step 2, or an update | Merges it (read-write) or judges it (read-only), then answers `SyncStatus`. An update that changes nothing is acknowledged without a write. |
| Awareness | Applies it to the document's shared presence. What was accepted goes to everyone, the sender included; what was not is silence. |
| QueryAwareness | Answers with everyone present in the document, even when that is nobody |
| Stateless | Nothing |
| Close | Nothing. The hub removes this document from the connection. |

The step 1 the server sends back is the part worth understanding. A sync is a
question in both directions: the browser asks what the server has, and the
server must ask what the browser has. Leave the second question out and the
exchange still looks correct — the browser receives the document and starts
editing — but the server can never learn about work it does not already hold.
A browser that was editing while the server was restarted, or restored from a
backup, or lost a write, would sit there holding the only copy. The provider
sends its state only in answer to this question, so the server asks it every
time.

One connection has one session for each document. A person who authenticates for
document A gets nothing for document B. The browser uses one socket for every
document, so this rule is what keeps the documents apart.

### Which messages reach the other persons

The session answers the person who spoke. The hub tells everybody else. It does
not tell them about everything:

| Message | Does it reach the other persons? |
|---|---|
| Sync step 1 | No. It asks a question. It says nothing. |
| Sync step 2 | Yes, if the server accepted it |
| Sync update | Yes, if the server accepted it |
| Awareness | Yes, and it also goes back to the person who sent it |
| SyncStatus | No. It goes only to the person who sent the change. |
| Stateless | No. Nothing at all happens. |
| Close, QueryAwareness | No |

Two rules control this table.

**Who is in the document.** The hub keeps a list of the connections in each
document, and a connection joins that list at the moment its handshake for that
document succeeds. So a connection that names a document without a token
receives nothing about it. The same rule works the other way: the hub relays
nothing on behalf of a connection whose session is not authenticated, which is
what stops a stranger from putting a cursor with a name of their choice in front
of everybody.

**Whether the server took the change.** The hub looks for the `SyncStatus`
answer that the session produced. If the answer is "refused", or if there is no
answer at all, the change reaches nobody. Without this rule a person with
read-only permission could send changes to every other person through a server
that refused to keep them.

### The read-only rule

A read-only person still completes the full handshake. The handshake requires
the browser to answer the sync step 1 of the server with a sync step 2. So a
read-only browser **does** send updates, and refusing all of them would break a
handshake that the person is allowed to complete.

The server therefore does not ask "did an update arrive?" It asks "would this
update change anything?"

| The message | The answer |
|---|---|
| A step 2 holding only state the server already has | Accepted, and nothing changes |
| A step 2 holding nothing | Accepted, and nothing changes |
| A step 2 holding state the server does not have | **Refused.** The document does not change and no other person sees it. |
| An update, whatever it holds | **Refused**, without looking inside it. |

The last row is an asymmetry worth pausing on. A step 2 answers the server's
own question — the browser was obliged to send it, so it earns the check. An
update is a write from someone who may not write, and Hocuspocus answers it
with a refusal without opening it. This server does the same.

This decision lives in `ReadOnlyPolicy` in the `hemp/yjs` package. It only
decides. The session acts.

### Awareness and departure

Every connection to a document shares one presence room. The store that holds
it lives with the document, not with any connection — the same shape as
Hocuspocus, whose Document owns a single Awareness instance — and it follows
the y-protocols rules exactly:

- A state is accepted when its clock is higher than the one the store knows.
  A repeat of something already known is rejected silently. This matters more
  than it looks: every browser restates every state it receives, and if those
  restatements were relayed, presence would circulate through the room for
  ever.
- A client renewing its clock without changing its state is the heartbeat.
  It is accepted and rebroadcast, and every peer's expiry timer starts over.
- What the store accepts is sent to **every** connection in the document, the
  sender included. The echo is what keeps one person alone in a document
  connected: the provider closes a socket it has received nothing on for 30
  seconds, counting only frames that arrive.

A person who opens the document is told who is already there, at once, in the
reply right after "you are in". Nobody waits for the others to renew.

Presence also ends in two ways without a goodbye:

- **The socket closes.** The hub retracts the clients that connection
  introduced — only those; a connection that could retract any client could
  remove another person from the cursor list — and tells the room. The
  departed client's clock is kept, so a message it sent before leaving cannot
  reinstate its cursor.
- **The socket goes silent.** A cursor that has not been heard from for 30
  seconds is expired and its removal is announced, on the same timer
  y-protocols runs. The daemon also pings each connection every 30 seconds
  and drops one that does not answer, with close code 4408, exactly as
  Hocuspocus does.

### Where each limit acts

| Limit | Where it acts | What happens when a message goes past it |
|---|---|---|
| `limits.frame_bytes` | The WebSocket layer, before the bytes are collected | The server ends that one connection. See the note below. |
| `limits.awareness_clients` | The frame reader, and the awareness store | The frame does not decode. The server closes that connection with code 1008. |
| `limits.awareness_state_bytes` | The frame reader, and the awareness store | The same |

The frame size limit acts on the announced length, not on the collected bytes. A
browser that announces a very large frame is cut off before the server holds it
in memory.

The WebSocket layer makes a close message with code 1009 for a frame that is too
large, but the server does not send that message. It ends the socket. So the
browser sees a connection that stopped, with no reason. The awareness limits and
the decode failures are different: the server sends code 1008 with a reason, and
the browser can read it.

The `hemp/yjs` decoder has its own limits for depth, element count, and total
allocation. They are not settings of this package. They use the defaults of that
package.

### Close codes

| Code | Name | Does the provider try again? | When the server sends it |
|---|---|---|---|
| 1008 | Policy Violation | No | A frame did not decode. The server sends this one. |
| 1009 | Message Too Big | No | A frame went past `limits.frame_bytes`. The WebSocket layer makes it, but the server ends the socket instead of sending it. |
| 1011 | Internal Error | Yes | Your `Authenticator` or `DocumentStore` threw an exception. |
| 1012 | Service Restart | Yes | Defined, not sent yet |
| 4205 | Reset Connection | Yes | Defined, not sent yet |
| 4401 | Unauthorized | No | Defined, not sent yet |
| 4403 | Forbidden | No | Defined, not sent yet |
| 4408 | Connection Timeout | Yes | The connection stopped answering pings. |

The code matters. A permanent refusal sent with a code that means "try again"
gives you a browser that reconnects for ever.

A refused token does **not** close the connection. The server answers with an
authentication message that says "refused". The provider stops asking for that
document.

### The compatibility profile

This server targets exact client versions, and it says so in code rather than
only in prose:

| Package | Version |
|---|---|
| `@hocuspocus/provider` | 3.4.4 |
| `yjs` | 13.6.29 |
| `y-protocols` | 1.0.7 |
| `lib0` | 0.2.117 |

`php artisan collab:start` prints this profile when it starts.

Profile 2 will add provider version 4. Version 4 changes enough to need its own
entry: an optional version field in the authentication, document addresses of
the form `documentName` + NUL + `sessionId`, and one-byte ping frames. None of
that works here. A version 4 client fails in a way you can see, which is better
than working half way.

The `fixtures/profile-1/provider-transcripts.json` file holds one recorded frame
for each message type. The tests read every frame and write each one again, and
the bytes must be identical. See
[How to work on this package](#how-to-work-on-this-package).

## The two classes you write

This package holds no rules of your application. It knows nothing about your
users, your documents, or your database. It asks your code two questions, one
question for each class.

### Authenticator: who can open this document?

```php
interface Authenticator
{
    public function authenticate(string $documentName, string $token): Authenticated;
}
```

The server calls this method one time for each document, when the browser first
asks for that document.

To accept the person, return an `Authenticated` object:

```php
new Authenticated(
    scope: Scope::ReadWrite,   // or Scope::ReadOnly
    identity: $user->id,       // any value; this package does not read it
);
```

To refuse the person, throw an `AuthenticationFailed` exception:

| Method | Text sent to the browser |
|---|---|
| `AuthenticationFailed::invalidToken()` | `Invalid token.` |
| `AuthenticationFailed::documentMismatch()` | `Token does not match document.` |
| `AuthenticationFailed::because('...')` | your text |

**Warning:** the browser receives this text. Do not put private data in it. Also
use the same text for a token that is too old and for a token that is false. If
the two texts are different, a person who attacks your application learns which
tokens are real.

Three rules to remember:

- The browser can open many documents on one connection. Each document has its
  own permission. Permission for one document gives no permission for another
  document.
- `Scope::ReadOnly` is the only rule that always holds. A person with
  `Scope::ReadWrite` can send any change, because the JavaScript in the browser
  is not under your control. If you hide a button, you change what the person
  sees, not what the person can do.
- Because of the rule above, do not put data in the document if a person with
  write permission must not change that data. Keep such data in your database
  and control it with your usual Laravel code.

### DocumentStore: where does the document stay?

```php
interface DocumentStore
{
    public function load(string $documentName): Update;

    public function store(string $documentName, Update $update): void;
}
```

`Update::decode($bytes)` makes an `Update` from the bytes in your column.
`$update->encode()` makes the bytes again. `Update::empty()` is a new document.
A document that does not exist is normal, not an error.

Three rules to remember:

- **The server calls `load()` once per open document, not per message.** The
  first person to open a document triggers one `load()`. After that the
  document lives in the server's memory for as long as anyone has it open, and
  every change merges there. Your `load()` should still read the database
  fresh each time it is called — the server decides when that is, and when it
  asks, it wants the truth.
- **`store()` runs on a debounce, not per keystroke.** The server writes the
  document after `persistence.quiet_seconds` without a change (default 2), at
  least every `persistence.max_wait_seconds` while the typing never pauses
  (default 10), and always when the last person leaves. These are Hocuspocus's
  numbers. A positive `SyncStatus` therefore means "merged into the server's
  document", with the write at most `max_wait_seconds` behind. If the server
  dies inside that window, the browsers still hold the document — the server
  asks each browser what it holds during the handshake, so the missing tail
  comes back when any of them reconnects.
- **An exception from `store()` costs a retry, not the connection.** By the
  time the write happens, the change was acknowledged long ago. The document
  stays in memory, stays marked dirty, and the server tries again with backoff
  until the write lands. A dirty document is never dropped. An exception from
  `load()` still ends that one connection with code 1011: there is nothing in
  memory yet to serve, and the browser should come back later.

### How to bind them without the config file

Both classes come out of the Laravel container. Name them in the config file, or
bind the interfaces yourself in a service provider and leave the config keys
empty:

```php
$this->app->singleton(\Hemp\Collab\Server\Authenticator::class, DocumentAuthenticator::class);
$this->app->singleton(\Hemp\Collab\Server\DocumentStore::class, DocumentStore::class);
```

Use this when your class needs constructor arguments that the container cannot
guess.

## Settings

The settings file is `config/collab.php`. To change it, copy it to your
application first:

```bash
php artisan vendor:publish --tag=collab-config
```

| Setting | Environment variable | Default | Function |
|---|---|---|---|
| `host` | `COLLAB_HOST` | `127.0.0.1` | The address of the server |
| `port` | `COLLAB_PORT` | `1234` | The port of the server |
| `hostname` | `COLLAB_HOSTNAME` | the host in `APP_URL` | Site name; finds a Herd or Valet certificate for it |
| `options.tls.local_cert` | `COLLAB_TLS_CERT` | none | Certificate file; set it and the server speaks `wss://` |
| `options.tls.local_pk` | `COLLAB_TLS_KEY` | none | Private key file, if it is not in the certificate file |
| `options.tls.passphrase` | `COLLAB_TLS_PASSPHRASE` | none | Passphrase, if the key has one |
| `authenticator` | `COLLAB_AUTHENTICATOR` | none | Your `Authenticator` class |
| `store` | `COLLAB_STORE` | none | Your `DocumentStore` class |
| `persistence.quiet_seconds` | `COLLAB_STORE_QUIET_SECONDS` | 2 | Write the document this long after the typing pauses |
| `persistence.max_wait_seconds` | `COLLAB_STORE_MAX_WAIT_SECONDS` | 10 | Write at least this often while the typing never pauses |
| `limits.frame_bytes` | `COLLAB_MAX_FRAME_BYTES` | 16 MB | The largest message from a browser |
| `limits.awareness_clients` | `COLLAB_MAX_AWARENESS_CLIENTS` | 512 | The largest number of cursors in one message |
| `limits.awareness_state_bytes` | `COLLAB_MAX_AWARENESS_STATE_BYTES` | 64 KB | The largest cursor data for one person |

The default address is `127.0.0.1`. Only your own machine can connect to that
address. This is correct for almost all applications, because a reverse proxy
sends the connections to the server. See
[How to install the server on a production machine](#how-to-install-the-server-on-a-production-machine).

The three limits protect the server. A browser can send a message before the
server knows who the person is, so the server must refuse a message that is too
large. If a browser goes past a limit, the server closes that one connection.
The server and all the other persons continue.

If you do not set `authenticator` or `store`, the server stops with a message
that gives the name of the setting.

## How to start the server

```bash
php artisan collab:start
```

To use a different address or port:

```bash
php artisan collab:start --host=0.0.0.0 --port=4000
```

The command line wins over the settings file.

The command shows this text:

```text
  INFO  Collaboration server listening on tcp://127.0.0.1:1234

  Clients connect with ......................... ws:// (no TLS here)
  Compatibility ... Profile 1: @hocuspocus/provider 3.4.4, yjs 13.6.29, …
```

The second line says `wss:// (this server terminates TLS)` when you give the
server a certificate.

To stop the server, press `Ctrl+C`. The server does this:

1. It stops accepting new connections.
2. It gives the work that is in progress one second to complete.
3. It writes every document that still owes the store a write.
4. It stops.

`SIGTERM` does the same thing, so Supervisor and Docker stop the server the same
way. No accepted change is lost to a graceful stop.

**Important:** this process reads your PHP code one time, at the start. If you
change your code, the running server must stop and start again. Do not do this
by hand — add one line to your deployment procedure:

```bash
php artisan collab:restart
```

This works the way `queue:restart` works. The command writes a timestamp into
your cache. The running server looks at the cache every second, sees the
signal, drains its documents, and exits. Supervisor starts the fresh server
with the new code. Nothing needs to find a process or talk to Supervisor, so
the command works from a deployment script, a Forge deploy, or your terminal.

Two conditions, both usually already true:

- The command and the server must share one cache store — `redis`,
  `database`, or `file` on the same machine. The `array` store lives and dies
  inside a single process, so a signal sent through it reaches nobody.
- Something must start the server again after it exits. That is Supervisor's
  `autorestart=true` below; a Forge daemon does the same.

## How to install the server on a production machine

### Keep the process alive

Use Supervisor to start the server again if it stops:

```ini
[program:collab]
command=php /var/www/app/artisan collab:start
autostart=true
autorestart=true
user=www-data
stopwaitsecs=10
```

Set `stopwaitsecs` to a value that is more than the time to stop. If the value
is too small, Supervisor kills a server that is still busy.

### Add encryption

A page served over `https://` cannot open a `ws://` connection. The browser
refuses it, with one exception: `127.0.0.1`. So a real deployment needs
`wss://`, and there are two ways to get it.

**Give the server a certificate.** The server then speaks `wss://` itself:

```env
COLLAB_TLS_CERT=/path/to/certificate.pem
COLLAB_TLS_KEY=/path/to/private-key.pem
```

```bash
php artisan collab:start --host=0.0.0.0 --port=8443
```

```js
url: 'wss://example.com:8443'
```

Use this on a platform that does not let you configure a web server, for example
Laravel Cloud and most managed hosts. There is nowhere to put a proxy there, so
a server that cannot terminate TLS cannot be reached at all.

In local development you usually set nothing at all. The hostname defaults to
the host in `APP_URL`, and if Herd or Valet has secured that site, its
certificate is found and used. A secured site therefore serves `wss://` with no
collaboration configuration beyond the two classes.

Set `COLLAB_HOSTNAME` only when the server answers on a different name from the
application:

```env
COLLAB_HOSTNAME=collab.my-app.test
```

If the certificate file holds a chain, put the server's own certificate first
and each issuer after it. `COLLAB_TLS_KEY` is not necessary when the key is in
the same file. The server checks both files at start and refuses to start if
either cannot be read, rather than failing later on a connection.

TLS is on when a certificate is present and off when it is not. There is no
separate switch to keep in agreement with it.

`config/collab.php` holds these under `options.tls`, and they are PHP's own SSL
context options, so anything PHP accepts can be set: `verify_peer`, `ciphers`,
`cafile`, and the rest. This is the same shape that Reverb uses, on purpose. An
application often runs both servers, and they should not disagree about how a
certificate is named or where a local one is found.

**Or put a proxy in front.** Use this when you control the web server. It keeps
everything on port 443 with no port number in the URL:

```nginx
location /collab {
    proxy_pass http://127.0.0.1:1234;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    # A person can look at a document for a long time and type nothing.
    # The default limit of 60 seconds closes that connection.
    proxy_read_timeout 3600s;
}
```

Leave `COLLAB_TLS_CERT` empty in this case. The proxy does the encryption and
the server speaks plain WebSocket behind it.

### Use one server only

You cannot run two of these servers behind a load balancer. The server keeps all
the connections in its memory. Two persons on two different servers cannot see
each other.

One server is sufficient for many documents and many persons. If you need more
than one machine, this package is not ready for you yet.

### A checklist before you go live

- [ ] The document column is binary, and it is large enough.
- [ ] `COLLAB_AUTHENTICATOR` and `COLLAB_STORE` use single quotation marks.
- [ ] Supervisor, or another program, starts the server again if it stops.
- [ ] The browser uses `wss://` if the page uses `https://`.
- [ ] The read timeout of the proxy is much more than 60 seconds.
- [ ] Your deployment procedure runs `php artisan collab:restart` after each code change.
- [ ] You read [Limits of this version](#limits-of-this-version).

## Commands

For your application:

| Command | Function |
|---|---|
| `php artisan collab:start` | Start the server |
| `php artisan collab:start --host=0.0.0.0` | Start it on a different address |
| `php artisan collab:start --port=4000` | Start it on a different port |
| `php artisan collab:restart` | Ask the running server to drain and exit, so its supervisor starts it fresh |
| `php artisan vendor:publish --tag=collab-config` | Copy `config/collab.php` into your application |

For work on this package:

| Command | Function |
|---|---|
| `composer test` | Check the formatting, then run every test |
| `composer test:lint` | Check the formatting only |
| `composer test:unit` | Run every test only |
| `composer lint` | Correct the formatting |
| `composer fixtures` | Build the provider transcripts again. Needs Node.js. |

## Questions and answers

### Which editors work with this package?

Any JavaScript editor that supports Yjs. This includes Tiptap, BlockNote,
ProseMirror, Quill, Monaco, CodeMirror, and Slate. The editor gives you a Yjs
document. You give that document to the provider.

### Must I change my JavaScript code?

No. Use the usual `@hocuspocus/provider` package. Change only the `url` value.

### Can I use this on Laravel Cloud, or another host with no web server config?

Yes. Give the server a certificate with `COLLAB_TLS_CERT` and it speaks `wss://`
on its own. A reverse proxy is one way to add encryption, not the only one, and
it is not available on those platforms.

### Do I need Node.js?

No. Node.js is necessary only to develop this package, not to use it.

### Does this replace Laravel Reverb, Pusher, or Echo?

No. They do different work.

Reverb, Pusher, and Echo send events to many browsers. Example: "a new comment
is here". Each browser then does something. They do not merge text.

This package merges text. Two persons type in the same line at the same time and
both changes stay. Reverb cannot do this.

You can use both in the same application. Give them different ports. This package
imports nothing from Reverb. The two share the container and nothing else.

### Do I need Redis?

No.

### Can I use shared hosting?

No. The server must run all the time and keep connections open. Shared hosting
usually stops a PHP process after some seconds. Use a virtual server, a
container, or a platform that runs worker processes.

### What happens if the server stops?

The browsers lose the connection. Each person keeps their work in the browser
page. The provider connects again automatically and sends the work again. The
length of the stop does not matter.

But the work is in the page. If a person closes the tab while the server is
down, the work of that person goes away. Tell your persons not to close the tab
if they see a "not connected" message.

### How do I make a document read-only for one person?

Return `Scope::ReadOnly` from your `Authenticator`. The server refuses the
changes from that person and sends them to nobody.

### How do I stop a person from opening a document?

Throw `AuthenticationFailed` from your `Authenticator`. The server answers
"refused" for that document, and the provider stops asking for it. The
connection itself stays open, because the browser can have other documents on
it.

### Can I let a person add comments but not change the text?

Not with the scope values. There are two values only: read-write and read-only.

If you hide the editing buttons, that is a change to what the person sees. It is
not a rule. A person who writes their own JavaScript can send any change.

For a real rule, keep the comments in your database and use your usual Laravel
permissions.

### How large can a document be?

The default limit for one message is 16 MB. A document of usual text is much
smaller. A document grows when persons edit it, also when they delete text,
because Yjs keeps a record of the deletions.

### Can I use a different database, or a file, or S3?

Yes. The server calls only `load()` and `store()` in your class. Put the bytes
where you want.

### How do I test my two classes?

Test them as usual Laravel classes. Call `authenticate()` with a token and look
at the scope that it returns. Call `store()` and then `load()` and compare. You
do not need a server for these tests.

### Does the package send events to Laravel?

Not yet. The `identity` value that you return from your `Authenticator` is
available to the package, but the package raises no events with it now.

### Why must the token be sent for each document?

The browser uses one connection for all the documents that a person opens. If
one token opened all of them, a person with permission for one document could
open every other document.

### How many persons can one document hold?

There is no fixed limit. The practical limit is the memory and the processor of
the one machine. Each change is sent to each other connection in the document,
so the work grows with the square of the number of persons in one document.

## Troubleshooting

### The browser does not connect

Look at these items in this order:

1. Is the server running? Look for the `INFO` line.
2. Is the port correct in the browser and in the settings?
3. Does the page use HTTPS? Then the browser needs `wss://`, not `ws://`. A
   browser refuses a `ws://` connection from an HTTPS page.
4. Is a reverse proxy in front of the server? Then it needs the two
   `proxy_set_header` lines that this document shows.
5. Does your `Authenticator` throw an exception? Look in the browser console for
   the refusal text.

### `Failed to parse dotenv file`

**Cause:** you used double quotation marks around a class name in `.env`.
Laravel reads `\C` as a special character.

**Correction:** use single quotation marks.

```env
COLLAB_AUTHENTICATOR='App\Collaboration\DocumentAuthenticator'
```

### `collab.authenticator is not configured`

**Cause:** the setting is empty, and nothing bound the interface.

**Correction:** set `COLLAB_AUTHENTICATOR` in `.env`, or bind
`Hemp\Collab\Server\Authenticator` in a service provider. The same applies to
`collab.store` and `Hemp\Collab\Server\DocumentStore`.

### `The TLS file [...] does not exist or cannot be read`

**Cause:** the certificate path or the key path is wrong, or the user that runs
the server cannot read the file.

**Correction:** correct the path, or give the user permission to read the file.
The server makes this check at the start, on purpose. A path that fails later
shows as a handshake error in the browser and as nothing in the server log.

### The connection closes after some seconds, and always at the same time

**Cause:** the read timeout of the reverse proxy. The default of nginx is 60
seconds, and a person who reads a document sends nothing in that time.

**Correction:** raise `proxy_read_timeout`.

### The connection closes immediately after the first message

**Cause:** the server could not decode the frame, so it closed the connection
with code 1008 and a reason. Read the reason in the browser console. The reader
takes a whole frame or nothing, so it cannot skip a bad one and continue.

A connection that ends with no code and no reason is a different fault: the
frame went past `limits.frame_bytes`.

**Correction:** check the version of `@hocuspocus/provider`. Version 4 sends
frames that this server does not understand. Use version 3.

### A change in my PHP code does nothing

**Cause:** the server read your code one time, when it started.

**Correction:** stop the server and start it again.

### `Address already in use`

**Cause:** another program has the port, often an older copy of this server.

**Correction:** stop the other program, or use `--port` with a free port.

### One browser sees the text and the other does not

Look at the `SyncStatus` answer in the browser console. If the server refused the
change, that browser has read-only permission, and the change reaches nobody.
This is correct behavior. Look at your `Authenticator`.

### A cursor stays on the screen after the person left

The server sends a removal when the socket closes, and expires any cursor that
has been silent for 30 seconds even when the socket did not close. A cursor
that outlives both is a clock problem: check that the machine's clock is not
jumping backwards, because expiry compares timestamps.

## Limits of this version

Read this list before you use the package for real documents.

| Limit | Result |
|---|---|
| The write is debounced | A `kill -9` can lose up to `persistence.max_wait_seconds` of accepted changes from the server's copy. The browsers still hold them, and the handshake takes them back when one reconnects — but until a browser that saw the changes reconnects, the database is behind. A graceful stop (SIGTERM) loses nothing: the server writes every dirty document before it exits. |
| One process only | You cannot use two servers behind a load balancer. |
| A slow browser has no limit | A browser that receives data slowly uses memory on the server. |
| The real-provider test is manual | It needs an application and a running server, so `composer test` does not include it. See [How to run the tests](#how-to-run-the-tests). |
| No certificate reloading | A renewed certificate is used when the server restarts, not before. |
| The `Origin` header is not checked | Any page may open a connection. It gets nothing without a token, so this is a door, not a hole, but a proxy in front of the server is the place to close it. |
| Bytes sent in the same packet as the handshake are dropped | A browser cannot do this — it cannot send before the connection opens — but a client that writes the upgrade request and its first message together loses that message. |
| Stateless messages do nothing | The server reads the `Stateless` message type but sends it to nobody. |
| Provider version 4 does not work | Use `@hocuspocus/provider` version 3. |
| No events | The `identity` value goes no further than the session. |

## How to run the tests

```bash
composer install
composer test
```

This checks the formatting and then runs 155 tests. They need no Node.js and no
network. They take about one second.

There are three groups:

| Group | What it tests | Needs an application? |
|---|---|---|
| `tests/Protocol` | The message format, byte for byte, against the recorded provider frames | No |
| `tests/Server` | The rules, and the server on a real port | No |
| `tests/Laravel` | A small Laravel application that starts the server and connects to it | Yes |

Only the Laravel group has an application under it. Everything else runs without
one, on purpose: the rules do not depend on a framework, so the tests should not
either.

To run one group:

```bash
vendor/bin/pest --testsuite=Protocol
vendor/bin/pest tests/Server/HubTest.php
```

Some helper functions live in the test file that uses them, not in `Pest.php`.
If a single file fails with `Call to undefined function hub()`, run the whole
group instead.

### The test with a real browser client

The tests above use messages that this package builds itself. They cannot show
that the real JavaScript client agrees. Two scripts do that. They need Node.js,
an application, and a running server:

```bash
npm --prefix tools/oracle ci
php artisan collab:start --port=7788     # in your application, in another terminal
```

```bash
node tools/oracle/e2e-provider.mjs ws://127.0.0.1:7788 <document> <token>
```

```bash
node tools/oracle/e2e-readonly.mjs ws://127.0.0.1:7788 <document> <owner-token> <reader-token>
```

The first script connects a real `@hocuspocus/provider`, types from two clients,
makes two changes at the same time, sends a cursor, connects a third client
late, and gives a bad token. The second script shows that text from a read-only
client reaches nobody.

## How to work on this package

### The two packages

The Yjs format itself is in a different package,
[hemp/yjs](https://github.com/davidhemphill/yjs-php). That package holds the
merge code, the binary formats, and the read-only decision. It knows nothing
about Laravel, WebSockets, or Hocuspocus.

While the two packages are unpublished, `composer.json` points at a path:

```json
{
    "repositories": [
        { "type": "path", "url": "../yjs-php", "options": { "symlink": true } }
    ]
}
```

So `yjs-php` must sit beside this checkout:

```text
GitHub/
├── collab/
└── yjs-php/
```

Continuous integration checks out both for the same reason.

### The transcripts

`fixtures/profile-1/provider-transcripts.json` holds one frame for each message
type. The tests read every frame, write each one again, and require identical
bytes.

The frames are built from the same packages that the provider builds them from:
`@hocuspocus/common`, `y-protocols`, and `lib0`, following the frame
construction in each `OutgoingMessage` class of the provider. They are **not**
recorded from a running provider. The provider does not export those classes,
and driving a live one needs a server to connect to. This is a real limit, and
the two end-to-end scripts above exist to cover it.

To build them again:

```bash
npm --prefix tools/oracle ci
composer fixtures
```

The generator writes no timestamp. So any difference in the output is a real
difference in the protocol, not noise. Continuous integration builds the
transcripts again on each change and fails if the committed files moved. If that
job fails, read the difference and decide whether the compatibility profile must
change.

### Continuous integration

| Job | What it does |
|---|---|
| `php` | Checks out both packages, then runs Pint and Pest on PHP 8.4 and 8.5 |
| `transcripts` | Builds the provider transcripts again and fails if they moved |

### Formatting

```bash
composer lint
```

Pint with the Laravel preset. Continuous integration fails on a difference, so
run this before you commit.

---

This document uses ASD-STE100 Simplified Technical English: short sentences,
active voice, one meaning for each word. Keep this style if you change the text.
