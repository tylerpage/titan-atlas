<?php

namespace App\Enums;

enum FeedbackReason: string
{
    case DataWrong = 'data_wrong';
    case FeatureRequest = 'feature_request';
    case NiceToHave = 'nice_to_have';
    case Confused = 'confused';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DataWrong => 'Data looks wrong',
            self::FeatureRequest => 'Feature request',
            self::NiceToHave => 'Nice to have',
            self::Confused => "I don't get this",
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $reason) => [
                'value' => $reason->value,
                'label' => $reason->label(),
            ])
            ->values()
            ->all();
    }
}
