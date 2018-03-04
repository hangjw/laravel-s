<?php

namespace Hangjw\LaravelS\Illuminate;

use Illuminate\Support\ServiceProvider;

class LaravelSServiceProvider extends ServiceProvider
{

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../Config/laravelsHttp.php' => base_path('config/laravelsHttp.php'),
            __DIR__ . '/../Config/laravelsWebsocket.php' => base_path('config/laravelsWebsocket.php'),
        ]);

    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/laravelsHttp.php', 'laravelsHttp',
            __DIR__ . '/../Config/laravelsWebsocket.php', 'laravelsWebsocket'
        );

        $this->commands(LaravelSCommand::class);
    }

}