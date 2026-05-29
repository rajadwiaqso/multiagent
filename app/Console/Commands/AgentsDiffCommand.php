<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Services\Workspace\TargetGitService;
use Illuminate\Console\Command;

class AgentsDiffCommand extends Command
{
    protected $signature = 'agents:diff {session_id : Agent session id}';

    protected $description = 'Show git diff for the target workspace of a session.';

    public function handle(TargetGitService $gitService): int
    {
        $session = AgentSession::query()->with('workspace')->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $diff = $gitService->diff($session->workspace, $session->base_branch);
        $this->line($diff === '' ? 'No diff.' : $diff);

        return self::SUCCESS;
    }
}
