<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentAction;
use App\Models\AgentCommit;
use App\Models\AgentSession;
use App\Models\Workspace;
use App\Services\Workspace\TargetCommandRunner;
use App\Services\Workspace\TargetFileService;
use App\Services\Workspace\TargetGitService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AgentActionProcessor
{
    private const ALLOWED_TOOLS = [
        'read_file',
        'list_files',
        'apply_patch',
        'run_command',
        'git_status',
        'git_diff',
        'commit',
    ];

    private const SESSION_MODES = [
        'readonly',
        'suggest',
        'sandbox',
        'auto',
    ];

    private const READONLY_TOOLS = [
        'read_file',
        'list_files',
        'git_status',
        'git_diff',
    ];

    private const ALLOWED_STATUSES = [
        'continue',
        'waiting_approval',
        'completed',
        'failed',
    ];

    private const ALLOWED_NEXT_AGENTS = [
        'planner',
        'implementer',
        'reviewer',
    ];

    public function __construct(
        private readonly TargetFileService $fileService,
        private readonly TargetGitService $gitService,
        private readonly TargetCommandRunner $commandRunner,
        private readonly SafetyGuardService $safetyGuard,
        private readonly AgentContextService $contextService,
    ) {}

    /**
     * @return array{
     *     message: string,
     *     actions: array<int, array<string, mixed>>,
     *     next_agent: string|null,
     *     status: string,
     *     action_results: array<int, array<string, mixed>>,
     *     should_stop: bool,
     *     stop_reason: string|null,
     *     has_critical_action_failure: bool,
     *     invalid_next_agent: string|null
     * }
     */
    public function process(AgentSession $session, Agent $agent, array|string $agentOutput): array
    {
        $protocol = $this->normalizeProtocol($agentOutput);
        $actionResults = [];
        $waitingForApproval = false;
        $maxActions = max(0, $session->max_actions_per_step ?: 5);
        $shouldStop = false;
        $stopReason = null;

        foreach ($protocol['actions'] as $index => $actionPayload) {
            $action = $this->recordAction($session, $agent, $actionPayload);

            if ($index >= $maxActions) {
                $this->blockAction($session, $action, "Action limit exceeded: max_actions_per_step is {$maxActions}");
                $this->markSessionActionLimitExceeded($session, count($protocol['actions']), $maxActions);
                $actionResults[] = $this->actionResult($action);
                $shouldStop = true;
                $stopReason = 'max_actions_per_step_exceeded';

                continue;
            }

            if (! $this->hasTool($actionPayload)) {
                $this->blockAction($session, $action, 'Invalid action: missing tool');
                $actionResults[] = $this->actionResult($action);

                continue;
            }

            if ($waitingForApproval) {
                $action->forceFill([
                    'status' => 'pending',
                    'result' => 'Waiting for an earlier approval-required action.',
                ])->save();

                $actionResults[] = $this->actionResult($action);

                continue;
            }

            $result = $this->processAction($session, $action);
            $actionResults[] = $result;

            if (($result['status'] ?? null) === 'pending' && ($result['requires_approval'] ?? false) === true) {
                $waitingForApproval = true;
            }
        }

        return [
            ...$protocol,
            'action_results' => $actionResults,
            'should_stop' => $shouldStop,
            'stop_reason' => $stopReason,
            'has_critical_action_failure' => $this->hasCriticalActionFailure($actionResults),
        ];
    }

    /**
     * @param  array<string, mixed>|string  $agentOutput
     * @return array{message: string, actions: array<int, array<string, mixed>>, next_agent: string|null, status: string, invalid_next_agent: string|null}
     */
    private function normalizeProtocol(array|string $agentOutput): array
    {
        $protocol = is_array($agentOutput)
            ? $agentOutput
            : $this->decodeProtocolString($agentOutput);

        $actions = $protocol['actions'] ?? [];

        if (! is_array($actions)) {
            $actions = [];
        }

        $nextAgent = $protocol['next_agent'] ?? null;
        $invalidNextAgent = null;

        if ($nextAgent === 'null' || $nextAgent === '') {
            $nextAgent = null;
        }

        if (! in_array($nextAgent, [null, ...self::ALLOWED_NEXT_AGENTS], true)) {
            $invalidNextAgent = is_scalar($nextAgent) ? (string) $nextAgent : json_encode($nextAgent);
            $nextAgent = null;
        }

        $status = $protocol['status'] ?? 'continue';

        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'continue';
        }

        return [
            'message' => is_string($protocol['message'] ?? null) ? $protocol['message'] : '',
            'actions' => array_values(array_filter($actions, fn (mixed $action): bool => is_array($action))),
            'next_agent' => $nextAgent,
            'status' => $status,
            'invalid_next_agent' => $invalidNextAgent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProtocolString(string $agentOutput): array
    {
        $json = trim($agentOutput);

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json) ?? $json;
            $json = preg_replace('/\s*```$/', '', $json) ?? $json;
        }

        $decoded = json_decode($json, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'message' => $agentOutput,
            'actions' => [],
            'next_agent' => null,
            'status' => 'continue',
        ];
    }

    /**
     * @param  array<string, mixed>  $actionPayload
     */
    private function recordAction(AgentSession $session, Agent $agent, array $actionPayload): AgentAction
    {
        $tool = $this->toolName($actionPayload);

        return AgentAction::query()->create([
            'session_id' => $session->id,
            'agent_id' => $agent->id,
            'type' => $tool !== '' ? $tool : 'invalid',
            'payload' => $actionPayload,
            'status' => 'pending',
            'requires_approval' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function processAction(AgentSession $session, AgentAction $action): array
    {
        try {
            $authorization = $this->authorizeAction($session, $action);

            if ($authorization['status'] === 'pending') {
                $action->forceFill([
                    'requires_approval' => true,
                    'status' => 'pending',
                    'result' => $authorization['reason'],
                ])->save();
            } else {
                $action->forceFill(['status' => 'running'])->save();
                $result = $this->dispatch($session, $action);

                $action->forceFill([
                    'status' => $result['status'],
                    'result' => $result['result'],
                    'error' => $result['error'] ?? null,
                ])->save();
            }
        } catch (Throwable $throwable) {
            $action->forceFill([
                'status' => $this->isBlocked($throwable) ? 'blocked' : 'failed',
                'error' => $throwable->getMessage(),
            ])->save();
        }

        $this->appendToolMessage($session, $action);

        return $this->actionResult($action);
    }

    /**
     * @return array{status: 'allowed'|'pending', reason?: string}
     */
    private function authorizeAction(AgentSession $session, AgentAction $action): array
    {
        $tool = $this->toolName($action->payload ?: []);

        if (! in_array($tool, self::ALLOWED_TOOLS, true)) {
            throw new InvalidArgumentException("Unknown tool: {$tool}");
        }

        $this->assertAllowedBySessionTools($session, $tool);

        $mode = $this->sessionMode($session);

        return match ($tool) {
            'read_file', 'list_files', 'git_status', 'git_diff' => $this->authorizeReadonlyTool($mode, $tool),
            'apply_patch' => $this->authorizeApplyPatch($session, $action, $mode),
            'commit' => $this->authorizeCommit($session, $mode),
            'run_command' => $this->authorizeRunCommand($session, $action, $mode),
            default => throw new InvalidArgumentException("Unknown tool: {$tool}"),
        };
    }

    /**
     * @return array{status: 'allowed'}
     */
    private function authorizeReadonlyTool(string $mode, string $tool): array
    {
        if (! in_array($tool, self::READONLY_TOOLS, true)) {
            throw new InvalidArgumentException("Tool {$tool} is not allowed in {$mode} mode.");
        }

        return ['status' => 'allowed'];
    }

    /**
     * @return array{status: 'allowed'|'pending', reason?: string}
     */
    private function authorizeApplyPatch(AgentSession $session, AgentAction $action, string $mode): array
    {
        if ($mode === 'readonly') {
            throw new InvalidArgumentException('Tool apply_patch is blocked in readonly mode.');
        }

        $this->assertPatchDoesNotTouchProtectedPaths($session->workspace, $action);

        if ($mode === 'suggest') {
            return [
                'status' => 'pending',
                'reason' => 'apply_patch requires approval in suggest mode.',
            ];
        }

        $this->assertCurrentBranchMatchesSession($session);

        return ['status' => 'allowed'];
    }

    /**
     * @return array{status: 'allowed'|'pending', reason?: string}
     */
    private function authorizeCommit(AgentSession $session, string $mode): array
    {
        if ($mode === 'readonly') {
            throw new InvalidArgumentException('Tool commit is blocked in readonly mode.');
        }

        if ($mode === 'suggest') {
            return [
                'status' => 'pending',
                'reason' => 'commit requires approval in suggest mode.',
            ];
        }

        if ($mode === 'sandbox') {
            return [
                'status' => 'pending',
                'reason' => 'commit requires approval in sandbox mode.',
            ];
        }

        $this->assertCurrentBranchMatchesSession($session);

        $approvalReasons = $this->commitApprovalReasons($session);

        if ($approvalReasons !== []) {
            return [
                'status' => 'pending',
                'reason' => 'commit requires approval: '.implode('; ', $approvalReasons),
            ];
        }

        return ['status' => 'allowed'];
    }

    /**
     * @return array{status: 'allowed'|'pending', reason?: string}
     */
    private function authorizeRunCommand(AgentSession $session, AgentAction $action, string $mode): array
    {
        $command = $this->requiredString($action, 'command');

        if ($mode === 'readonly') {
            throw new InvalidArgumentException('Tool run_command is blocked in readonly mode.');
        }

        $this->assertCommandIsNotModeBlocked($command);
        $classification = $this->safetyGuard->assertCommandAllowed($session->workspace, $command);

        if ($mode === 'suggest') {
            return [
                'status' => 'pending',
                'reason' => 'run_command requires approval in suggest mode.',
            ];
        }

        if ($classification === 'approval_required') {
            return [
                'status' => 'pending',
                'reason' => 'Command requires approval before execution.',
            ];
        }

        return ['status' => 'allowed'];
    }

    /**
     * @return array{status: string, result: string, error?: string|null}
     */
    private function dispatch(AgentSession $session, AgentAction $action): array
    {
        $workspace = $session->workspace;
        $payload = $action->payload ?: [];

        return match ($action->type) {
            'read_file' => $this->readFile($workspace, $action),
            'list_files' => $this->listFiles($workspace, $action),
            'apply_patch' => [
                'status' => 'done',
                'result' => $this->fileService->applyPatch($workspace, $this->requiredString($action, 'patch')),
            ],
            'run_command' => $this->runCommand($workspace, $action),
            'git_status' => [
                'status' => 'done',
                'result' => $this->gitService->status($workspace),
            ],
            'git_diff' => [
                'status' => 'done',
                'result' => $this->gitService->diff($workspace, is_string($payload['base'] ?? null) ? $payload['base'] : null),
            ],
            'commit' => $this->commit($session, $action),
            default => throw new InvalidArgumentException("Unknown tool: {$action->type}"),
        };
    }

    /**
     * @return array{status: string, result: string}
     */
    private function readFile(Workspace $workspace, AgentAction $action): array
    {
        $path = $this->requiredString($action, 'path');
        $this->safetyGuard->assertSafeRelativePath($workspace, $path);

        return [
            'status' => 'done',
            'result' => $this->fileService->readFile($workspace, $path),
        ];
    }

    /**
     * @return array{status: string, result: string}
     */
    private function listFiles(Workspace $workspace, AgentAction $action): array
    {
        $payload = $action->payload ?: [];
        $path = is_string($payload['path'] ?? null) ? $payload['path'] : '';
        $depth = is_int($payload['depth'] ?? null) ? $payload['depth'] : 2;

        if ($path !== '') {
            $this->safetyGuard->assertSafeRelativePath($workspace, $path);
        }

        return [
            'status' => 'done',
            'result' => json_encode($this->fileService->listFiles($workspace, $path, $depth), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }

    /**
     * @return array{status: string, result: string, error?: string|null}
     */
    private function runCommand(Workspace $workspace, AgentAction $action): array
    {
        $result = $this->commandRunner->runAllowed($workspace, $this->requiredString($action, 'command'));

        return [
            'status' => $result['status'],
            'result' => trim($result['output'].$result['error']),
            'error' => $result['successful'] ? null : ($result['error'] ?: null),
        ];
    }

    /**
     * @return array{status: string, result: string}
     */
    private function commit(AgentSession $session, AgentAction $action): array
    {
        $workspace = $session->workspace;
        $message = $this->requiredString($action, 'message');
        $changedFiles = $this->changedFiles($workspace);

        $this->assertCurrentBranchMatchesSession($session);

        if ($changedFiles === []) {
            throw new RuntimeException('No changed files to commit.');
        }

        $this->safetyGuard->assertCanCommit($workspace);
        $this->safetyGuard->assertSafeChangedFiles($workspace, $changedFiles, $session);

        $this->gitService->add($workspace, $changedFiles);
        $commit = $this->gitService->commit($workspace, $message);
        $branch = $this->gitService->currentBranch($workspace);

        AgentCommit::query()->create([
            'session_id' => $session->id,
            'agent_id' => $action->agent_id,
            'workspace_id' => $workspace->id,
            'branch' => $branch,
            'commit_hash' => $commit['hash'],
            'commit_message' => $message,
            'changed_files' => $changedFiles,
        ]);

        return [
            'status' => 'done',
            'result' => trim($commit['output']) ?: 'Committed '.$commit['hash'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function changedFiles(Workspace $workspace): array
    {
        return collect($this->changedFileEntries($workspace))
            ->pluck('path')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{status: string, path: string}>
     */
    private function changedFileEntries(Workspace $workspace): array
    {
        $status = $this->gitService->status($workspace);

        if (trim($status) === '') {
            return [];
        }

        return collect(preg_split('/\R/', rtrim($status)) ?: [])
            ->map(function (string $line): array {
                $status = strlen($line) >= 2 ? substr($line, 0, 2) : '';
                $path = strlen($line) >= 3 ? trim(substr($line, 3)) : trim($line);
                $path = str_contains($path, ' -> ') ? Str::afterLast($path, ' -> ') : $path;

                return [
                    'status' => $status,
                    'path' => $path,
                ];
            })
            ->filter(fn (array $entry): bool => $entry['path'] !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function requiredString(AgentAction $action, string $key): string
    {
        $value = ($action->payload ?: [])[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Action {$action->type} requires a non-empty {$key} value.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toolName(array $payload): string
    {
        $tool = $payload['tool'] ?? '';

        return is_string($tool) ? trim($tool) : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasTool(array $payload): bool
    {
        return $this->toolName($payload) !== '';
    }

    private function blockAction(AgentSession $session, AgentAction $action, string $error): void
    {
        $action->forceFill([
            'status' => 'blocked',
            'error' => $error,
        ])->save();

        $this->appendToolMessage($session, $action);
    }

    private function appendToolMessage(AgentSession $session, AgentAction $action): void
    {
        $content = json_encode([
            'tool' => $action->type,
            'status' => $action->status,
            'requires_approval' => $action->requires_approval,
            'result' => $action->result ? Str::limit($action->result, 1200) : null,
            'error' => $action->error,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->contextService->appendMessage($session, $action->agent, 'tool', $content ?: '{}', [
            'action_id' => $action->id,
            'tool' => $action->type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function actionResult(AgentAction $action): array
    {
        return [
            'id' => $action->id,
            'tool' => $action->type,
            'status' => $action->status,
            'requires_approval' => $action->requires_approval,
            'result' => $action->result,
            'error' => $action->error,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $actionResults
     */
    private function hasCriticalActionFailure(array $actionResults): bool
    {
        foreach ($actionResults as $result) {
            if (in_array($result['status'] ?? null, ['blocked', 'failed'], true)) {
                return true;
            }
        }

        return false;
    }

    private function markSessionActionLimitExceeded(AgentSession $session, int $receivedActionsCount, int $maxActions): void
    {
        $metadata = $session->metadata ?: [];
        $metadata['blocked_reason'] = 'max_actions_per_step_exceeded';
        $metadata['max_actions_per_step'] = $maxActions;
        $metadata['received_actions_count'] = $receivedActionsCount;

        $session->forceFill(['metadata' => $metadata])->save();
    }

    private function isBlocked(Throwable $throwable): bool
    {
        if ($throwable instanceof InvalidArgumentException) {
            return true;
        }

        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'blocked')
            || str_contains($message, 'protected')
            || str_contains($message, 'not in the allowed command list')
            || str_contains($message, 'must use the')
            || str_contains($message, 'must match session.agent_branch')
            || str_contains($message, 'path traversal')
            || str_contains($message, 'escapes the configured workspace');
    }

    private function sessionMode(AgentSession $session): string
    {
        $mode = $session->mode ?: 'readonly';

        if (! in_array($mode, self::SESSION_MODES, true)) {
            throw new InvalidArgumentException("Unsupported session mode: {$mode}");
        }

        return $mode;
    }

    private function assertAllowedBySessionTools(AgentSession $session, string $tool): void
    {
        if ($session->allowed_tools === null) {
            return;
        }

        if (! in_array($tool, $session->allowed_tools, true)) {
            throw new InvalidArgumentException("Tool {$tool} is not allowed by session allowed_tools.");
        }
    }

    private function assertCurrentBranchMatchesSession(AgentSession $session): void
    {
        $currentBranch = $this->gitService->currentBranch($session->workspace);

        if ($currentBranch !== $session->agent_branch) {
            throw new RuntimeException("Current branch must match session.agent_branch ({$session->agent_branch}); current branch is {$currentBranch}.");
        }
    }

    private function assertCommandIsNotModeBlocked(string $command): void
    {
        $command = strtolower(preg_replace('/\s+/', ' ', trim($command)) ?? trim($command));

        if (
            preg_match('/(^|\s)git\s+push(\s|$)/', $command) === 1
            || preg_match('/(^|\s)git\s+merge(\s|$)/', $command) === 1
            || str_contains($command, 'deploy')
        ) {
            throw new InvalidArgumentException('Command is blocked by session mode.');
        }
    }

    private function assertPatchDoesNotTouchProtectedPaths(Workspace $workspace, AgentAction $action): void
    {
        foreach ($this->changedFilesFromPatch($this->requiredString($action, 'patch')) as $changedFile) {
            $this->safetyGuard->assertSafeRelativePath($workspace, $changedFile);
        }
    }

    /**
     * @return array<int, string>
     */
    private function changedFilesFromPatch(string $patch): array
    {
        preg_match_all('/^diff --git a\/(.+?) b\/(.+)$/m', $patch, $diffMatches, PREG_SET_ORDER);
        preg_match_all('/^(?:---|\+\+\+) (?!\/dev\/null)(?:a|b)\/(.+)$/m', $patch, $fileMatches, PREG_SET_ORDER);

        $files = [];

        foreach ($diffMatches as $match) {
            $files[] = $match[1];
            $files[] = $match[2];
        }

        foreach ($fileMatches as $match) {
            $files[] = $match[1];
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<int, string>
     */
    private function commitApprovalReasons(AgentSession $session): array
    {
        $entries = $this->changedFileEntries($session->workspace);
        $paths = collect($entries)->pluck('path')->unique()->values()->all();
        $reasons = [];

        if (count($paths) > 10) {
            $reasons[] = 'changes more than 10 files';
        }

        foreach ($entries as $entry) {
            $path = str_replace('\\', '/', $entry['path']);

            if (str_contains($entry['status'], 'D')) {
                $reasons[] = 'contains deletion';
            }

            if (str_starts_with($path, 'database/migrations/') || str_contains($path, 'migration')) {
                $reasons[] = 'contains migration';
            }

            if (str_starts_with($path, 'config/')) {
                $reasons[] = 'contains config';
            }

            try {
                $this->safetyGuard->assertSafeRelativePath($session->workspace, $path);
            } catch (InvalidArgumentException) {
                $reasons[] = 'contains protected path';
            }
        }

        return array_values(array_unique($reasons));
    }
}
