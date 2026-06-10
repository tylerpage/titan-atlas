<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Services\Admin\CompanyService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Companies/Index', [
            'companies' => Company::query()
                ->withCount(['clientDashboards', 'users'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Companies/Create');
    }

    public function store(StoreCompanyRequest $request, CompanyService $service): RedirectResponse
    {
        $company = $service->create($request->validated());

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('status', 'Company "'.$company->name.'" created.');
    }

    public function show(Company $company): Response
    {
        $company->load([
            'clientDashboards' => fn ($q) => $q->latest(),
            'users' => fn ($q) => $q->orderBy('name'),
            'invitations' => fn ($q) => $q
                ->whereNull('accepted_at')
                ->latest(),
        ]);

        return Inertia::render('Admin/Companies/Show', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'created_at' => $company->created_at?->toIso8601String(),
                'dashboards' => $company->clientDashboards->map(fn ($dashboard) => [
                    'id' => $dashboard->id,
                    'name' => $dashboard->name,
                    'slug' => $dashboard->slug,
                ])->values(),
                'users' => $company->users->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ])->values(),
                'invitations' => $company->invitations->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'dashboard_ids' => $invitation->dashboard_ids ?? [],
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                    'is_expired' => $invitation->isExpired(),
                ])->values(),
            ],
            'roles' => collect(UserRole::cases())
                ->mapWithKeys(fn ($role) => [$role->value => $role->label()])
                ->all(),
        ]);
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('Admin/Companies/Edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
        ]);
    }

    public function update(
        UpdateCompanyRequest $request,
        Company $company,
        CompanyService $service,
    ): RedirectResponse {
        $service->update($company, $request->validated());

        return redirect()
            ->route('admin.companies.show', $company)
            ->with('status', 'Company "'.$company->name.'" updated.');
    }

    public function destroy(Company $company, CompanyService $service): RedirectResponse
    {
        $name = $company->name;

        try {
            $service->delete($company);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.companies.index')
            ->with('status', 'Company "'.$name.'" deleted.');
    }
}
