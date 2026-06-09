<?php

namespace App\Models;

use App\Enums\CoverPageBlockType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverPageBlock extends Model
{
    protected $fillable = [
        'cover_page_id',
        'block_type',
        'sort_order',
        'column_span',
        'configuration',
    ];

    protected function casts(): array
    {
        return [
            'block_type' => CoverPageBlockType::class,
            'configuration' => 'array',
        ];
    }

    public function coverPage(): BelongsTo
    {
        return $this->belongsTo(CoverPage::class);
    }
}
