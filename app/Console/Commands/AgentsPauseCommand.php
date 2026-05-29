<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Services\Agent\AgentOrchestrator;
use Illuminate\Console\Command;

class AgentsPauseCommand extends Command
{
    protected $signature = 'agents:pause {session_id : Agent session id}';

    protected $description = 'Pause an agent session.';

    public function handle(AgentOrchestrator $orchestrator): int
    {
        $session = AgentSession::query()->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $orchestrator->pause($session);
        $this->info("Session {$session->id} paused.");

        return self::SUCCESS;
    }
}
