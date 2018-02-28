<?php

use Hangjw\LaravelS\LaravelS;

$input = file_get_contents('php://stdin');
$cfg = json_decode($input, true);

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . substr(str_replace('\\', '/', $class), 16) . '.php';
    if (is_readable($file)) {
        require $file;
        return true;
    }
    return false;
});

$s = LaravelS::getInstance($cfg['svrConf'], $cfg['laravelConf']);
$s->run();