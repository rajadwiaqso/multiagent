<?php

namespace App\Console\Commands;

use App\Models\AgentAction;
use Illuminate\Console\Command;

class AgentsRejectCommand extends Command
{
    protected $signature = 'agents:reject {action_id : Agent action id}';

    protected $description = 'Reject a pending agent action.';

    public function handle(): int
    {
        $action = AgentAction::query()->find($this->argument('action_id'));

        if ($action === null) {
            $this->error('Action not found.');

            return self::FAILURE;
        }

        $action->forceFill([
            'status' => 'rejected',
            'error' => 'Rejected from Artisan command.',
        ])->save();

        $this->info("Action {$action->id} rejected.");

        return self::SUCCESS;
    }
}
