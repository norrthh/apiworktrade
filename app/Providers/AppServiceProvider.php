<?php

namespace App\Providers;

use App\Services\Work1\CabinetTransport;
use App\Services\Work1\GuzzleCabinetTransport;
use App\Services\Work1\GuzzleWork1Transport;
use App\Services\Work1\Work1Transport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Work1Transport::class, GuzzleWork1Transport::class);
        $this->app->singleton(CabinetTransport::class, GuzzleCabinetTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
