<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use Illuminate\Console\Command;

class AgentsModeCommand extends Command
{
    protected $signature = 'agents:mode {session_id : Agent session id} {mode : readonly, suggest, sandbox, or auto}';

    protected $description = 'Update the mode for an agent session.';

    public function handle(): int
    {
        $mode = (string) $this->argument('mode');

        if (! in_array($mode, ['readonly', 'suggest', 'sandbox', 'auto'], true)) {
            $this->error('Invalid mode. Use readonly, suggest, sandbox, or auto.');

            return self::FAILURE;
        }

        $session = AgentSession::query()->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $session->forceFill(['mode' => $mode])->save();

        $this->info("Session {$session->id} mode set to {$mode}.");

        return self::SUCCESS;
    }
}
