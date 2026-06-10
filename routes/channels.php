<?php

use App\Models\AnalyticsReportSession;
use App\Models\ConnectorBuilderSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('ai.report-session.{sessionId}', function ($user, int $sessionId) {
    $session = AnalyticsReportSession::query()->find($sessionId);

    if ($session === null) {
        return false;
    }

    return (int) $user->id === (int) $session->user_id;
});

Broadcast::channel('ai.connector-builder-session.{sessionId}', function ($user, int $sessionId) {
    if (! $user->isAdmin()) {
        return false;
    }

    $session = ConnectorBuilderSession::query()->find($sessionId);

    if ($session === null) {
        return false;
    }

    return (int) $user->id === (int) $session->user_id;
});
