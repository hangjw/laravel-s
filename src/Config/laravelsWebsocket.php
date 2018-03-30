<?php
return [
    'listen_ip'     => env('LARAVELS_SOCKET_LISTEN_IP', '127.0.0.1'),
    'listen_port'   => env('LARAVELS_SOCKET_LISTEN_PORT', 5201),
    'enable_gzip'   => env('LARAVELS_SOCKET_ENABLE_GZIP', false),
    'server'        => env('LARAVELS_SOCKET_SERVER', 'laravels-http'),
    'swoole'        => [
        'dispatch_mode' => 2,
        'max_request'   => 3000,
        'daemonize'     => 1,
        'pid_file'      => storage_path('laravelsWebsocket.pid'),
        'log_file'      => storage_path('logs/swoole-' . date('Y-m-d') . '.log'),
        'log_level'     => 4,
        'document_root' => base_path('public'),
        /**
         * The other settings of Swoole like worker_num, backlog ...
         * @see https://wiki.swoole.com/wiki/page/274.html  Chinese
         * @see https://www.swoole.co.uk/docs/modules/swoole-server/configuration  English
         */
    ],
];
