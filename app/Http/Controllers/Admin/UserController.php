<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Admin\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            ...$this->formOptions(),
            'users' => User::query()
                ->with(['companies:id,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'companies' => $user->companies->map(fn (Company $company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                    ])->values(),
                ]),
            'pendingInvitations' => UserInvitation::query()
                ->with('company:id,name')
                ->whereNull('accepted_at')
                ->latest()
                ->get()
                ->map(fn (UserInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'company_id' => $invitation->company_id,
                    'company_name' => $invitation->company->name,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                    'is_expired' => $invitation->isExpired(),
                ])
                ->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create', $this->formOptions());
    }

    public function store(StoreUserRequest $request, UserManagementService $service): RedirectResponse
    {
        $user = $service->create($request->validated());

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'User "'.$user->name.'" created.');
    }

    public function edit(User $user): Response
    {
        $user->load(['companies:id', 'clientDashboards:id']);

        return Inertia::render('Admin/Users/Edit', [
            ...$this->formOptions(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'company_ids' => $user->companies->pluck('id')->values(),
                'dashboard_ids' => $user->clientDashboards->pluck('id')->values(),
            ],
        ]);
    }

    public function update(
        UpdateUserRequest $request,
        User $user,
        UserManagementService $service,
    ): RedirectResponse {
        $user = $service->update($user, $request->validated());

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'User "'.$user->name.'" updated.');
    }

    public function destroy(User $user, UserManagementService $service): RedirectResponse
    {
        $name = $user->name;

        try {
            $service->delete($user, request()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User "'.$name.'" deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'roles' => collect(\App\Enums\UserRole::cases())
                ->mapWithKeys(fn ($role) => [$role->value => $role->label()])
                ->all(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'dashboards' => ClientDashboard::query()
                ->with('company:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (ClientDashboard $dashboard) => [
                    'id' => $dashboard->id,
                    'name' => $dashboard->name,
                    'company_id' => $dashboard->company_id,
                    'company_name' => $dashboard->company->name,
                ]),
        ];
    }
}
