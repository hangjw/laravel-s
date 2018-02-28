<?php

namespace Hangjw\LaravelS\Illuminate;

use Illuminate\Support\ServiceProvider;

class LaravelSServiceProvider extends ServiceProvider
{

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../Config/laravels.php' => base_path('config/laravels.php'),
        ]);

    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/laravels.php', 'laravels'
        );

        $this->commands(LaravelSCommand::class);
    }

}