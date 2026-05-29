<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgentOrchestrator
{
    public function __construct(
        private readonly AgentContextService $contextService,
        private readonly FakeLlmClient $fakeLlmClient,
        private readonly OpenAiCompatibleLlmClient $openAiCompatibleLlmClient,
        private readonly AgentActionProcessor $actionProcessor,
    ) {}

    /**
     * @param  array<int, string>|null  $allowedTools
     */
    public function createMission(
        Workspace $workspace,
        string $title,
        string $mission,
        string $baseBranch,
        string $agentBranch,
        int $maxSteps = 20,
        string $mode = 'readonly',
        ?array $allowedTools = null,
        int $maxActionsPerStep = 5,
    ): AgentSession {
        return DB::transaction(function () use ($workspace, $title, $mission, $baseBranch, $agentBranch, $maxSteps, $mode, $allowedTools, $maxActionsPerStep): AgentSession {
            $session = AgentSession::query()->create([
                'workspace_id' => $workspace->id,
                'title' => $title,
                'mission' => $mission,
                'status' => 'pending',
                'mode' => $mode,
                'allowed_tools' => $allowedTools,
                'base_branch' => $baseBranch,
                'agent_branch' => $agentBranch,
                'current_step' => 0,
                'max_steps' => $maxSteps,
                'max_actions_per_step' => $maxActionsPerStep,
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

        if (in_array($session->status, ['paused', 'waiting_approval', 'stopped', 'completed', 'failed'], true)) {
            return;
        }

        if ($session->current_step >= $session->max_steps) {
            $this->pauseSession($session, 'max_steps_reached');

            return;
        }

        $agent = $this->nextAgent($session);

        if ($agent === null) {
            throw new RuntimeException('No enabled agents are available.');
        }

        $metadata = $session->metadata ?: [];
        unset($metadata['next_agent_role']);

        $session->forceFill([
            'status' => 'running',
            'current_agent_id' => $agent->id,
            'metadata' => $metadata,
        ])->save();

        $context = $this->contextService->buildContext($session, $agent);

        try {
            $response = $this->llmClient()->send($session, $agent, $context);
        } catch (\Throwable $throwable) {
            $this->failStep($session, 'LLM request failed: '.$throwable->getMessage());

            return;
        }

        $this->contextService->appendMessage($session, $agent, 'assistant', $response['content'], $response['metadata'] ?? []);

        if (! $this->isValidJsonProtocol($response['content'])) {
            $this->failStep($session, 'LLM response was not valid JSON action protocol.');

            return;
        }

        $protocol = $this->actionProcessor->process($session, $agent, $response['content']);

        $session->increment('current_step');
        $session->refresh();

        $metadata = $session->metadata ?: [];
        $metadata['last_protocol_status'] = $protocol['status'];
        $metadata['last_agent_role'] = $agent->role;

        if ($protocol['invalid_next_agent'] !== null) {
            $metadata['invalid_next_agent'] = $protocol['invalid_next_agent'];
        } else {
            unset($metadata['invalid_next_agent']);
        }

        if ($protocol['status'] === 'waiting_approval') {
            unset($metadata['next_agent_role']);

            $session->forceFill([
                'status' => 'paused',
                'metadata' => [
                    ...$metadata,
                    'paused_reason' => 'waiting_approval',
                    'stop_reason' => 'waiting_approval',
                ],
            ])->save();

            $this->contextService->updateSummary($session);

            return;
        }

        if ($protocol['should_stop']) {
            unset($metadata['next_agent_role']);

            $stopStatus = $this->workflowStopStatus($protocol['stop_reason']);
            $reasonKey = $stopStatus === 'failed' ? 'failure_reason' : 'paused_reason';

            $session->forceFill([
                'status' => $stopStatus,
                'metadata' => [
                    ...$metadata,
                    $reasonKey => $protocol['stop_reason'],
                    'stop_reason' => $protocol['stop_reason'],
                ],
            ])->save();

            $this->contextService->updateSummary($session);

            return;
        }

        if ($protocol['has_critical_action_failure'] && $protocol['status'] === 'completed') {
            unset($metadata['next_agent_role']);

            $session->forceFill([
                'status' => 'paused',
                'metadata' => [
                    ...$metadata,
                    'paused_reason' => 'critical_action_blocked',
                    'stop_reason' => 'critical_action_blocked',
                ],
            ])->save();

            $this->contextService->updateSummary($session);

            return;
        }

        $nextAgentDecision = $this->resolveNextAgentDecision($agent, $protocol, $session, $metadata);

        if ($nextAgentDecision['status'] === 'pause') {
            unset($metadata['next_agent_role']);

            $session->forceFill([
                'status' => 'paused',
                'metadata' => [
                    ...$metadata,
                    ...$nextAgentDecision['metadata'],
                    'paused_reason' => $nextAgentDecision['reason'],
                    'stop_reason' => $nextAgentDecision['reason'],
                ],
            ])->save();

            $this->contextService->updateSummary($session);

            return;
        }

        $metadata = [
            ...$metadata,
            ...$nextAgentDecision['metadata'],
        ];

        $effectiveNextAgent = $nextAgentDecision['next_agent'];
        $nextAgentHistory = $this->nextAgentHistory($metadata, $effectiveNextAgent);

        if ($this->hasRepeatedNextAgentLoop($agent, $nextAgentHistory, $session)) {
            unset($metadata['next_agent_role']);

            $session->forceFill([
                'status' => 'paused',
                'metadata' => [
                    ...$metadata,
                    'next_agent_history' => $nextAgentHistory,
                    'paused_reason' => 'agent_loop_detected',
                    'stop_reason' => 'agent_loop_detected',
                ],
            ])->save();

            $this->contextService->updateSummary($session);

            return;
        }

        $metadata['next_agent_history'] = $nextAgentHistory;

        if ($effectiveNextAgent !== null) {
            $metadata['next_agent_role'] = $effectiveNextAgent;
        } else {
            unset($metadata['next_agent_role']);
        }

        if ($session->current_step >= $session->max_steps && $protocol['status'] !== 'completed') {
            unset($metadata['next_agent_role']);

            $session->forceFill([
                'status' => 'paused',
                'metadata' => [
                    ...$metadata,
                    'paused_reason' => 'max_steps_reached',
                    'stop_reason' => 'max_steps_reached',
                ],
            ])->save();

            $this->contextService->updateSummary($session);

            return;
        }

        $status = match ($protocol['status']) {
            'completed' => $protocol['has_critical_action_failure'] ? 'paused' : 'completed',
            'failed' => 'failed',
            default => $session->status,
        };

        $session->forceFill([
            'status' => $status,
            'metadata' => $metadata,
        ])->save();

        $this->contextService->updateSummary($session);
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
        $nextAgentRole = ($session->metadata ?: [])['next_agent_role'] ?? null;

        if (is_string($nextAgentRole) && $nextAgentRole !== '') {
            $agent = Agent::query()
                ->where('enabled', true)
                ->where('role', $nextAgentRole)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($agent !== null) {
                return $agent;
            }
        }

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

    private function llmClient(): LlmClientInterface
    {
        return match (strtolower((string) config('agents.llm.driver', 'fake'))) {
            'openai', 'openai-compatible', 'openai_compatible' => $this->openAiCompatibleLlmClient,
            'fake' => $this->fakeLlmClient,
            default => throw new RuntimeException('Unsupported AGENT_LLM_DRIVER value: '.config('agents.llm.driver')),
        };
    }

    private function isValidJsonProtocol(string $content): bool
    {
        $decoded = json_decode(trim($content), true);

        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE;
    }

    private function failStep(AgentSession $session, string $message): void
    {
        $metadata = $session->metadata ?: [];
        $metadata['last_protocol_status'] = 'failed';
        $metadata['llm_error'] = $message;
        $metadata['failure_reason'] = 'llm_request_failed';
        $metadata['stop_reason'] = 'llm_request_failed';

        $session->forceFill([
            'status' => 'failed',
            'metadata' => $metadata,
        ])->save();
    }

    private function pauseSession(AgentSession $session, string $reason): void
    {
        $metadata = $session->metadata ?: [];
        $metadata['paused_reason'] = $reason;
        $metadata['stop_reason'] = $reason;

        $session->forceFill([
            'status' => 'paused',
            'metadata' => $metadata,
        ])->save();
    }

    private function workflowStopStatus(?string $reason): string
    {
        if ($reason === 'max_actions_per_step_exceeded') {
            $status = config('agents.workflow.action_limit_exceeded_status', 'paused');

            return in_array($status, ['paused', 'failed'], true) ? $status : 'paused';
        }

        return 'paused';
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @param  array<string, mixed>  $metadata
     * @return array{status: 'continue'|'pause', next_agent: string|null, reason: string|null, metadata: array<string, mixed>}
     */
    private function resolveNextAgentDecision(Agent $agent, array $protocol, AgentSession $session, array $metadata): array
    {
        $nextAgent = $protocol['next_agent'];

        if (($session->mode ?: 'readonly') !== 'readonly') {
            return [
                'status' => 'continue',
                'next_agent' => $nextAgent,
                'reason' => null,
                'metadata' => [],
            ];
        }

        $actionsAreReadonlySafe = $this->stepActionsAreReadonlySafe($protocol['action_results']);

        if (($protocol['invalid_next_agent'] ?? null) !== null) {
            if ($actionsAreReadonlySafe) {
                return [
                    'status' => 'continue',
                    'next_agent' => 'reviewer',
                    'reason' => null,
                    'metadata' => [
                        'readonly_next_agent_fallback' => 'reviewer',
                        'readonly_next_agent_fallback_reason' => 'invalid_next_agent',
                    ],
                ];
            }

            return [
                'status' => 'pause',
                'next_agent' => null,
                'reason' => 'invalid_readonly_workflow',
                'metadata' => [],
            ];
        }

        if ($agent->role === 'planner' && $nextAgent === 'planner' && $protocol['status'] === 'continue' && $actionsAreReadonlySafe) {
            $selfLoops = (int) ($metadata['readonly_planner_self_loops'] ?? 0) + 1;
            $limit = (int) config('agents.workflow.readonly_planner_max_self_loops', 3);

            if ($selfLoops > $limit) {
                return [
                    'status' => 'continue',
                    'next_agent' => 'reviewer',
                    'reason' => null,
                    'metadata' => [
                        'readonly_planner_self_loops' => $selfLoops,
                        'readonly_planner_self_loop_limit_reached' => true,
                        'readonly_next_agent_fallback' => 'reviewer',
                        'readonly_next_agent_fallback_reason' => 'readonly_planner_self_loop_limit_reached',
                    ],
                ];
            }

            return [
                'status' => 'continue',
                'next_agent' => 'planner',
                'reason' => null,
                'metadata' => [
                    'readonly_planner_self_loops' => $selfLoops,
                ],
            ];
        }

        if ($agent->role !== 'planner' || $nextAgent !== 'planner') {
            $metadata = [
                'readonly_planner_self_loops' => 0,
            ];
        } else {
            $metadata = [];
        }

        $allowed = match ($agent->role) {
            'planner' => in_array($nextAgent, [null, 'implementer', 'reviewer'], true),
            'implementer' => in_array($nextAgent, [null, 'reviewer'], true),
            'reviewer' => in_array($nextAgent, [null, 'planner'], true),
            default => $nextAgent === null,
        };

        if (! $allowed) {
            return [
                'status' => 'pause',
                'next_agent' => null,
                'reason' => 'invalid_readonly_workflow',
                'metadata' => $metadata,
            ];
        }

        return [
            'status' => 'continue',
            'next_agent' => $nextAgent,
            'reason' => null,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionResults
     */
    private function stepActionsAreReadonlySafe(array $actionResults): bool
    {
        $readonlyTools = ['read_file', 'list_files', 'git_status', 'git_diff'];

        foreach ($actionResults as $result) {
            if (! in_array($result['tool'] ?? null, $readonlyTools, true)) {
                return false;
            }

            if (($result['status'] ?? null) !== 'done') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string|null>
     */
    private function nextAgentHistory(array $metadata, ?string $nextAgent): array
    {
        $history = $metadata['next_agent_history'] ?? [];

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $nextAgent;

        return array_slice($history, -5);
    }

    /**
     * @param  array<int, string|null>  $history
     */
    private function hasRepeatedNextAgentLoop(Agent $agent, array $history, AgentSession $session): bool
    {
        if (($session->mode ?: 'readonly') === 'readonly' && $agent->role === 'planner' && end($history) === 'planner') {
            return false;
        }

        if (($session->mode ?: 'readonly') === 'readonly' && $agent->role === 'reviewer' && end($history) === 'planner') {
            $reviewerToPlannerCount = collect($history)->filter(fn (?string $nextAgent): bool => $nextAgent === 'planner')->count();

            return $reviewerToPlannerCount > 1;
        }

        $lastThree = array_slice($history, -3);

        if (count($lastThree) < 3 || in_array(null, $lastThree, true)) {
            return false;
        }

        return count(array_unique($lastThree)) === 1;
    }
}
