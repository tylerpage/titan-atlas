<?php

namespace App\Enums;

enum AnalyticsReportSessionStatus: string
{
    case Active = 'active';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
