<?php

namespace App\Http\Middleware;

use App\Enums\FeedbackStatus;
use App\Models\FeedbackSubmission;
use App\Services\Auth\ImpersonationService;
use App\Services\Google\GoogleOAuthPendingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $impersonation = app(ImpersonationService::class);
        $impersonator = $impersonation->impersonator();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role->value,
                    'is_admin' => $request->user()->isAdmin(),
                ] : null,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
                'focused_block_id' => fn () => $request->session()->get('focused_block_id'),
                'google_oauth' => fn () => $request->session()->get(GoogleOAuthPendingSession::FLASH_KEY),
            ],
            'impersonation' => [
                'active' => $impersonation->isImpersonating(),
                'impersonator_name' => $impersonator?->name,
            ],
            'app' => [
                'name' => config('app.name', 'Atlas'),
                'ai_name' => config('titan.branding.ai_assistant_name', 'TitanAI'),
            ],
            'broadcast' => fn () => $this->broadcastConfig(),
            'feedback' => [
                'reasons' => \App\Enums\FeedbackReason::options(),
                'pending_count' => $this->pendingFeedbackCount($request),
            ],
        ];
    }

    protected function pendingFeedbackCount(Request $request): int
    {
        if (! $request->user()?->isAdmin()) {
            return 0;
        }

        if (! $this->feedbackTableExists()) {
            return 0;
        }

        return FeedbackSubmission::query()
            ->where('status', FeedbackStatus::Pending->value)
            ->count();
    }

    protected function feedbackTableExists(): bool
    {
        return Cache::remember(
            'schema.feedback_submissions.exists',
            now()->addHour(),
            fn () => Schema::hasTable('feedback_submissions'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function broadcastConfig(): array
    {
        $connection = config('broadcasting.default');
        $enabled = is_string($connection)
            && $connection !== ''
            && ! in_array($connection, ['null', 'log'], true);

        if (! $enabled) {
            return ['enabled' => false];
        }

        $reverb = config('broadcasting.connections.reverb', []);
        $options = $reverb['options'] ?? [];
        $host = (string) ($options['host'] ?? 'localhost');

        // When browsing via ngrok/HTTPS tunnel, localhost Reverb is unreachable from the browser.
        if ($host === 'localhost' && request()->secure()) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'key' => $reverb['key'] ?? null,
            'host' => $host,
            'port' => (int) ($options['port'] ?? 8080),
            'scheme' => $options['scheme'] ?? 'http',
        ];
    }
}
