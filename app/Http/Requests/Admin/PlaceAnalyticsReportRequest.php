<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlaceAnalyticsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cover_page_id' => ['required', 'integer', 'exists:cover_pages,id'],
            'column_span' => ['nullable', 'integer', 'in:1,2'],
        ];
    }
}
