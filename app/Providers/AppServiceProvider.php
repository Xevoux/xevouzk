<?php

namespace App\Providers;

use App\View\Composers\NavbarStatusComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Status koneksi Polygon untuk navbar status-pill.
        View::composer('layouts.partials.navbar', NavbarStatusComposer::class);
    }
}
