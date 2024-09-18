<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Interfaces\RepositoryInterfaces\BookingRepositoryInterface;

use App\Repositories\BookingRepository;

class RepositoryInterfaceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // binding the repository interfaces with repository class for dependency injection

        $this->app->bind(BookingRepositoryInterface::class, BookingRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}