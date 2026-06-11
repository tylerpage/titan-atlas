<?php

namespace App\Enums;

enum ConnectorApiLogContext: string
{
    case Sync = 'sync';
    case Test = 'test';
    case Builder = 'builder';
    case Token = 'token';

    public function label(): string
    {
        return match ($this) {
            self::Sync => 'Sync',
            self::Test => 'Credential test',
            self::Builder => 'AI builder',
            self::Token => 'Token request',
        };
    }
}
