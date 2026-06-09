<?php

namespace Database\Seeders;

use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Enums\WidgetType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\DashboardTemplate;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\User;
use App\Models\WidgetPlacement;
use App\Support\MetricDimensions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TitanSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@titan.test'],
            [
                'name' => 'Titan Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
            ],
        );

        $company = Company::query()->updateOrCreate(
            ['slug' => 'acme-retail'],
            ['name' => 'Acme Retail'],
        );

        $template = DashboardTemplate::query()->updateOrCreate(
            ['slug' => 'ecommerce-performance'],
            [
                'name' => 'Ecommerce Performance',
                'description' => 'Revenue, orders, ad spend, and keyword visibility.',
                'default_widgets' => [
                    WidgetType::Revenue->value,
                    WidgetType::Orders->value,
                    WidgetType::AdSpend->value,
                    WidgetType::Roas->value,
                    WidgetType::TopKeywords->value,
                ],
            ],
        );

        $dashboard = ClientDashboard::query()->updateOrCreate(
            ['company_id' => $company->id, 'slug' => 'main'],
            [
                'dashboard_template_id' => $template->id,
                'name' => 'Acme Main Dashboard',
                'primary_color' => '#1e40af',
                'secondary_color' => '#64748b',
                'powered_by_text' => config('titan.branding.powered_by_text'),
                'show_powered_by' => true,
                'currency' => config('titan.currency', 'USD'),
                'attribution_window_days' => 30,
            ],
        );

        $client = User::query()->updateOrCreate(
            ['email' => 'client@acme.test'],
            [
                'name' => 'Acme Client',
                'password' => Hash::make('password'),
                'role' => UserRole::Client,
            ],
        );

        $company->users()->syncWithoutDetaching([$client->id]);
        $dashboard->users()->syncWithoutDetaching([$client->id]);

        $connection = Connection::query()->updateOrCreate(
            ['client_dashboard_id' => $dashboard->id, 'name' => 'Shopify Store'],
            [
                'connector_type' => ConnectorType::Shopify,
                'encrypted_credentials' => [
                    'shop_domain' => 'demo.myshopify.com',
                    'access_token' => 'demo-token',
                ],
            ],
        );

        foreach ($template->default_widgets ?? [] as $index => $widgetType) {
            WidgetPlacement::query()->updateOrCreate(
                [
                    'client_dashboard_id' => $dashboard->id,
                    'widget_type' => $widgetType,
                ],
                [
                    'title' => WidgetType::from($widgetType)->label(),
                    'sort_order' => $index,
                    'column_span' => in_array($widgetType, [WidgetType::Roas->value, WidgetType::TopKeywords->value], true) ? 2 : 1,
                    'configuration' => WidgetType::from($widgetType)->defaultConfiguration(),
                ],
            );
        }

        foreach (range(0, 14) as $dayOffset) {
            $date = now()->subDays($dayOffset)->toDateString();
            $orderDimensions = ['connection_id' => $connection->id];
            $dimensionHash = MetricDimensions::hash($orderDimensions);
            $revenue = 1200 + ($dayOffset * 37);
            $orders = 8 + ($dayOffset % 5);

            MetricSnapshot::query()->updateOrCreate(
                [
                    'client_dashboard_id' => $dashboard->id,
                    'snapshot_date' => $date,
                    'metric_key' => 'revenue',
                    'dimension_hash' => $dimensionHash,
                ],
                [
                    'metric_value' => $revenue,
                    'currency' => 'USD',
                    'dimensions' => $orderDimensions,
                ],
            );

            MetricSnapshot::query()->updateOrCreate(
                [
                    'client_dashboard_id' => $dashboard->id,
                    'snapshot_date' => $date,
                    'metric_key' => 'orders',
                    'dimension_hash' => $dimensionHash,
                ],
                [
                    'metric_value' => $orders,
                    'currency' => 'USD',
                    'dimensions' => $orderDimensions,
                ],
            );

            RawConnectorPayload::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'resource_type' => 'order',
                    'external_id' => 'demo-order-'.$dayOffset,
                ],
                [
                    'payload' => [
                        'date' => $date,
                        'total' => $revenue / max($orders, 1),
                        'order_number' => '#10'.(14 - $dayOffset),
                        'source' => $dayOffset % 2 === 0 ? 'google' : 'direct',
                        'medium' => $dayOffset % 2 === 0 ? 'cpc' : '(none)',
                        'source_medium' => $dayOffset % 2 === 0 ? 'google / cpc' : 'direct / (none)',
                        'channel' => 'web',
                    ],
                    'payload_hash' => hash('sha256', 'demo-order-'.$dayOffset),
                    'fetched_at' => now(),
                ],
            );

            MetricSnapshot::query()->updateOrCreate(
                [
                    'client_dashboard_id' => $dashboard->id,
                    'snapshot_date' => $date,
                    'metric_key' => 'ad_spend',
                    'dimension_hash' => hash('sha256', '{}'),
                ],
                ['metric_value' => 180 + ($dayOffset * 11), 'currency' => 'USD'],
            );
        }

        MetricSnapshot::query()->updateOrCreate(
            [
                'client_dashboard_id' => $dashboard->id,
                'snapshot_date' => now()->toDateString(),
                'metric_key' => 'keyword_rank',
                'dimension_hash' => hash('sha256', json_encode(['keyword' => 'organic cotton t-shirt'])),
            ],
            [
                'metric_value' => 3,
                'dimensions' => ['keyword' => 'organic cotton t-shirt'],
            ],
        );

        unset($admin, $client);
    }
}
