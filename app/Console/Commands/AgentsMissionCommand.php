<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Workspace\TargetGitService;
use Illuminate\Console\Command;
use Throwable;

class AgentsMissionCommand extends Command
{
    protected $signature = 'agents:mission
        {title : Mission title}
        {--workspace=rexmarket : Workspace name}
        {--base=develop : Base branch}
        {--branch=agent/example : Agent branch}
        {--max-steps=20 : Maximum orchestration steps}
        {--mission= : Optional long mission text}';

    protected $description = 'Create a mission session and prepare its target agent branch.';

    public function handle(AgentOrchestrator $orchestrator, TargetGitService $gitService): int
    {
        $workspace = Workspace::query()->where('name', (string) $this->option('workspace'))->first();

        if ($workspace === null) {
            $this->error('Workspace not found. Run php artisan agents:workspace:init first.');

            return self::FAILURE;
        }

        $title = (string) $this->argument('title');
        $mission = (string) ($this->option('mission') ?: $title);
        $baseBranch = (string) ($this->option('base') ?: $workspace->base_branch);
        $agentBranch = (string) $this->option('branch');
        $maxSteps = max(1, (int) $this->option('max-steps'));

        $session = $orchestrator->createMission($workspace, $title, $mission, $baseBranch, $agentBranch, $maxSteps);

        try {
            $gitService->createBranchFromBase($workspace, $baseBranch, $agentBranch);
            $orchestrator->startSession($session);
        } catch (Throwable $throwable) {
            $session->forceFill([
                'status' => 'failed',
                'metadata' => [
                    ...($session->metadata ?: []),
                    'failure' => $throwable->getMessage(),
                ],
            ])->save();

            $this->error($throwable->getMessage());
            $this->line("Session {$session->id} was created with status failed.");

            return self::FAILURE;
        }

        $this->info("Mission created. Session id: {$session->id}");
        $this->line("Branch: {$agentBranch}");

        return self::SUCCESS;
    }
}
