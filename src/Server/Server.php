<?php

namespace Hangjw\LaravelS\Server;

class Server
{
    protected $conf;
    protected $swoole;

    public function run()
    {
        $this->bind();
        $this->swoole->start();
    }

    protected function setProcessTitle($title)
    {
        if (PHP_OS === 'Darwin') {
            return;
        }
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        } elseif (function_exists('\swoole_set_process_name')) {
            \swoole_set_process_name($title);
        }
    }

}