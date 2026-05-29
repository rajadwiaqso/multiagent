<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Console\Command;

class AgentsRunCommand extends Command
{
    protected $signature = 'agents:run
        {session_id : Agent session id}
        {--until-approval : Stop when an approval action is pending}
        {--max-steps= : Override maximum steps for this run}';

    protected $description = 'Run an agent session until it stops, completes, fails, or reaches the step limit.';

    public function handle(AgentOrchestrator $orchestrator): int
    {
        $session = AgentSession::query()->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $maxSteps = $this->option('max-steps') !== null
            ? max(1, (int) $this->option('max-steps'))
            : $session->max_steps;
        $stepsRun = 0;

        while ($stepsRun < $maxSteps) {
            $session->refresh();

            if (in_array($session->status, ['paused', 'waiting_approval', 'stopped', 'completed', 'failed'], true)) {
                break;
            }

            if ($this->option('until-approval') && $this->shouldStopUntilApproval($session)) {
                $this->warn('Pending approval found. Stopping run.');
                break;
            }

            $orchestrator->runStep($session);
            $session->refresh()->load('currentAgent');
            $stepsRun++;

            $this->line("Ran step {$session->current_step}; status {$session->status}; agent ".($session->currentAgent?->name ?: '-'));

            if ($this->option('until-approval') && $this->shouldStopUntilApproval($session)) {
                $this->warn('Pending approval found. Stopping run.');
                break;
            }
        }

        $this->info("Run finished after {$stepsRun} step(s). Final status: {$session->refresh()->status}");

        return self::SUCCESS;
    }

    private function hasPendingApproval(AgentSession $session): bool
    {
        return $session->actions()
            ->where('status', 'pending')
            ->where('requires_approval', true)
            ->exists();
    }

    private function shouldStopUntilApproval(AgentSession $session): bool
    {
        $session->refresh();

        return $this->hasPendingApproval($session)
            || ($session->status === 'paused' && (($session->metadata ?: [])['paused_reason'] ?? null) === 'waiting_approval')
            || $session->status === 'waiting_approval';
    }
}
