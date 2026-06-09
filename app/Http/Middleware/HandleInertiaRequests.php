<?php

namespace App\Http\Middleware;

use App\Enums\FeedbackStatus;
use App\Models\FeedbackSubmission;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\Request;
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
                'focused_block_id' => fn () => $request->session()->get('focused_block_id'),
            ],
            'impersonation' => [
                'active' => $impersonation->isImpersonating(),
                'impersonator_name' => $impersonator?->name,
            ],
            'app' => [
                'name' => config('app.name', 'Atlas'),
                'ai_name' => config('titan.branding.ai_assistant_name', 'TitanAI'),
            ],
            'feedback' => [
                'reasons' => \App\Enums\FeedbackReason::options(),
                'pending_count' => $this->pendingFeedbackCount($request),
            ],
        ];
    }

    protected function pendingFeedbackCount(Request $request): int
    {
        if (! $request->user()?->isAdmin() || ! Schema::hasTable('feedback_submissions')) {
            return 0;
        }

        return FeedbackSubmission::query()
            ->where('status', FeedbackStatus::Pending->value)
            ->count();
    }
}
