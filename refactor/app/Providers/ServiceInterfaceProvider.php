<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Interfaces\ServiceInterfaces\BookingServiceInterface;

use App\Services\BookingService;

class ServiceInterfaceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // binding the service interfaces with service class for dependency injection
        $this->app->bind(BookingServiceInterface::class, BookingService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}