<?php

namespace App\Services\Admin;

use App\Models\ClientDashboard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UpdateClientDashboardService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClientDashboard $dashboard, array $data, ?UploadedFile $logo = null): ClientDashboard
    {
        $dashboard->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'timezone' => $data['timezone'] ?? $dashboard->timezone,
            'default_date_range' => $data['default_date_range'] ?? $dashboard->default_date_range,
            'show_summary_tab' => array_key_exists('show_summary_tab', $data)
                ? (bool) $data['show_summary_tab']
                : $dashboard->show_summary_tab,
            'attribution_window_days' => $data['attribution_window_days'] ?? $dashboard->attribution_window_days,
            'primary_color' => $data['primary_color'] ?? $dashboard->primary_color,
            'secondary_color' => $data['secondary_color'] ?? $dashboard->secondary_color,
            'custom_domain' => $data['custom_domain'] ?? null,
            'powered_by_text' => config('titan.branding.powered_by_text'),
            'show_powered_by' => true,
        ]);

        if (! empty($data['remove_logo'])) {
            $this->deleteLogo($dashboard);
            $dashboard->logo_path = null;
        }

        if ($logo) {
            $this->deleteLogo($dashboard);
            $dashboard->logo_path = $logo->store('dashboard-logos/'.$dashboard->id, 'public');
        }

        $dashboard->save();

        return $dashboard->fresh();
    }

    protected function deleteLogo(ClientDashboard $dashboard): void
    {
        if ($dashboard->logo_path && Storage::disk('public')->exists($dashboard->logo_path)) {
            Storage::disk('public')->delete($dashboard->logo_path);
        }
    }
}
