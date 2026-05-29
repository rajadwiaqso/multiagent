<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use Illuminate\Console\Command;

class AgentsToolsCommand extends Command
{
    protected $signature = 'agents:tools {session_id : Agent session id} {--allow= : Comma-separated allowed tools}';

    protected $description = 'Update the per-session allowed tools list.';

    public function handle(): int
    {
        $session = AgentSession::query()->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $allowed = $this->option('allow');

        if (! is_string($allowed) || trim($allowed) === '') {
            $this->error('Provide --allow=read_file,list_files,git_status,git_diff or another comma-separated list.');

            return self::FAILURE;
        }

        $tools = collect(explode(',', $allowed))
            ->map(fn (string $tool): string => trim($tool))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $session->forceFill(['allowed_tools' => $tools])->save();

        $this->info("Session {$session->id} allowed tools updated.");
        $this->line('Allowed: '.implode(', ', $tools));

        return self::SUCCESS;
    }
}
