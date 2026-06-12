<?php

namespace App\Providers;

use App\Listeners\LogAiAgentPerformance;
use App\Models\ClientDashboard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

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

        $performanceListener = LogAiAgentPerformance::class;

        Event::listen(InvokingTool::class, [$performanceListener, 'handleInvokingTool']);
        Event::listen(ToolInvoked::class, [$performanceListener, 'handleToolInvoked']);
        Event::listen(AgentPrompted::class, [$performanceListener, 'handleAgentPrompted']);
    }
}
