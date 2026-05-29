<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Console\Command;

class AgentsStepCommand extends Command
{
    protected $signature = 'agents:step {session_id : Agent session id}';

    protected $description = 'Run one orchestration step for an agent session.';

    public function handle(AgentOrchestrator $orchestrator): int
    {
        $session = AgentSession::query()->with('currentAgent')->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $orchestrator->runStep($session);
        $session->refresh()->load('currentAgent');

        $message = $session->messages()->latest()->first();

        $this->info("Step: {$session->current_step}/{$session->max_steps}");
        $this->line('Status: '.$session->status);
        $this->line('Current agent: '.($session->currentAgent?->name ?: '-'));

        if ($message !== null) {
            $this->line('Last message: '.$message->content);
        }

        return self::SUCCESS;
    }
}
