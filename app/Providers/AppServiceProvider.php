<?php

namespace App\Providers;

use App\Models\ClientDashboard;
use Illuminate\Support\Facades\Route;
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
        Route::bind('dashboard', function (string $value): ClientDashboard {
            if (ctype_digit($value)) {
                return ClientDashboard::query()->findOrFail($value);
            }

            return ClientDashboard::query()
                ->where('slug', $value)
                ->firstOrFail();
        });
    }
}
