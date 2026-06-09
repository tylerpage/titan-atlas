<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\UserInvitation;
use App\Services\Admin\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = UserInvitation::query()
            ->with('company')
            ->where('token', $token)
            ->firstOrFail();

        if ($invitation->accepted_at !== null) {
            return redirect()
                ->route('login')
                ->with('status', 'This invitation has already been accepted. Please log in.');
        }

        if ($invitation->isExpired()) {
            return Inertia::render('Auth/AcceptInvitation', [
                'invitation' => null,
                'token' => $token,
                'expired' => true,
            ]);
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'invitation' => [
                'email' => $invitation->email,
                'company_name' => $invitation->company->name,
                'role' => $invitation->role,
            ],
            'token' => $token,
            'expired' => false,
        ]);
    }

    public function store(
        AcceptInvitationRequest $request,
        string $token,
        UserInvitationService $service,
    ): RedirectResponse {
        $invitation = UserInvitation::query()
            ->where('token', $token)
            ->firstOrFail();

        try {
            $user = $service->accept($invitation, $request->validated());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        Auth::login($user);

        return redirect()
            ->route('home')
            ->with('status', 'Welcome to '.$invitation->company->name.'!');
    }
}
