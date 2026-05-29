<?php

namespace App\Services\Agent;

use App\Models\AgentAction;
use App\Models\AgentSession;
use App\Models\Workspace;
use App\Services\Workspace\WorkspacePathService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class SafetyGuardService
{
    public function __construct(
        private readonly WorkspacePathService $pathService,
    ) {}

    public function assertWorkspaceCleanOrAllowed(Workspace $workspace): void
    {
        $process = new Process(['git', 'status', '--porcelain'], $this->pathService->resolve($workspace));
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Unable to inspect workspace status.');
        }

        if (trim($process->getOutput()) !== '') {
            throw new RuntimeException('Target workspace must be clean before creating an agent branch.');
        }
    }

    public function assertSafeRelativePath(Workspace $workspace, string $relativePath): void
    {
        $relativePath = $this->pathService->normalizeRelativePath($relativePath);

        foreach ($this->protectedPaths($workspace) as $protectedPath) {
            if ($this->pathMatches($relativePath, $protectedPath)) {
                throw new InvalidArgumentException("Protected path is blocked: {$relativePath}");
            }
        }
    }

    /**
     * @param  array<int, string>  $changedFiles
     */
    public function assertSafeChangedFiles(Workspace $workspace, array $changedFiles, AgentSession $session): void
    {
        $violations = [];

        foreach ($changedFiles as $changedFile) {
            try {
                $this->assertSafeRelativePath($workspace, $changedFile);
            } catch (InvalidArgumentException) {
                $violations[] = $changedFile;
            }
        }

        if ($violations === []) {
            return;
        }

        if ($session->exists) {
            AgentAction::query()->create([
                'session_id' => $session->id,
                'agent_id' => $session->current_agent_id,
                'type' => 'safety.protected_paths',
                'payload' => ['changed_files' => array_values(array_unique($violations))],
                'status' => 'blocked',
                'requires_approval' => false,
                'error' => 'Protected path change blocked.',
            ]);
        }

        throw new RuntimeException('Changed files include protected paths: '.implode(', ', array_unique($violations)));
    }

    public function assertCommandAllowed(Workspace $workspace, string $commandLine): string
    {
        $commandLine = $this->normalizeCommandLine($commandLine);
        $commandComparable = strtolower($commandLine);

        foreach ($this->blockedCommands($workspace) as $blockedCommand) {
            if ($blockedCommand !== '' && str_contains($commandComparable, strtolower($this->normalizeCommandLine($blockedCommand)))) {
                throw new RuntimeException("Command is blocked: {$blockedCommand}");
            }
        }

        foreach ($this->approvalRequiredCommands($workspace) as $approvalCommand) {
            $approvalCommand = $this->normalizeCommandLine($approvalCommand);

            if ($commandLine === $approvalCommand || str_starts_with($commandLine, $approvalCommand.' ')) {
                return 'approval_required';
            }
        }

        foreach ($this->allowedCommands($workspace) as $allowedCommand) {
            if ($commandLine === $this->normalizeCommandLine($allowedCommand)) {
                return 'allowed';
            }
        }

        throw new RuntimeException("Command is not in the allowed command list: {$commandLine}");
    }

    public function assertCanCommit(Workspace $workspace): void
    {
        $branch = $this->currentBranch($workspace);

        if ($branch === '') {
            throw new RuntimeException('Unable to determine current branch.');
        }

        if (in_array($branch, config('agents.dangerous_branches', []), true)) {
            throw new RuntimeException("Branch {$branch} is protected.");
        }

        $prefix = trim($workspace->agent_branch_prefix ?: config('agents.workspace.agent_branch_prefix', 'agent'), '/');

        if (! str_starts_with($branch, $prefix.'/')) {
            throw new RuntimeException("Current branch must use the {$prefix}/ prefix before agent writes or commits.");
        }
    }

    /**
     * @return array<int, string>
     */
    private function protectedPaths(Workspace $workspace): array
    {
        return $workspace->protected_paths ?: config('agents.protected_paths', []);
    }

    /**
     * @return array<int, string>
     */
    private function allowedCommands(Workspace $workspace): array
    {
        return $workspace->allowed_commands ?: config('agents.allowed_commands', []);
    }

    /**
     * @return array<int, string>
     */
    private function approvalRequiredCommands(Workspace $workspace): array
    {
        return $workspace->approval_required_commands ?: config('agents.approval_required_commands', []);
    }

    /**
     * @return array<int, string>
     */
    private function blockedCommands(Workspace $workspace): array
    {
        return $workspace->blocked_commands ?: config('agents.blocked_commands', []);
    }

    private function pathMatches(string $relativePath, string $protectedPath): bool
    {
        $protectedPath = str_replace('\\', '/', trim($protectedPath));

        if ($protectedPath === '') {
            return false;
        }

        if (str_ends_with($protectedPath, '/')) {
            $directory = rtrim($protectedPath, '/');

            return $relativePath === $directory || str_starts_with($relativePath, $directory.'/');
        }

        if (str_contains($protectedPath, '*')) {
            return fnmatch($protectedPath, $relativePath, FNM_PATHNAME);
        }

        return $relativePath === $protectedPath;
    }

    private function currentBranch(Workspace $workspace): string
    {
        $process = new Process(['git', 'branch', '--show-current'], $this->pathService->resolve($workspace));
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Unable to inspect current branch.');
        }

        return trim($process->getOutput());
    }

    private function normalizeCommandLine(string $commandLine): string
    {
        return preg_replace('/\s+/', ' ', trim($commandLine)) ?? trim($commandLine);
    }
}
