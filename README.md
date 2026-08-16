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
- [The two classes you write](#the-two-classes-you-write)
- [Settings](#settings)
- [How to start the server](#how-to-start-the-server)
- [How to install the server on a production machine](#how-to-install-the-server-on-a-production-machine)
- [Questions and answers](#questions-and-answers)
- [Limits of this version](#limits-of-this-version)
- [How to run the tests](#how-to-run-the-tests)

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
| A machine that can run a process that does not stop | A virtual server, a container, or a dedicated machine |

Shared hosting is not sufficient. The server must run all the time. See
[Questions and answers](#questions-and-answers).

## Quick start

Follow these five steps. The full explanation of each step comes after.

### Step 1: Install the package

The package is not on Packagist yet. Add the two repositories to your
`composer.json` file:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/davidhemphill/collab" },
        { "type": "vcs", "url": "https://github.com/davidhemphill/yjs-php" }
    ]
}
```

Then install the two packages:

```bash
composer require hemp/collab:@dev hemp/yjs:@dev
```

Name both packages. If you name only `hemp/collab`, composer stops with this
message:

```text
hemp/collab dev-main requires hemp/yjs @dev
-> found hemp/yjs[dev-main] but it does not match your minimum-stability.
```

The `@dev` mark applies only to the package that you name. It does not apply to
the packages that this package needs.

Laravel finds the package automatically. You do not register anything.

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
4. The server reads the document with your `DocumentStore`, adds the update, and
   writes the document again.
5. The server tells person A that the change is safe.
6. The server sends the update to person B.
7. Yjs in the browser of person B adds the update. The letter appears.

Two rules keep this correct:

- If the server refuses a change, no other person receives it. A person with
  read-only permission cannot send changes to the other persons.
- If a person closes the page, the server tells the other persons to remove that
  cursor.

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

Two rules to remember:

- **`load()` must read the database each time.** Do not keep the value in a
  variable for the life of the connection. Another person can change the
  document between two calls. If you use an old value, you lose the work of that
  person.
- **`store()` must complete before the person is safe.** The server tells the
  browser that the change is safe only after `store()` returns. If `store()`
  throws an exception, the server does not send that message, and the browser
  keeps the change and sends it again.

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
| `tls.certificate` | `COLLAB_TLS_CERTIFICATE` | none | Certificate file; set it and the server speaks `wss://` |
| `tls.key` | `COLLAB_TLS_KEY` | none | Private key file, if it is not in the certificate file |
| `tls.passphrase` | `COLLAB_TLS_PASSPHRASE` | none | Passphrase, if the key has one |
| `authenticator` | `COLLAB_AUTHENTICATOR` | none | Your `Authenticator` class |
| `store` | `COLLAB_STORE` | none | Your `DocumentStore` class |
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

The command shows this text:

```text
  INFO  Collaboration server listening on tcp://127.0.0.1:1234

  Compatibility ... Profile 1: @hocuspocus/provider 3.4.4, yjs 13.6.29, …
```

To stop the server, press `Ctrl+C`. The server stops new connections first, then
completes the work that is in progress.

**Important:** this process reads your PHP code one time, at the start. If you
change your code, stop the server and start it again. Add this step to your
deployment procedure.

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
COLLAB_TLS_CERTIFICATE=/path/to/certificate.pem
COLLAB_TLS_KEY=/path/to/private-key.pem
```

```bash
php artisan collab:start --host=0.0.0.0 --port=443
```

```js
url: 'wss://example.com:8443'
```

Use this on a platform that does not let you configure a web server — Laravel
Cloud and most managed hosts. There is nowhere to put a proxy there, so a server
that cannot terminate TLS cannot be reached at all.

If the certificate file holds a chain, put the server's own certificate first
and each issuer after it. `COLLAB_TLS_KEY` is not necessary when the key is in
the same file. The server reads both files at start and refuses to start if
either is missing, rather than failing later on a connection.

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

Leave `COLLAB_TLS_CERTIFICATE` empty in this case. The proxy does the
encryption and the server speaks plain WebSocket behind it.

### Use one server only

You cannot run two of these servers behind a load balancer. The server keeps all
the connections in its memory. Two persons on two different servers cannot see
each other.

One server is sufficient for many documents and many persons. If you need more
than one machine, this package is not ready for you yet.

## Questions and answers

### Which editors work with this package?

Any JavaScript editor that supports Yjs. This includes Tiptap, BlockNote,
ProseMirror, Quill, Monaco, CodeMirror, and Slate. The editor gives you a Yjs
document. You give that document to the provider.

### Must I change my JavaScript code?

No. Use the usual `@hocuspocus/provider` package. Change only the `url` value.

### Can I use this on Laravel Cloud, or another host with no web server config?

Yes. Give the server a certificate with `COLLAB_TLS_CERTIFICATE` and it speaks
`wss://` on its own. A reverse proxy is one way to add encryption, not the only
one, and it is not available on those platforms.

### Do I need Node.js?

No. Node.js is necessary only to develop this package, not to use it.

### Does this replace Laravel Reverb, Pusher, or Echo?

No. They do different work.

Reverb, Pusher, and Echo send events to many browsers. Example: "a new comment
is here". Each browser then does something. They do not merge text.

This package merges text. Two persons type in the same line at the same time and
both changes stay. Reverb cannot do this.

You can use both in the same application. Give them different ports.

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

Throw `AuthenticationFailed` from your `Authenticator`. The server closes the
connection for that document.

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

### The browser does not connect. What must I do?

Look at these items in this order:

1. Is the server running? Look for the `INFO` line.
2. Is the port correct in the browser and in the settings?
3. Does the page use HTTPS? Then the browser needs `wss://`, not `ws://`. A
   browser refuses a `ws://` connection from an HTTPS page.
4. Is a reverse proxy in front of the server? Then it needs the two `proxy_set_header`
   lines that this document shows.
5. Does your `Authenticator` throw an exception? Look in the browser console for
   the refusal text.

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

## Limits of this version

Read this list before you use the package for real documents.

| Limit | Result |
|---|---|
| The document is written for each change | Many writes to the database while a person types. This is correct but not fast. |
| One process only | You cannot use two servers behind a load balancer. |
| A slow browser has no limit | A browser that receives data slowly uses memory on the server. |
| The real-provider test is manual | It needs an application and a running server, so `composer test` does not include it. See [How to run the tests](#how-to-run-the-tests). |
| No certificate reloading | A renewed certificate is picked up when the server restarts, not before. |
| Stateless messages do nothing | The server reads the `Stateless` message type but sends it to nobody. |
| Provider version 4 does not work | Use `@hocuspocus/provider` version 3. |

## How to run the tests

```bash
composer install
composer test
```

The tests need no Node.js and no network. There are three groups:

| Group | What it tests |
|---|---|
| `tests/Protocol` | The message format, byte for byte |
| `tests/Server` | The rules, and the server on a real port |
| `tests/Laravel` | A small Laravel application that starts the server and connects to it |

### The test with a real browser client

The tests above use messages that this package builds itself. They cannot show
that the real JavaScript client agrees. Two scripts do that. They need Node.js,
an application, and a running server:

```bash
npm --prefix tools/oracle ci
php artisan collab:start --port=7788     # in your application, in another terminal

node tools/oracle/e2e-provider.mjs ws://127.0.0.1:7788 <document> <token>
node tools/oracle/e2e-readonly.mjs ws://127.0.0.1:7788 <document> <owner-token> <reader-token>
```

The first script connects a real `@hocuspocus/provider`, types from two clients,
makes two changes at the same time, sends a cursor, connects a third client
late, and gives a bad token. The second script shows that text from a read-only
client reaches nobody.

The Yjs format itself is in a different package,
[hemp/yjs](https://github.com/davidhemphill/yjs-php). That package holds the
merge code. It knows nothing about Laravel, WebSockets, or Hocuspocus.

---

This document uses ASD-STE100 Simplified Technical English: short sentences,
active voice, one meaning for each word. Keep this style if you change the text.
