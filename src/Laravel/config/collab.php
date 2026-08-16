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
    | Set `hostname` in local development and Herd or Valet's own certificate
    | for that site is found and used without naming any paths.
    |
    | Leave all of it empty when a reverse proxy handles encryption.
    |
    */

    'hostname' => env('COLLAB_HOSTNAME'),

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
