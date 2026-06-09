<?php

namespace App\Enums;

enum SyncRunType: string
{
    case Backfill = 'backfill';
    case Incremental = 'incremental';
    case Manual = 'manual';
    case TodayHourly = 'today_hourly';
}
