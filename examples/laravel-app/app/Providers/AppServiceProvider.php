<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accord\ControlController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The conformance control API. The route always exists (so routes can be cached); it answers
        // only in a process started with ACCORD_CONTROL_ENABLED=true (serve.sh's control server).
        if (!$this->app->routesAreCached()) {
            Route::match(['GET', 'POST'], '/{action}', ControlController::class)->where('action', 'token|reset|compact|age-device');
        }
    }
}
