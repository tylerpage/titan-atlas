<?php

namespace App\Enums;

enum ConnectorBuilderSessionStatus: string
{
    case Active = 'active';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
