<?php

namespace Cfrd\Lti;

use Illuminate\Support\ServiceProvider;

class CfrdLtiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cfrd-lti.php', 'cfrd-lti');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/lti.php');
    }
}
