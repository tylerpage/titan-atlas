<?php

namespace App\Services\AI;

use App\Events\AiReportSessionUpdated;
use App\Events\ConnectorBuilderSessionUpdated;
use App\Models\AnalyticsReportSession;
use App\Models\ConnectorBuilderSession;

class AiBroadcastService
{
    public function enabled(): bool
    {
        $connection = config('broadcasting.default');

        return is_string($connection)
            && $connection !== ''
            && ! in_array($connection, ['null', 'log'], true);
    }

    public function reportSessionUpdated(AnalyticsReportSession $session): void
    {
        if (! $this->enabled()) {
            return;
        }

        $session->loadCount('messages');

        broadcast(new AiReportSessionUpdated(
            sessionId: $session->id,
            status: $session->status->value,
            messagesCount: (int) $session->messages_count,
        ));
    }

    public function connectorBuilderSessionUpdated(ConnectorBuilderSession $session): void
    {
        if (! $this->enabled()) {
            return;
        }

        $session->loadCount('messages');

        broadcast(new ConnectorBuilderSessionUpdated(
            sessionId: $session->id,
            status: $session->status->value,
            messagesCount: (int) $session->messages_count,
        ));
    }
}
