<?php

namespace App\Console\Commands;

use App\Models\ClientDashboard;
use App\Models\DashboardAgentMemory;
use Illuminate\Console\Command;

class AgentMemoriesCommand extends Command
{
    protected $signature = 'titan:agent-memories
                            {dashboard : Client dashboard ID}
                            {--delete= : Delete a memory by key}';

    protected $description = 'List or delete dashboard agent memory entries';

    public function handle(): int
    {
        $dashboard = ClientDashboard::query()->findOrFail((int) $this->argument('dashboard'));

        if ($deleteKey = $this->option('delete')) {
            $deleted = DashboardAgentMemory::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->where('memory_key', $deleteKey)
                ->delete();

            if ($deleted === 0) {
                $this->warn("No memory found for key [{$deleteKey}].");
            } else {
                $this->info("Deleted memory [{$deleteKey}].");
            }

            return self::SUCCESS;
        }

        $memories = DashboardAgentMemory::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->orderByDesc('updated_at')
            ->get();

        if ($memories->isEmpty()) {
            $this->warn("No agent memories for dashboard {$dashboard->id} ({$dashboard->name}).");

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Category', 'Flow', 'Title', 'Updated'],
            $memories->map(fn (DashboardAgentMemory $memory) => [
                $memory->memory_key,
                $memory->category,
                $memory->agent_flow,
                $memory->title,
                $memory->updated_at?->toDateTimeString(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
