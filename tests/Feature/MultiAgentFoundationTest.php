<?php

use App\Models\Agent;
use App\Models\Workspace;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\SafetyGuardService;
use App\Services\Workspace\TargetCommandRunner;
use App\Services\Workspace\WorkspacePathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function agentTestTempDir(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rexmarket-agent-'.bin2hex(random_bytes(6));

    mkdir($path, 0775, true);

    return realpath($path) ?: $path;
}

function agentTestWorkspace(string $path, array $attributes = []): Workspace
{
    return Workspace::query()->create([
        'name' => $attributes['name'] ?? 'rexmarket-test-'.bin2hex(random_bytes(3)),
        'path' => $path,
        'type' => 'laravel-vilt',
        'base_branch' => 'develop',
        'agent_branch_prefix' => 'agent',
        'protected_paths' => config('agents.protected_paths'),
        'allowed_commands' => config('agents.allowed_commands'),
        'approval_required_commands' => config('agents.approval_required_commands'),
        'blocked_commands' => config('agents.blocked_commands'),
        'status' => 'active',
    ]);
}

function agentTestRun(array $command, string $cwd): void
{
    $process = new Process($command, $cwd);
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput() ?: $process->getOutput());
}

function agentTestGitRepo(string $path, string $branch = 'develop'): void
{
    agentTestRun(['git', 'init'], $path);
    agentTestRun(['git', 'config', 'user.email', 'agent-test@example.com'], $path);
    agentTestRun(['git', 'config', 'user.name', 'Agent Test'], $path);

    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Test\n");

    agentTestRun(['git', 'add', 'README.md'], $path);
    agentTestRun(['git', 'commit', '-m', 'Initial commit'], $path);
    agentTestRun(['git', 'branch', '-M', $branch], $path);
}

test('workspace path service rejects traversal and absolute paths', function () {
    $workspace = agentTestWorkspace(agentTestTempDir());
    $service = app(WorkspacePathService::class);

    expect(fn () => $service->safePath($workspace, '../.env'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->safePath($workspace, agentTestTempDir()))->toThrow(InvalidArgumentException::class);
});

test('safety guard rejects commits on protected branches', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path, 'develop');

    $workspace = agentTestWorkspace($path);
    $guard = app(SafetyGuardService::class);

    expect(fn () => $guard->assertCanCommit($workspace))->toThrow(RuntimeException::class);

    agentTestRun(['git', 'checkout', '-b', 'main'], $path);

    expect(fn () => $guard->assertCanCommit($workspace))->toThrow(RuntimeException::class);
});

test('target command runner rejects blocked commands', function () {
    $workspace = agentTestWorkspace(agentTestTempDir());
    $runner = app(TargetCommandRunner::class);

    expect(fn () => $runner->runAllowed($workspace, 'rm -rf storage'))->toThrow(RuntimeException::class);
    expect(fn () => $runner->runAllowed($workspace, 'php artisan migrate:fresh'))->toThrow(RuntimeException::class);
});

test('agent orchestrator creates a mission and planner task', function () {
    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);

    Agent::query()->create([
        'name' => 'Planner Agent',
        'role' => 'planner',
        'system_prompt' => 'Plan work.',
        'enabled' => true,
        'sort_order' => 10,
    ]);
    Agent::query()->create([
        'name' => 'Implementer Agent',
        'role' => 'implementer',
        'system_prompt' => 'Implement work.',
        'enabled' => true,
        'sort_order' => 20,
    ]);
    Agent::query()->create([
        'name' => 'Reviewer Agent',
        'role' => 'reviewer',
        'system_prompt' => 'Review work.',
        'enabled' => true,
        'sort_order' => 30,
    ]);

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Tambah wishlist', 'Tambah fitur wishlist produk', 'develop', 'agent/wishlist', 20);

    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('running');
    expect($session->messages()->count())->toBe(2);
    expect($session->tasks()->count())->toBe(1);
    expect($session->currentAgent?->role)->toBe('planner');
});

test('workspace init command creates workspace from env', function () {
    $path = agentTestTempDir();

    putenv('TARGET_WORKSPACE_PATH='.$path);
    $_ENV['TARGET_WORKSPACE_PATH'] = $path;
    $_SERVER['TARGET_WORKSPACE_PATH'] = $path;

    $this->artisan('agents:workspace:init')
        ->assertExitCode(0);

    $workspace = Workspace::query()->where('name', 'rexmarket')->first();

    expect($workspace)->toBeInstanceOf(Workspace::class);
    expect($workspace->path)->toBe($path);
});
