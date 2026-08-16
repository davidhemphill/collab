<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    |
    | Where the daemon listens. It sits behind a reverse proxy that terminates
    | TLS and forwards the WebSocket upgrade, so binding to localhost is the
    | right default — a collaboration server reachable from the internet
    | without a proxy in front is almost never intended.
    |
    */

    'host' => env('COLLAB_HOST', '127.0.0.1'),

    'port' => (int) env('COLLAB_PORT', 1234),

    /*
    |--------------------------------------------------------------------------
    | TLS
    |--------------------------------------------------------------------------
    |
    | Set a certificate and the server speaks wss:// itself, with no proxy in
    | front of it. This is the only option on a platform that does not let you
    | configure one, and a page served over https cannot open a plain ws://
    | connection to anything except localhost.
    |
    | Leave these empty when something ahead of the server terminates TLS.
    |
    */

    'tls' => [
        'certificate' => env('COLLAB_TLS_CERTIFICATE'),
        'key' => env('COLLAB_TLS_KEY'),
        'passphrase' => env('COLLAB_TLS_PASSPHRASE'),
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
