<?php

use App\Http\Controllers\Admin\AnalyticsReportController as AdminAnalyticsReportController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\ConnectionController as AdminConnectionController;
use App\Http\Controllers\Admin\AiConnectorController as AdminAiConnectorController;
use App\Http\Controllers\Admin\GatheredAnalyticsController as AdminGatheredAnalyticsController;
use App\Http\Controllers\Admin\ConnectorApiLogController as AdminConnectorApiLogController;
use App\Http\Controllers\Admin\ConnectorBlueprintController as AdminConnectorBlueprintController;
use App\Http\Controllers\Admin\ConnectorBuilderController as AdminConnectorBuilderController;
use App\Http\Controllers\Admin\CoverPageBlockController as AdminCoverPageBlockController;
use App\Http\Controllers\Admin\CoverPageController as AdminCoverPageController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FeedbackSubmissionController as AdminFeedbackSubmissionController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\Admin\GoogleOAuthController as AdminGoogleOAuthController;
use App\Http\Controllers\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\UserInvitationController as AdminUserInvitationController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Admin\UserPasswordResetController;
use App\Http\Controllers\Client\AnalyticsReportController as ClientAnalyticsReportController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\DashboardShareController;
use App\Http\Controllers\Client\SavedDashboardController as ClientSavedDashboardController;
use App\Http\Controllers\HomeController;
use App\Models\ClientDashboard;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    Route::get('/invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [AcceptInvitationController::class, 'store'])->name('invitations.store');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/admin/google/oauth/callback', [AdminGoogleOAuthController::class, 'callback'])
    ->name('admin.google.oauth.callback');

Route::get('/s/{code}', [DashboardShareController::class, 'show'])->name('dashboard.share.show');

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::post('/impersonate/stop', [AdminImpersonationController::class, 'destroy'])->name('admin.impersonate.destroy');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/feedback', [AdminFeedbackSubmissionController::class, 'index'])->name('feedback.index');
        Route::get('/feedback/{feedback}', [AdminFeedbackSubmissionController::class, 'show'])->name('feedback.show');
        Route::post('/feedback/{feedback}', [AdminFeedbackSubmissionController::class, 'update'])->name('feedback.update');
        Route::get('/feedback-attachments/{attachment}/download', [AdminFeedbackSubmissionController::class, 'downloadAttachment'])
            ->name('feedback.attachments.download');
        Route::get('/connector-api-logs', [AdminConnectorApiLogController::class, 'index'])->name('connector-api-logs.index');
        Route::get('/connector-api-logs/{connectorApiLog}', [AdminConnectorApiLogController::class, 'show'])->name('connector-api-logs.show');
        Route::get('/gathered-analytics', [AdminGatheredAnalyticsController::class, 'index'])->name('gathered-analytics.index');
        Route::get('/gathered-analytics/payloads/{payload}', [AdminGatheredAnalyticsController::class, 'showPayload'])->name('gathered-analytics.payloads.show');
        Route::get('/gathered-analytics/metrics/{metric}', [AdminGatheredAnalyticsController::class, 'showMetric'])->name('gathered-analytics.metrics.show');
        Route::get('/companies', [AdminCompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/create', [AdminCompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [AdminCompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}', [AdminCompanyController::class, 'show'])->name('companies.show');
        Route::get('/companies/{company}/edit', [AdminCompanyController::class, 'edit'])->name('companies.edit');
        Route::post('/companies/{company}', [AdminCompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [AdminCompanyController::class, 'destroy'])->name('companies.destroy');

        Route::post('/companies/{company}/invitations', [AdminUserInvitationController::class, 'store'])->name('companies.invitations.store');
        Route::post('/companies/{company}/invitations/{invitation}/resend', [AdminUserInvitationController::class, 'resend'])->name('companies.invitations.resend');
        Route::delete('/companies/{company}/invitations/{invitation}', [AdminUserInvitationController::class, 'destroy'])->name('companies.invitations.destroy');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::post('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/password-reset', [UserPasswordResetController::class, 'store'])->name('users.password-reset.store');

        Route::get('/ai-connectors', [AdminAiConnectorController::class, 'index'])->name('ai-connectors.index');
        Route::get('/ai-connectors/import', [AdminAiConnectorController::class, 'importForm'])->name('ai-connectors.import');
        Route::post('/ai-connectors/import', [AdminAiConnectorController::class, 'import'])->name('ai-connectors.import.store');
        Route::get('/ai-connectors/create', [AdminAiConnectorController::class, 'create'])->name('ai-connectors.create');
        Route::post('/ai-connectors', [AdminAiConnectorController::class, 'store'])->name('ai-connectors.store');
        Route::get('/ai-connectors/{blueprint}/export', [AdminAiConnectorController::class, 'export'])->name('ai-connectors.export');
        Route::get('/companies/{company}/ai-connectors', [AdminAiConnectorController::class, 'companyIndex'])->name('companies.ai-connectors.index');
        Route::get('/ai-connectors/{blueprint}/edit', [AdminAiConnectorController::class, 'edit'])->name('ai-connectors.edit');
        Route::post('/ai-connectors/{blueprint}', [AdminAiConnectorController::class, 'update'])->name('ai-connectors.update');
        Route::delete('/ai-connectors/{blueprint}', [AdminAiConnectorController::class, 'destroy'])->name('ai-connectors.destroy');
        Route::post('/ai-connectors/{blueprint}/share', [AdminAiConnectorController::class, 'share'])->name('ai-connectors.share');
        Route::post('/ai-connectors/{blueprint}/share-global', [AdminAiConnectorController::class, 'shareGlobally'])->name('ai-connectors.share-global');
        Route::get('/ai-connectors/{blueprint}/chat', [AdminAiConnectorController::class, 'resumeChat'])->name('ai-connectors.chat');
        Route::get('/dashboards/{dashboard}/connections/from-template/{blueprint}', [AdminAiConnectorController::class, 'createConnection'])->name('dashboards.connections.from-template');
        Route::post('/dashboards/{dashboard}/connections/from-template/{blueprint}', [AdminAiConnectorController::class, 'storeConnection'])->name('dashboards.connections.from-template.store');
        Route::post('/dashboards/{dashboard}/connections/from-template/{blueprint}/test', [AdminAiConnectorController::class, 'testConnection'])->name('dashboards.connections.from-template.test');

        Route::get('/dashboards', [AdminDashboardController::class, 'index'])->name('dashboards.index');
        Route::get('/dashboards/create', [AdminDashboardController::class, 'create'])->name('dashboards.create');
        Route::post('/dashboards', [AdminDashboardController::class, 'store'])->name('dashboards.store');
        Route::get('/dashboards/{dashboard}', [AdminDashboardController::class, 'show'])->name('dashboards.show');
        Route::get('/dashboards/{dashboard}/edit', [AdminDashboardController::class, 'edit'])->name('dashboards.edit');
        Route::post('/dashboards/{dashboard}', [AdminDashboardController::class, 'update'])->name('dashboards.update');
        Route::get('/dashboards/{dashboard}/connections/create', [AdminDashboardController::class, 'createConnection'])->name('dashboards.connections.create');
        Route::get('/dashboards/{dashboard}/connections/ai-create/{session?}', [AdminConnectorBuilderController::class, 'aiCreate'])->name('dashboards.connections.ai-create');
        Route::post('/dashboards/{dashboard}/connector-builder/sessions', [AdminConnectorBuilderController::class, 'sendMessage'])->name('dashboards.connector-builder.sessions.store');
        Route::get('/dashboards/{dashboard}/connector-builder/sessions/{session}/status', [AdminConnectorBuilderController::class, 'sessionStatus'])->name('dashboards.connector-builder.sessions.status');
        Route::post('/dashboards/{dashboard}/connector-blueprints/{blueprint}/revert-dashboard', [AdminConnectorBuilderController::class, 'revertDashboard'])->name('dashboards.connector-blueprints.revert-dashboard');
        Route::post('/dashboards/{dashboard}/connections', [AdminDashboardController::class, 'storeConnection'])->name('dashboards.connections.store');
        Route::post('/connections/test', [AdminDashboardController::class, 'testConnection'])->name('connections.test');
        Route::get('/google/oauth/redirect', [AdminGoogleOAuthController::class, 'redirect'])->name('google.oauth.redirect');
        Route::get('/connections/{connection}', [AdminConnectionController::class, 'show'])->name('connections.show');
        Route::get('/connections/{connection}/edit', [AdminConnectionController::class, 'edit'])->name('connections.edit');
        Route::post('/connections/{connection}', [AdminConnectionController::class, 'update'])->name('connections.update');
        Route::delete('/connections/{connection}', [AdminConnectionController::class, 'destroy'])->name('connections.destroy');
        Route::post('/connections/{connection}/test', [AdminConnectionController::class, 'test'])->name('connections.test-existing');
        Route::post('/connections/{connection}/sync', [AdminConnectionController::class, 'sync'])->name('connections.sync');
        Route::post('/connections/{connection}/backfill', [AdminConnectionController::class, 'backfill'])->name('connections.backfill');
        Route::post('/connections/{connection}/clear-data', [AdminConnectionController::class, 'clearData'])->name('connections.clear-data');
        Route::post('/connections/{connection}/rebuild-dashboard', [AdminConnectionController::class, 'rebuildDashboard'])->name('connections.rebuild-dashboard');
        Route::get('/connector-blueprints/{blueprint}', [AdminConnectorBlueprintController::class, 'show'])->name('connector-blueprints.show');
        Route::get('/ai-connectors/{blueprint}', [AdminConnectorBlueprintController::class, 'show'])->name('ai-connectors.show');
        Route::post('/connector-blueprints/{blueprint}/test', [AdminConnectorBlueprintController::class, 'test'])->name('connector-blueprints.test');
        Route::post('/connector-blueprints/{blueprint}/activate', [AdminConnectorBlueprintController::class, 'activate'])->name('connector-blueprints.activate');
        Route::get('/dashboards/{dashboard}/cover-pages', [AdminCoverPageController::class, 'index'])->name('dashboards.cover-pages.index');
        Route::get('/dashboards/{dashboard}/cover-pages/create', [AdminCoverPageController::class, 'create'])->name('dashboards.cover-pages.create');
        Route::post('/dashboards/{dashboard}/cover-pages', [AdminCoverPageController::class, 'store'])->name('dashboards.cover-pages.store');
        Route::get('/cover-pages/{coverPage}/edit', [AdminCoverPageController::class, 'edit'])->name('cover-pages.edit');
        Route::post('/cover-pages/{coverPage}', [AdminCoverPageController::class, 'update'])->name('cover-pages.update');
        Route::post('/cover-pages/{coverPage}/activate', [AdminCoverPageController::class, 'activate'])->name('cover-pages.activate');
        Route::post('/cover-pages/{coverPage}/duplicate', [AdminCoverPageController::class, 'duplicate'])->name('cover-pages.duplicate');
        Route::delete('/cover-pages/{coverPage}', [AdminCoverPageController::class, 'destroy'])->name('cover-pages.destroy');
        Route::post('/cover-pages/{coverPage}/blocks', [AdminCoverPageBlockController::class, 'store'])->name('cover-pages.blocks.store');
        Route::post('/cover-page-blocks/{block}', [AdminCoverPageBlockController::class, 'update'])->name('cover-page-blocks.update');
        Route::delete('/cover-page-blocks/{block}', [AdminCoverPageBlockController::class, 'destroy'])->name('cover-page-blocks.destroy');
        Route::post('/cover-page-blocks/{block}/move-up', [AdminCoverPageBlockController::class, 'moveUp'])->name('cover-page-blocks.move-up');
        Route::post('/cover-page-blocks/{block}/move-down', [AdminCoverPageBlockController::class, 'moveDown'])->name('cover-page-blocks.move-down');
        Route::post('/cover-pages/{coverPage}/blocks/reorder', [AdminCoverPageBlockController::class, 'reorder'])->name('cover-pages.blocks.reorder');
        Route::post('/cover-page-blocks/{block}/import-csv', [AdminCoverPageBlockController::class, 'importCsv'])->name('cover-page-blocks.import-csv');
        Route::get('/dashboards/{dashboard}/reports', [AdminAnalyticsReportController::class, 'index'])->name('dashboards.reports.index');
        Route::get('/dashboards/{dashboard}/reports/ask/{session?}', [AdminAnalyticsReportController::class, 'ask'])->name('dashboards.reports.ask');
        Route::get('/dashboards/{dashboard}/reports/sessions/{session}/status', [AdminAnalyticsReportController::class, 'sessionStatus'])->name('dashboards.reports.sessions.status');
        Route::post('/dashboards/{dashboard}/reports/sessions', [AdminAnalyticsReportController::class, 'sendMessage'])->name('dashboards.reports.sessions.store');
        Route::post('/dashboards/{dashboard}/reports/{report}/place', [AdminAnalyticsReportController::class, 'place'])->name('dashboards.reports.place');
        Route::post('/reports/{report}/archive', [AdminAnalyticsReportController::class, 'archive'])->name('reports.archive');
        Route::post('/reports/{report}/restore', [AdminAnalyticsReportController::class, 'restore'])->name('reports.restore');
        Route::post('/impersonate/{user}', [AdminImpersonationController::class, 'store'])->name('impersonate.store');
    });

    Route::get('/dashboards/{dashboard}', function (ClientDashboard $dashboard) {
        return redirect()->route('client.dashboard.show', [
            'dashboard' => $dashboard,
            ...request()->query(),
        ], 301);
    });

    Route::patch('/saved-dashboard-blocks/{block}', [ClientSavedDashboardController::class, 'updateBlock'])->name('client.dashboard.saved.blocks.update');
    Route::delete('/saved-dashboard-blocks/{block}', [ClientSavedDashboardController::class, 'destroyBlock'])->name('client.dashboard.saved.blocks.destroy');

    Route::get('/{dashboard:slug}', [ClientDashboardController::class, 'show'])
        ->name('client.dashboard.show')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::post('/{dashboard:slug}/share', [DashboardShareController::class, 'store'])
        ->name('client.dashboard.share')
        ->where('dashboard', '[a-z0-9\\-]+');

    Route::get('/{dashboard:slug}/ai/sessions', [ClientAnalyticsReportController::class, 'sessions'])
        ->name('client.dashboard.ai.sessions')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::get('/{dashboard:slug}/ai/sessions/{session}/status', [ClientAnalyticsReportController::class, 'sessionStatus'])
        ->name('client.dashboard.ai.sessions.status')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::get('/{dashboard:slug}/ai/{session?}', [ClientAnalyticsReportController::class, 'chat'])
        ->name('client.dashboard.ai.chat')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::post('/{dashboard:slug}/ai/sessions', [ClientAnalyticsReportController::class, 'sendMessage'])
        ->name('client.dashboard.ai.sessions.store')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::post('/{dashboard:slug}/ai/pin-board', [ClientAnalyticsReportController::class, 'quickCreateBoardAndPin'])
        ->name('client.dashboard.ai.pin-board')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::post('/{dashboard:slug}/ai/reports/{report}/pin', [ClientAnalyticsReportController::class, 'pinReport'])
        ->name('client.dashboard.ai.reports.pin')
        ->where('dashboard', '[a-z0-9\\-]+');

    Route::get('/{dashboard:slug}/saved', [ClientSavedDashboardController::class, 'index'])
        ->name('client.dashboard.saved.index')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::post('/{dashboard:slug}/saved', [ClientSavedDashboardController::class, 'store'])
        ->name('client.dashboard.saved.store')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::get('/{dashboard:slug}/saved/{board}', [ClientSavedDashboardController::class, 'show'])
        ->name('client.dashboard.saved.show')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::patch('/{dashboard:slug}/saved/{board}', [ClientSavedDashboardController::class, 'update'])
        ->name('client.dashboard.saved.update')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::delete('/{dashboard:slug}/saved/{board}', [ClientSavedDashboardController::class, 'destroy'])
        ->name('client.dashboard.saved.destroy')
        ->where('dashboard', '[a-z0-9\\-]+');
    Route::post('/{dashboard:slug}/saved/{board}/blocks', [ClientSavedDashboardController::class, 'pinBlock'])
        ->name('client.dashboard.saved.blocks.store')
        ->where('dashboard', '[a-z0-9\\-]+');
});
