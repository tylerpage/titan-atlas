<?php

namespace App\Enums;

enum FeedbackStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Reviewed => 'Reviewed',
        };
    }
}
