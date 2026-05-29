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
        {--mode=readonly : Session mode: readonly, suggest, sandbox, auto}
        {--allow-tools= : Comma-separated tool allow-list for this session}
        {--max-actions-per-step=5 : Maximum actions processed per LLM step}
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
        $mode = (string) $this->option('mode');
        $allowedTools = $this->parseAllowedTools($this->option('allow-tools'));
        $maxActionsPerStep = max(0, (int) $this->option('max-actions-per-step'));

        if (! in_array($mode, ['readonly', 'suggest', 'sandbox', 'auto'], true)) {
            $this->error('Invalid mode. Use readonly, suggest, sandbox, or auto.');

            return self::FAILURE;
        }

        $session = $orchestrator->createMission(
            $workspace,
            $title,
            $mission,
            $baseBranch,
            $agentBranch,
            $maxSteps,
            $mode,
            $allowedTools,
            $maxActionsPerStep,
        );

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
        $this->line("Mode: {$mode}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function parseAllowedTools(mixed $allowedTools): ?array
    {
        if (! is_string($allowedTools) || trim($allowedTools) === '') {
            return null;
        }

        return collect(explode(',', $allowedTools))
            ->map(fn (string $tool): string => trim($tool))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
