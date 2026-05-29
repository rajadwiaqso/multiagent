<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentSession;
use App\Models\AgentTask;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgentOrchestrator
{
    public function __construct(
        private readonly AgentContextService $contextService,
        private readonly LlmClient $llmClient,
    ) {}

    public function createMission(Workspace $workspace, string $title, string $mission, string $baseBranch, string $agentBranch, int $maxSteps = 20): AgentSession
    {
        return DB::transaction(function () use ($workspace, $title, $mission, $baseBranch, $agentBranch, $maxSteps): AgentSession {
            $session = AgentSession::query()->create([
                'workspace_id' => $workspace->id,
                'title' => $title,
                'mission' => $mission,
                'status' => 'pending',
                'base_branch' => $baseBranch,
                'agent_branch' => $agentBranch,
                'current_step' => 0,
                'max_steps' => $maxSteps,
                'metadata' => ['created_by' => 'agents:mission'],
            ]);

            $this->contextService->appendMessage($session, null, 'user', $mission, [
                'title' => $title,
                'base_branch' => $baseBranch,
                'agent_branch' => $agentBranch,
            ]);

            return $session;
        });
    }

    public function startSession(AgentSession $session): void
    {
        $session->forceFill(['status' => 'running'])->save();
    }

    public function runStep(AgentSession $session): void
    {
        $session->refresh();

        if (in_array($session->status, ['paused', 'stopped', 'completed', 'failed'], true)) {
            return;
        }

        if ($session->current_step >= $session->max_steps) {
            $session->forceFill(['status' => 'completed'])->save();

            return;
        }

        $agent = $this->nextAgent($session);

        if ($agent === null) {
            throw new RuntimeException('No enabled agents are available.');
        }

        DB::transaction(function () use ($session, $agent): void {
            $session->forceFill([
                'status' => 'running',
                'current_agent_id' => $agent->id,
            ])->save();

            $context = $this->contextService->buildContext($session, $agent);
            $response = $this->llmClient->send($session, $agent, $context);

            $this->contextService->appendMessage($session, $agent, 'assistant', $response['content'], $response['metadata'] ?? []);

            match ($agent->role) {
                'planner' => $this->runPlannerStub($session, $agent),
                'implementer' => $this->runImplementerStub($session, $agent),
                'reviewer' => $this->runReviewerStub($session, $agent),
                default => null,
            };

            $session->increment('current_step');
            $session->refresh();

            if ($session->current_step >= $session->max_steps) {
                $session->forceFill(['status' => 'completed'])->save();
            }

            $this->contextService->updateSummary($session);
        });
    }

    public function pause(AgentSession $session): void
    {
        $session->forceFill(['status' => 'paused'])->save();
    }

    public function stop(AgentSession $session): void
    {
        $session->forceFill(['status' => 'stopped'])->save();
    }

    public function nextAgent(AgentSession $session): ?Agent
    {
        $agents = Agent::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($agents->isEmpty()) {
            return null;
        }

        return $agents[$session->current_step % $agents->count()];
    }

    private function runPlannerStub(AgentSession $session, Agent $agent): void
    {
        if ($session->tasks()->exists()) {
            return;
        }

        $implementer = Agent::query()
            ->where('enabled', true)
            ->where('role', 'implementer')
            ->orderBy('sort_order')
            ->first();

        AgentTask::query()->create([
            'session_id' => $session->id,
            'created_by_agent_id' => $agent->id,
            'assigned_to_agent_id' => $implementer?->id,
            'title' => 'Rencanakan implementasi: '.$session->title,
            'description' => $session->mission,
            'status' => 'pending',
            'metadata' => ['source' => 'planner_stub'],
        ]);
    }

    private function runImplementerStub(AgentSession $session, Agent $agent): void
    {
        $task = $session->tasks()
            ->whereIn('status', ['pending', 'in_progress'])
            ->oldest()
            ->first();

        if ($task !== null) {
            $task->forceFill([
                'status' => 'done',
                'result' => 'Implementer stub selesai tanpa menulis kode target.',
            ])->save();
        }

        AgentAction::query()->create([
            'session_id' => $session->id,
            'agent_id' => $agent->id,
            'type' => 'implementation.stub',
            'payload' => [
                'task_id' => $task?->id,
                'writes_target_files' => false,
            ],
            'status' => 'done',
            'requires_approval' => false,
            'result' => 'No-op implementer stub completed.',
        ]);
    }

    private function runReviewerStub(AgentSession $session, Agent $agent): void
    {
        AgentAction::query()->create([
            'session_id' => $session->id,
            'agent_id' => $agent->id,
            'type' => 'review.stub',
            'payload' => [
                'checks' => ['scope', 'safety', 'diff'],
            ],
            'status' => 'done',
            'requires_approval' => false,
            'result' => 'Reviewer stub completed.',
        ]);

        if (! $session->tasks()->whereNotIn('status', ['done', 'cancelled'])->exists()) {
            $session->forceFill(['status' => 'completed'])->save();
        }
    }
}
