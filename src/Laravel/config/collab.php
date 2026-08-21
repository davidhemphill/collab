<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | Where the server listens. Bind to localhost when something in front of it
    | terminates TLS, and to 0.0.0.0 when the server does that itself and has
    | to be reachable from outside the machine.
    |
    */

    'host' => env('COLLAB_HOST', '127.0.0.1'),

    'port' => (int) env('COLLAB_PORT', 1234),

    /*
    |--------------------------------------------------------------------------
    | TLS
    |--------------------------------------------------------------------------
    |
    | Name a certificate and the server speaks wss:// itself, with nothing in
    | front of it. This is the only option on a platform that does not let you
    | configure a web server, because a page served over https cannot open a
    | plain ws:// connection to anything except 127.0.0.1.
    |
    | These are PHP's own SSL context options, so anything PHP accepts may be
    | set here. The shape follows Reverb's: an application will often run both
    | servers, and they should not disagree about how certificates are named.
    |
    | `hostname` defaults to the host in APP_URL, which is almost always the
    | right answer: the server runs beside the application and answers on the
    | same name. In local development that means a secured Herd or Valet site
    | needs no collaboration configuration at all — the certificate for the
    | site is found and used. Set it only when the server answers on some other
    | name.
    |
    | Leave all of it empty when a reverse proxy handles encryption. A hostname
    | with no certificate anywhere is not an error; the server simply stays
    | plain, which is what a proxy in front of it expects.
    |
    */

    'hostname' => env('COLLAB_HOSTNAME') ?: (parse_url((string) env('APP_URL'), PHP_URL_HOST) ?: null),

    'options' => [
        'tls' => [
            'local_cert' => env('COLLAB_TLS_CERT'),
            'local_pk' => env('COLLAB_TLS_KEY'),
            'passphrase' => env('COLLAB_TLS_PASSPHRASE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application seams
    |--------------------------------------------------------------------------
    |
    | The two interfaces the server needs from the host application: who may
    | open a document, and where a document's state lives. Both are resolved
    | from the container, so binding them in a service provider works too.
    |
    */

    'authenticator' => env('COLLAB_AUTHENTICATOR'),

    'store' => env('COLLAB_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | While anyone has a document open, its truth lives in the daemon's memory
    | and the database catches up on a debounce — autosave, basically. A write
    | happens after `quiet_seconds` without an accepted change, or every
    | `max_wait_seconds` while the typing never pauses, and always when the
    | last person leaves. These are Hocuspocus's defaults.
    |
    | A positive sync status therefore means "merged in memory", with the disk
    | write at most `max_wait_seconds` behind. Every browser holds the full
    | document too, and the handshake asks for anything the server is missing,
    | so a crash inside that window heals when any client reconnects.
    |
    */

    'persistence' => [
        'quiet_seconds' => (float) env('COLLAB_STORE_QUIET_SECONDS', 2),
        'max_wait_seconds' => (float) env('COLLAB_STORE_MAX_WAIT_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Every frame arriving here comes from a socket that may not have
    | authenticated yet, so these bound what a single message can cost before
    | anything is allocated for it. The defaults are generous enough that no
    | real document reaches them.
    |
    */

    'limits' => [
        'frame_bytes' => (int) env('COLLAB_MAX_FRAME_BYTES', 16 * 1024 * 1024),
        'awareness_clients' => (int) env('COLLAB_MAX_AWARENESS_CLIENTS', 512),
        'awareness_state_bytes' => (int) env('COLLAB_MAX_AWARENESS_STATE_BYTES', 64 * 1024),
    ],
];
