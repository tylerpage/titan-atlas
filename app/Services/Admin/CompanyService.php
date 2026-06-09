<?php

namespace App\Services\Admin;

use App\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Company
    {
        return Company::query()->create([
            'name' => $data['name'],
            'slug' => $this->resolveSlug($data['name'], $data['slug'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);

        return $company->fresh();
    }

    public function delete(Company $company): void
    {
        if ($company->clientDashboards()->exists()) {
            throw ValidationException::withMessages([
                'company' => 'Remove or reassign dashboards before deleting this company.',
            ]);
        }

        $company->users()->detach();
        $company->delete();
    }

    protected function resolveSlug(string $name, ?string $slug): string
    {
        $base = Str::slug($slug ?: $name);

        if ($base === '') {
            $base = 'company';
        }

        $candidate = $base;
        $suffix = 1;

        while (Company::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
