<?php

namespace App\Agents;

use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBuilderSession;
use App\Models\User;

class ConnectorBuilderAgentContext
{
    public function __construct(
        public ConnectorBuilderSession $session,
        public ClientDashboard $dashboard,
        public User $user,
        public ?ConnectorBlueprint $blueprint = null,
        public ?Connection $connection = null,
        public ?array $lastTestResult = null,
        public ?array $lastDashboardSpec = null,
        public ?array $lastDevTasks = null,
        public ?string $currentUserMessage = null,
    ) {}

    public function refreshBlueprint(): void
    {
        $this->blueprint = $this->session->blueprint()
            ->with('streams')
            ->first();
    }
}
