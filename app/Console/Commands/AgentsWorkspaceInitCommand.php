<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;

class AgentsWorkspaceInitCommand extends Command
{
    protected $signature = 'agents:workspace:init {--name=rexmarket}';

    protected $description = 'Create or update the default target workspace from TARGET_WORKSPACE_PATH.';

    public function handle(): int
    {
        $targetEnvKey = config('agents.target_env_key', 'TARGET_WORKSPACE_PATH');
        $path = env($targetEnvKey);

        if (! is_string($path) || trim($path) === '') {
            $this->error("{$targetEnvKey} is not configured.");

            return self::FAILURE;
        }

        if (! is_dir($path)) {
            $this->error("Target workspace path does not exist: {$path}");

            return self::FAILURE;
        }

        $workspace = Workspace::query()->updateOrCreate(
            ['name' => (string) $this->option('name')],
            [
                'path' => $path,
                'type' => config('agents.workspace.type', 'laravel-vilt'),
                'base_branch' => config('agents.workspace.base_branch', 'develop'),
                'agent_branch_prefix' => config('agents.workspace.agent_branch_prefix', 'agent'),
                'protected_paths' => config('agents.protected_paths', []),
                'allowed_commands' => config('agents.allowed_commands', []),
                'approval_required_commands' => config('agents.approval_required_commands', []),
                'blocked_commands' => config('agents.blocked_commands', []),
                'status' => 'active',
            ],
        );

        $this->info("Workspace [{$workspace->name}] ready with id {$workspace->id}.");
        $this->line("Path: {$workspace->path}");

        return self::SUCCESS;
    }
}
