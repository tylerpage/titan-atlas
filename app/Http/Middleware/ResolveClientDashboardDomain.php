<?php

namespace App\Http\Middleware;

use App\Models\ClientDashboard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveClientDashboardDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host === $appHost || $host === 'localhost' || $host === '127.0.0.1') {
            return $next($request);
        }

        $dashboard = ClientDashboard::query()
            ->where('custom_domain', $host)
            ->first();

        if ($dashboard) {
            $request->attributes->set('resolved_dashboard', $dashboard);
        }

        return $next($request);
    }
}
