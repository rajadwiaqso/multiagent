<?php

namespace App\Services\Workspace;

use App\Models\AgentSession;
use App\Models\Workspace;
use App\Services\Agent\SafetyGuardService;
use RuntimeException;
use Symfony\Component\Process\Process;

class TargetFileService
{
    public function __construct(
        private readonly WorkspacePathService $pathService,
        private readonly SafetyGuardService $safetyGuard,
        private readonly TargetGitService $gitService,
    ) {}

    /**
     * @return array<int, string>
     */
    public function listFiles(Workspace $workspace, string $relativePath = '', int $depth = 2): array
    {
        $root = $this->pathService->safePath($workspace, $relativePath);

        if (! is_dir($root)) {
            throw new RuntimeException("Path is not a directory: {$relativePath}");
        }

        $workspaceRoot = $this->pathService->resolve($workspace);
        $directoryIterator = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filteredIterator = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            fn (\SplFileInfo $fileInfo): bool => ! in_array($fileInfo->getFilename(), ['.git', 'node_modules', 'vendor'], true)
        );
        $iterator = new \RecursiveIteratorIterator(
            $filteredIterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth(max(0, $depth));

        $files = [];

        foreach ($iterator as $fileInfo) {
            if (in_array($fileInfo->getFilename(), ['.git', 'node_modules', 'vendor'], true)) {
                continue;
            }

            $path = str_replace('\\', '/', $fileInfo->getPathname());
            $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $workspaceRoot))), '/');
            $files[] = $fileInfo->isDir() ? $relative.'/' : $relative;
        }

        sort($files);

        return $files;
    }

    public function readFile(Workspace $workspace, string $relativePath): string
    {
        $this->safetyGuard->assertSafeRelativePath($workspace, $relativePath);

        $path = $this->pathService->safePath($workspace, $relativePath);

        if (! is_file($path)) {
            throw new RuntimeException("File does not exist: {$relativePath}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read file: {$relativePath}");
        }

        return $contents;
    }

    public function writeFile(Workspace $workspace, string $relativePath, string $content): void
    {
        $this->safetyGuard->assertCanCommit($workspace);
        $this->safetyGuard->assertSafeRelativePath($workspace, $relativePath);

        $path = $this->pathService->safePath($workspace, $relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Unable to write file: {$relativePath}");
        }
    }

    public function applyPatch(Workspace $workspace, string $patch): string
    {
        $this->safetyGuard->assertCanCommit($workspace);

        $session = $this->currentSession($workspace);
        $patchFiles = $this->changedFilesFromPatch($patch);

        if ($session !== null) {
            $this->safetyGuard->assertSafeChangedFiles($workspace, $patchFiles, $session);
        } else {
            foreach ($patchFiles as $patchFile) {
                $this->safetyGuard->assertSafeRelativePath($workspace, $patchFile);
            }
        }

        $patchDirectory = storage_path('app/agent-patches');

        if (! is_dir($patchDirectory) && ! mkdir($patchDirectory, 0775, true) && ! is_dir($patchDirectory)) {
            throw new RuntimeException("Unable to create patch directory: {$patchDirectory}");
        }

        $patchPath = $patchDirectory.DIRECTORY_SEPARATOR.'patch-'.bin2hex(random_bytes(8)).'.diff';

        if (file_put_contents($patchPath, $patch) === false) {
            throw new RuntimeException('Unable to write temporary patch file.');
        }

        try {
            $checkOutput = $this->runGitApply($workspace, ['apply', '--check', $patchPath]);
            $applyOutput = $this->runGitApply($workspace, ['apply', $patchPath]);
            $changedFiles = $this->gitService->diffNameOnly($workspace);

            if ($changedFiles !== []) {
                $session = $session ?: $this->currentSession($workspace);

                if ($session !== null) {
                    $this->safetyGuard->assertSafeChangedFiles($workspace, $changedFiles, $session);
                } else {
                    foreach ($changedFiles as $changedFile) {
                        $this->safetyGuard->assertSafeRelativePath($workspace, $changedFile);
                    }
                }
            }

            return trim($checkOutput.$applyOutput) ?: 'Patch applied.';
        } finally {
            @unlink($patchPath);
        }
    }

    public function fileExists(Workspace $workspace, string $relativePath): bool
    {
        return file_exists($this->pathService->safePath($workspace, $relativePath));
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

    private function currentSession(Workspace $workspace): ?AgentSession
    {
        return AgentSession::query()
            ->where('workspace_id', $workspace->id)
            ->where('agent_branch', $this->gitService->currentBranch($workspace))
            ->latest()
            ->first();
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function runGitApply(Workspace $workspace, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->pathService->resolve($workspace));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Patch command failed.');
        }

        return $process->getOutput().$process->getErrorOutput();
    }
}
