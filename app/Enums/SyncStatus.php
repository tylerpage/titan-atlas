<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Success => 'Success',
            self::Partial => 'Partial',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed], true);
    }
}
