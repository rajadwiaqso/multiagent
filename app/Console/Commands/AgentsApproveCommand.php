<?php

namespace App\Console\Commands;

use App\Models\AgentAction;
use Illuminate\Console\Command;

class AgentsApproveCommand extends Command
{
    protected $signature = 'agents:approve {action_id : Agent action id}';

    protected $description = 'Approve a pending agent action.';

    public function handle(): int
    {
        $action = AgentAction::query()->find($this->argument('action_id'));

        if ($action === null) {
            $this->error('Action not found.');

            return self::FAILURE;
        }

        $action->forceFill([
            'status' => 'approved',
            'error' => null,
        ])->save();

        $this->info("Action {$action->id} approved.");

        return self::SUCCESS;
    }
}
