<?php

namespace App\Http\Requests\Client;

use App\Models\SavedDashboard;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $board = $this->route('board');

        return $board instanceof SavedDashboard
            && ($this->user()?->canAccessDashboard($board->dashboard) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
