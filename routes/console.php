<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('titan:sync-connections --type=incremental')
    ->dailyAt(config('titan.sync.daily_at', '02:00'));

Schedule::command('titan:prune-connector-api-logs')->hourly();

if (config('titan.sync.hourly_today', true)) {
    Schedule::command('titan:sync-connections --type=today_hourly')
        ->hourly();
}
