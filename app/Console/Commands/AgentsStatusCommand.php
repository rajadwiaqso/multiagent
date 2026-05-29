<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use Illuminate\Console\Command;

class AgentsStatusCommand extends Command
{
    protected $signature = 'agents:status {session_id? : Agent session id}';

    protected $description = 'Show status for a session, or the latest session if no id is provided.';

    public function handle(): int
    {
        $session = $this->argument('session_id')
            ? AgentSession::query()->with(['workspace', 'currentAgent'])->find($this->argument('session_id'))
            : AgentSession::query()->with(['workspace', 'currentAgent'])->latest()->first();

        if ($session === null) {
            $this->error('No session found.');

            return self::FAILURE;
        }

        $this->info("Session {$session->id}: {$session->title}");
        $this->line('Workspace: '.$session->workspace->name);
        $this->line('Status: '.$session->status);
        $this->line("Step: {$session->current_step}/{$session->max_steps}");
        $this->line('Base branch: '.$session->base_branch);
        $this->line('Agent branch: '.$session->agent_branch);
        $this->line('Current agent: '.($session->currentAgent?->name ?: '-'));

        return self::SUCCESS;
    }
}
