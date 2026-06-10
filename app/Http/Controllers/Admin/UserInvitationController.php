<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserInvitationRequest;
use App\Models\Company;
use App\Models\UserInvitation;
use App\Services\Admin\UserInvitationService;
use Illuminate\Http\RedirectResponse;

class UserInvitationController extends Controller
{
    public function store(
        StoreUserInvitationRequest $request,
        Company $company,
        UserInvitationService $service,
    ): RedirectResponse {
        $invitation = $service->invite($company, $request->user(), $request->validated());

        $message = $invitation
            ? 'Invitation sent to '.$invitation->email.'.'
            : 'Existing user added to '.$company->name.'.';

        return back()->with('status', $message);
    }

    public function resend(
        Company $company,
        UserInvitation $invitation,
        UserInvitationService $service,
    ): RedirectResponse {
        if ($invitation->company_id !== $company->id) {
            abort(404);
        }

        try {
            $service->resend($invitation);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', 'Invitation resent to '.$invitation->email.'.');
    }

    public function destroy(
        Company $company,
        UserInvitation $invitation,
        UserInvitationService $service,
    ): RedirectResponse {
        if ($invitation->company_id !== $company->id) {
            abort(404);
        }

        try {
            $service->revoke($invitation);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', 'Invitation revoked.');
    }
}
