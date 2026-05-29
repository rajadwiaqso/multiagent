<?php

namespace App\Services\Workspace;

use App\Models\Workspace;
use App\Services\Agent\SafetyGuardService;
use RuntimeException;
use Symfony\Component\Process\Process;

class TargetGitService
{
    public function __construct(
        private readonly WorkspacePathService $pathService,
        private readonly SafetyGuardService $safetyGuard,
    ) {}

    public function currentBranch(Workspace $workspace): string
    {
        return trim($this->runGit($workspace, ['branch', '--show-current']));
    }

    public function status(Workspace $workspace): string
    {
        return $this->runGit($workspace, ['status', '--short']);
    }

    public function diff(Workspace $workspace, ?string $base = null): string
    {
        $command = $base === null || $base === ''
            ? ['diff']
            : ['diff', $base];

        return $this->runGit($workspace, $command);
    }

    /**
     * @return array<int, string>
     */
    public function diffNameOnly(Workspace $workspace, ?string $base = null): array
    {
        $command = $base === null || $base === ''
            ? ['diff', '--name-only']
            : ['diff', '--name-only', $base];

        $output = trim($this->runGit($workspace, $command));

        if ($output === '') {
            return [];
        }

        return preg_split('/\R/', $output) ?: [];
    }

    public function checkout(Workspace $workspace, string $branch): string
    {
        $this->assertSafeBranchName($branch);

        return $this->runGit($workspace, ['checkout', $branch]);
    }

    public function createBranchFromBase(Workspace $workspace, string $baseBranch, string $agentBranch): string
    {
        $this->assertSafeBranchName($baseBranch);
        $this->ensureAgentBranch($workspace, $agentBranch);
        $this->safetyGuard->assertWorkspaceCleanOrAllowed($workspace);

        if ($this->branchExists($workspace, $agentBranch)) {
            return $this->checkout($workspace, $agentBranch);
        }

        $output = $this->checkout($workspace, $baseBranch);
        $output .= $this->runGit($workspace, ['checkout', '-b', $agentBranch]);

        return $output;
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function add(Workspace $workspace, array $paths = []): string
    {
        $this->safetyGuard->assertCanCommit($workspace);

        foreach ($paths as $path) {
            $this->safetyGuard->assertSafeRelativePath($workspace, $path);
        }

        $command = ['add'];

        if ($paths === []) {
            $command[] = '-A';
        } else {
            array_push($command, '--', ...$paths);
        }

        return $this->runGit($workspace, $command);
    }

    /**
     * @return array{hash: string|null, output: string, changed_files: array<int, string>}
     */
    public function commit(Workspace $workspace, string $message): array
    {
        $this->safetyGuard->assertCanCommit($workspace);

        $changedFiles = $this->cachedDiffNameOnly($workspace);
        $output = $this->runGit($workspace, ['commit', '-m', $message]);

        return [
            'hash' => $this->latestCommitHash($workspace),
            'output' => $output,
            'changed_files' => $changedFiles,
        ];
    }

    public function latestCommitHash(Workspace $workspace): ?string
    {
        try {
            return trim($this->runGit($workspace, ['rev-parse', 'HEAD']));
        } catch (RuntimeException) {
            return null;
        }
    }

    public function ensureAgentBranch(Workspace $workspace, string $branch): void
    {
        $this->assertSafeBranchName($branch);

        $prefix = trim($workspace->agent_branch_prefix ?: config('agents.workspace.agent_branch_prefix', 'agent'), '/');

        if (! str_starts_with($branch, $prefix.'/')) {
            throw new RuntimeException("Branch must use the {$prefix}/ prefix.");
        }

        if (in_array($branch, config('agents.dangerous_branches', []), true)) {
            throw new RuntimeException("Branch {$branch} is protected.");
        }
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function runGit(Workspace $workspace, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->pathService->resolve($workspace));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Git command failed.');
        }

        return $process->getOutput().$process->getErrorOutput();
    }

    private function branchExists(Workspace $workspace, string $branch): bool
    {
        $process = new Process(['git', 'show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], $this->pathService->resolve($workspace));
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array<int, string>
     */
    private function cachedDiffNameOnly(Workspace $workspace): array
    {
        $output = trim($this->runGit($workspace, ['diff', '--cached', '--name-only']));

        if ($output === '') {
            return [];
        }

        return preg_split('/\R/', $output) ?: [];
    }

    private function assertSafeBranchName(string $branch): void
    {
        if (trim($branch) === '' || preg_match('/\s/', $branch) === 1 || str_contains($branch, '..')) {
            throw new RuntimeException('Branch name is invalid.');
        }
    }
}
