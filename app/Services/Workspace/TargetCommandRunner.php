<?php

namespace App\Services\Workspace;

use App\Models\Workspace;
use App\Services\Agent\SafetyGuardService;
use RuntimeException;
use Symfony\Component\Process\Process;

class TargetCommandRunner
{
    public function __construct(
        private readonly WorkspacePathService $pathService,
        private readonly SafetyGuardService $safetyGuard,
    ) {}

    /**
     * @param  array<int, string>  $command
     * @return array{command: string, exit_code: int|null, successful: bool, output: string, error: string}
     */
    public function run(Workspace $workspace, array $command, int $timeout = 120): array
    {
        if ($command === []) {
            throw new RuntimeException('Command cannot be empty.');
        }

        $process = new Process($command, $this->pathService->resolve($workspace));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'command' => implode(' ', $command),
            'exit_code' => $process->getExitCode(),
            'successful' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return array{command: string, status: string, exit_code: int|null, successful: bool, output: string, error: string}
     */
    public function runAllowed(Workspace $workspace, string $commandLine): array
    {
        $classification = $this->safetyGuard->assertCommandAllowed($workspace, $commandLine);
        $commandLine = $this->normalizeCommandLine($commandLine);

        if ($classification === 'approval_required') {
            return [
                'command' => $commandLine,
                'status' => 'approval_required',
                'exit_code' => null,
                'successful' => false,
                'output' => '',
                'error' => 'Command requires approval before it can be executed.',
            ];
        }

        $result = $this->run($workspace, $this->parseCommandLine($commandLine));

        return [
            ...$result,
            'status' => $result['successful'] ? 'done' : 'failed',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseCommandLine(string $commandLine): array
    {
        $tokens = str_getcsv($commandLine, ' ', '"', '\\');
        $tokens = array_values(array_filter($tokens, fn (?string $token): bool => $token !== null && $token !== ''));

        if ($tokens === []) {
            throw new RuntimeException('Command cannot be empty.');
        }

        return $tokens;
    }

    private function normalizeCommandLine(string $commandLine): string
    {
        return preg_replace('/\s+/', ' ', trim($commandLine)) ?? trim($commandLine);
    }
}
