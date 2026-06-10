<?php

namespace App\Enums;

enum ConnectorBlueprintStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Active = 'active';
    case NeedsDev = 'needs_dev';
    case Failed = 'failed';
}
