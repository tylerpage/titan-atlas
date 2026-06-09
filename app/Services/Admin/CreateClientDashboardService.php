<?php

namespace App\Services\Admin;

use App\Enums\WidgetType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\DashboardTemplate;
use App\Models\WidgetPlacement;
use Illuminate\Support\Str;

class CreateClientDashboardService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ClientDashboard
    {
        $company = Company::query()->findOrFail($data['company_id']);
        $slug = $this->resolveSlug($company, $data['name'], $data['slug'] ?? null);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'dashboard_template_id' => $data['dashboard_template_id'] ?? null,
            'name' => $data['name'],
            'slug' => $slug,
            'primary_color' => '#1e40af',
            'secondary_color' => '#64748b',
            'powered_by_text' => config('titan.branding.powered_by_text'),
            'show_powered_by' => true,
            'timezone' => $data['timezone'] ?? 'America/Chicago',
            'currency' => config('titan.currency', 'USD'),
            'default_date_range' => $data['default_date_range'] ?? 'last_30_days',
            'attribution_window_days' => $data['attribution_window_days'] ?? 30,
        ]);

        if (! empty($data['dashboard_template_id'])) {
            $template = DashboardTemplate::query()->find($data['dashboard_template_id']);

            if ($template) {
                $this->seedWidgetsFromTemplate($dashboard, $template);
            }
        }

        return $dashboard;
    }

    protected function resolveSlug(Company $company, string $name, ?string $slug): string
    {
        $base = Str::slug($slug ?: $name);

        if ($base === '') {
            $base = 'dashboard';
        }

        $candidate = $base;
        $suffix = 1;

        while ($company->clientDashboards()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function seedWidgetsFromTemplate(ClientDashboard $dashboard, DashboardTemplate $template): void
    {
        foreach ($template->default_widgets ?? [] as $index => $widgetType) {
            $type = WidgetType::from($widgetType);

            WidgetPlacement::query()->create([
                'client_dashboard_id' => $dashboard->id,
                'widget_type' => $type,
                'title' => $type->label(),
                'sort_order' => $index,
                'column_span' => in_array($widgetType, [WidgetType::Roas->value, WidgetType::TopKeywords->value], true) ? 2 : 1,
                'configuration' => $type->defaultConfiguration(),
                'is_visible' => true,
            ]);
        }
    }
}
