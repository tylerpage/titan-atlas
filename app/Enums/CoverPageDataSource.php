<?php

namespace App\Enums;

enum CoverPageDataSource: string
{
    case Manual = 'manual';
    case Metric = 'metric';
    case Report = 'report';
}
