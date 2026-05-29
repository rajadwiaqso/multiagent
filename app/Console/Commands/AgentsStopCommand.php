<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Console\Command;

class AgentsStopCommand extends Command
{
    protected $signature = 'agents:stop {session_id : Agent session id}';

    protected $description = 'Stop an agent session.';

    public function handle(AgentOrchestrator $orchestrator): int
    {
        $session = AgentSession::query()->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $orchestrator->stop($session);
        $this->info("Session {$session->id} stopped.");

        return self::SUCCESS;
    }
}
