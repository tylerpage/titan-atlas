<?php

namespace App\Http\Requests\Client;

use App\Models\SavedDashboardBlock;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedDashboardBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $block = $this->route('block');

        return $block instanceof SavedDashboardBlock
            && ($this->user()?->canAccessDashboard($block->savedDashboard->dashboard) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'column_span' => ['nullable', 'integer', 'in:1,2'],
        ];
    }
}
