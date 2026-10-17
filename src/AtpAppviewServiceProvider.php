<?php

namespace SocialDept\AtpAppview;

use Illuminate\Support\ServiceProvider;

class AtpAppviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atp-appview.php', 'atp-appview');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/atp-appview.php' => config_path('atp-appview.php'),
            ], 'atp-appview-config');
        }
    }
}
