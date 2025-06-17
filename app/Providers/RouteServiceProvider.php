<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Path to redirect after login/registration.
     */
    public const HOME = '/dashboard';

    public function boot(): void
    {
        // You can define custom logic here, if needed
    }
}
