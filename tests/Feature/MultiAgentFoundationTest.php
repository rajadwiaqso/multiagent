<?php

use App\Models\Agent;
use App\Models\AgentCommit;
use App\Models\AgentSession;
use App\Models\Workspace;
use App\Services\Agent\AgentActionProcessor;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\OpenAiCompatibleLlmClient;
use App\Services\Agent\SafetyGuardService;
use App\Services\Workspace\TargetCommandRunner;
use App\Services\Workspace\TargetFileService;
use App\Services\Workspace\WorkspacePathService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

function agentTestOutput(array $command, string $cwd): string
{
    $process = new Process($command, $cwd);
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput() ?: $process->getOutput());

    return $process->getOutput();
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

function agentTestReadmePatch(string $path, string $line = 'Protocol action applied.'): string
{
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Test\n{$line}\n");
    $patch = agentTestOutput(['git', 'diff'], $path);
    agentTestRun(['git', 'checkout', '--', 'README.md'], $path);

    return $patch;
}

function agentTestAgent(string $role = 'planner'): Agent
{
    return Agent::query()->create([
        'name' => ucfirst($role).' Agent',
        'role' => $role,
        'system_prompt' => 'Test '.$role,
        'enabled' => true,
        'sort_order' => match ($role) {
            'planner' => 10,
            'implementer' => 20,
            'reviewer' => 30,
            default => 100,
        },
    ]);
}

function agentTestSession(Workspace $workspace, Agent $agent, string $branch = 'agent/test', array $attributes = []): AgentSession
{
    return AgentSession::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Test mission',
        'mission' => 'Run processor tests',
        'status' => 'running',
        'mode' => $attributes['mode'] ?? 'readonly',
        'allowed_tools' => $attributes['allowed_tools'] ?? null,
        'base_branch' => 'develop',
        'agent_branch' => $branch,
        'current_agent_id' => $agent->id,
        'current_step' => 0,
        'max_steps' => 20,
        'max_actions_per_step' => $attributes['max_actions_per_step'] ?? 5,
        'metadata' => [],
    ]);
}

function agentTestOpenAiConfig(): void
{
    config([
        'agents.llm.driver' => 'openai-compatible',
        'agents.llm.base_url' => 'https://llm.example.test/v1',
        'agents.llm.api_key' => 'secret-test-key',
        'agents.llm.model' => 'agent-model',
    ]);
}

function agentTestOpenAiResponse(array $protocol): array
{
    return [
        'choices' => [
            [
                'message' => [
                    'content' => json_encode($protocol),
                ],
            ],
        ],
    ];
}

test('agent llm config exposes timeout and retry defaults', function () {
    expect((int) config('agents.llm.timeout'))->toBe(120);
    expect((int) config('agents.llm.retry.times'))->toBe(1);
    expect((int) config('agents.llm.retry.sleep_ms'))->toBe(500);
});

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

test('agent orchestrator processes fake planner JSON actions', function () {
    config(['agents.llm.driver' => 'fake']);

    $path = agentTestTempDir();
    mkdir($path.DIRECTORY_SEPARATOR.'routes', 0775, true);
    file_put_contents($path.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php', "<?php\n");

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);

    agentTestAgent('planner');
    agentTestAgent('implementer');
    agentTestAgent('reviewer');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Tambah wishlist', 'Tambah fitur wishlist produk', 'develop', 'agent/wishlist', 20);

    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('running');
    expect($session->actions()->count())->toBe(2);
    expect($session->actions()->where('status', 'done')->count())->toBe(2);
    expect(($session->metadata ?: [])['next_agent_role'])->toBe('implementer');
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

test('agent action processor dispatches read_file list_files and git_status', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    mkdir($path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models', 0775, true);
    file_put_contents($path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Product.php', "<?php\n");
    agentTestRun(['git', 'add', '.'], $path);
    agentTestRun(['git', 'commit', '-m', 'Add model'], $path);
    agentTestRun(['git', 'checkout', '-b', 'agent/protocol'], $path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('planner');
    $session = agentTestSession($workspace, $agent, 'agent/protocol');

    $result = app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Inspect workspace',
        'actions' => [
            ['tool' => 'read_file', 'path' => 'README.md'],
            ['tool' => 'list_files', 'path' => 'app/Models'],
            ['tool' => 'git_status'],
        ],
        'next_agent' => 'implementer',
        'status' => 'continue',
    ]);

    expect($result['next_agent'])->toBe('implementer');
    expect($session->actions()->count())->toBe(3);
    expect($session->actions()->where('status', 'done')->count())->toBe(3);
    expect($session->messages()->where('role', 'tool')->count())->toBe(3);
});

test('agent action processor keeps approval commands pending and does not run later actions', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/test', ['mode' => 'suggest']);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Need install',
        'actions' => [
            ['tool' => 'run_command', 'command' => 'composer install'],
            ['tool' => 'git_status'],
        ],
        'next_agent' => null,
        'status' => 'waiting_approval',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions)->toHaveCount(2);
    expect($actions[0]->status)->toBe('pending');
    expect($actions[0]->requires_approval)->toBeTrue();
    expect($actions[1]->status)->toBe('pending');
    expect($actions[1]->result)->toBe('Waiting for an earlier approval-required action.');
});

test('agent action processor records blocked actions and continues', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Try unsafe then safe',
        'actions' => [
            ['tool' => 'read_file', 'path' => '.env'],
            ['tool' => 'run_command', 'command' => 'rm -rf storage'],
            ['tool' => 'git_status'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions)->toHaveCount(3);
    expect($actions[0]->status)->toBe('blocked');
    expect($actions[1]->status)->toBe('blocked');
    expect($actions[2]->status)->toBe('done');
});

test('agent action processor blocks empty actions without dispatching them as tools', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Contains empty action',
        'actions' => [
            [],
            ['path' => 'README.md'],
            ['tool' => 'git_status'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions)->toHaveCount(3);
    expect($actions[0]->type)->toBe('invalid');
    expect($actions[0]->status)->toBe('blocked');
    expect($actions[0]->error)->toBe('Invalid action: missing tool');
    expect($actions[1]->type)->toBe('invalid');
    expect($actions[1]->status)->toBe('blocked');
    expect($actions[1]->error)->toBe('Invalid action: missing tool');
    expect($actions[2]->type)->toBe('git_status');
    expect($actions[2]->status)->toBe('done');
    expect($session->messages()->where('role', 'tool')->count())->toBe(3);
});

test('readonly mode blocks apply_patch and commit', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/readonly'], $path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/readonly');
    $patch = agentTestReadmePatch($path, 'Readonly should not apply.');

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Readonly mutation attempt',
        'actions' => [
            ['tool' => 'apply_patch', 'patch' => $patch],
            ['tool' => 'commit', 'message' => 'test: readonly commit'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions[0]->status)->toBe('blocked');
    expect($actions[0]->error)->toContain('apply_patch is blocked in readonly mode');
    expect($actions[1]->status)->toBe('blocked');
    expect($actions[1]->error)->toContain('commit is blocked in readonly mode');
    expect(file_get_contents($path.DIRECTORY_SEPARATOR.'README.md'))->not->toContain('Readonly should not apply.');
});

test('suggest mode keeps apply_patch pending approval', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/suggest'], $path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/suggest', ['mode' => 'suggest']);
    $patch = agentTestReadmePatch($path, 'Suggest waits.');

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Suggest patch',
        'actions' => [
            ['tool' => 'apply_patch', 'patch' => $patch],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $action = $session->actions()->first();

    expect($action->status)->toBe('pending');
    expect($action->requires_approval)->toBeTrue();
    expect($action->result)->toContain('apply_patch requires approval in suggest mode');
    expect(file_get_contents($path.DIRECTORY_SEPARATOR.'README.md'))->not->toContain('Suggest waits.');
});

test('sandbox mode allows apply_patch on the exact session branch and keeps commit pending', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/sandbox'], $path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/sandbox', ['mode' => 'sandbox']);
    $patch = agentTestReadmePatch($path, 'Sandbox applies.');

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Sandbox patch then commit',
        'actions' => [
            ['tool' => 'apply_patch', 'patch' => $patch],
            ['tool' => 'commit', 'message' => 'test: sandbox commit'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions[0]->status)->toBe('done');
    expect(file_get_contents($path.DIRECTORY_SEPARATOR.'README.md'))->toContain('Sandbox applies.');
    expect($actions[1]->status)->toBe('pending');
    expect($actions[1]->requires_approval)->toBeTrue();
    expect($actions[1]->result)->toContain('commit requires approval in sandbox mode');
});

test('auto mode blocks commit when current branch differs from the session branch', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/actual'], $path);
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Test\nBranch mismatch.\n");

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/expected', ['mode' => 'auto']);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Commit on wrong branch',
        'actions' => [
            ['tool' => 'commit', 'message' => 'test: wrong branch'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $action = $session->actions()->first();

    expect($action->status)->toBe('blocked');
    expect($action->error)->toContain('Current branch must match session.agent_branch');
    expect(AgentCommit::query()->count())->toBe(0);
});

test('auto mode keeps risky commits pending approval', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/risky-commit'], $path);

    mkdir($path.DIRECTORY_SEPARATOR.'config', 0775, true);
    mkdir($path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0775, true);

    for ($i = 1; $i <= 11; $i++) {
        file_put_contents($path.DIRECTORY_SEPARATOR."file-{$i}.txt", "changed {$i}\n");
    }

    file_put_contents($path.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'feature.php', "<?php return [];\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'2026_05_30_000000_create_demo_table.php', "<?php\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'.env', "APP_KEY=secret\n");
    unlink($path.DIRECTORY_SEPARATOR.'README.md');

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/risky-commit', ['mode' => 'auto']);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Risky commit',
        'actions' => [
            ['tool' => 'commit', 'message' => 'test: risky commit'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $action = $session->actions()->first();

    expect($action->status)->toBe('pending');
    expect($action->requires_approval)->toBeTrue();
    expect($action->result)->toContain('changes more than 10 files');
    expect($action->result)->toContain('contains migration');
    expect($action->result)->toContain('contains config');
    expect($action->result)->toContain('contains deletion');
    expect($action->result)->toContain('contains protected path');
    expect(AgentCommit::query()->count())->toBe(0);
});

test('allowed tools blocks tools outside the session allow list', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('planner');
    $session = agentTestSession($workspace, $agent, 'agent/tools', [
        'allowed_tools' => ['read_file'],
    ]);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Tool allow list',
        'actions' => [
            ['tool' => 'git_status'],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $action = $session->actions()->first();

    expect($action->status)->toBe('blocked');
    expect($action->error)->toContain('not allowed by session allowed_tools');
});

test('max actions per step blocks actions beyond the session limit', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('planner');
    $session = agentTestSession($workspace, $agent, 'agent/limit', [
        'max_actions_per_step' => 2,
    ]);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Too many actions',
        'actions' => [
            ['tool' => 'git_status'],
            ['tool' => 'git_diff'],
            ['tool' => 'list_files', 'path' => ''],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions[0]->status)->toBe('done');
    expect($actions[1]->status)->toBe('done');
    expect($actions[2]->status)->toBe('blocked');
    expect($actions[2]->error)->toContain('Action limit exceeded');
});

test('run command follows readonly suggest and sandbox session modes', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');

    $readonly = agentTestSession($workspace, $agent, 'agent/readonly-command');
    $suggest = agentTestSession($workspace, $agent, 'agent/suggest-command', ['mode' => 'suggest']);
    $sandbox = agentTestSession($workspace, $agent, 'agent/sandbox-command', ['mode' => 'sandbox']);

    foreach ([$readonly, $suggest, $sandbox] as $session) {
        app(AgentActionProcessor::class)->process($session, $agent, [
            'message' => 'Run safe command',
            'actions' => [
                ['tool' => 'run_command', 'command' => 'git status --short'],
            ],
            'next_agent' => null,
            'status' => 'continue',
        ]);
    }

    expect($readonly->actions()->first()->status)->toBe('blocked');
    expect($readonly->actions()->first()->error)->toContain('run_command is blocked in readonly mode');
    expect($suggest->actions()->first()->status)->toBe('pending');
    expect($suggest->actions()->first()->requires_approval)->toBeTrue();
    expect($sandbox->actions()->first()->status)->toBe('done');
});

test('target file service blocks protected reads but still lists file names', function () {
    $path = agentTestTempDir();
    mkdir($path.DIRECTORY_SEPARATOR.'config', 0775, true);
    mkdir($path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment', 0775, true);

    file_put_contents($path.DIRECTORY_SEPARATOR.'.env', "APP_KEY=secret-env\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'.env.example', "APP_KEY=example-secret\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database.php', "<?php return ['password' => 'secret-db'];\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment'.DIRECTORY_SEPARATOR.'Gateway.php', "<?php\n");

    $workspace = agentTestWorkspace($path);
    $service = app(TargetFileService::class);

    expect(fn () => $service->readFile($workspace, '.env'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->readFile($workspace, '.env.example'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->readFile($workspace, 'config/database.php'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->readFile($workspace, 'app/Services/Payment/Gateway.php'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->readFile($workspace, '../.env'))->toThrow(InvalidArgumentException::class);

    $files = $service->listFiles($workspace, '', 4);

    expect($files)->toContain('.env');
    expect($files)->toContain('.env.example');
    expect($files)->toContain('config/database.php');
    expect($files)->toContain('app/Services/Payment/Gateway.php');
});

test('read_file actions for protected paths are blocked while list_files can show names only', function () {
    $path = agentTestTempDir();
    mkdir($path.DIRECTORY_SEPARATOR.'config', 0775, true);

    file_put_contents($path.DIRECTORY_SEPARATOR.'.env', "APP_KEY=secret-env\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'.env.example', "APP_KEY=example-secret\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database.php', "<?php return ['password' => 'secret-db'];\n");
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Public\n");

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('planner');
    $session = agentTestSession($workspace, $agent);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Probe protected reads',
        'actions' => [
            ['tool' => 'read_file', 'path' => '.env'],
            ['tool' => 'read_file', 'path' => '../.env'],
            ['tool' => 'read_file', 'path' => '.env.example'],
            ['tool' => 'read_file', 'path' => 'config/database.php'],
            ['tool' => 'list_files', 'path' => '', 'depth' => 3],
        ],
        'next_agent' => null,
        'status' => 'continue',
    ]);

    $actions = $session->actions()->orderBy('id')->get();

    expect($actions)->toHaveCount(5);
    expect($actions[0]->status)->toBe('blocked');
    expect($actions[0]->error)->toContain('Protected path is blocked: .env');
    expect($actions[1]->status)->toBe('blocked');
    expect($actions[1]->error)->toContain('Path traversal is not allowed.');
    expect($actions[2]->status)->toBe('blocked');
    expect($actions[2]->error)->toContain('Protected path is blocked: .env.example');
    expect($actions[3]->status)->toBe('blocked');
    expect($actions[3]->error)->toContain('Protected path is blocked: config/database.php');
    expect($actions[4]->status)->toBe('done');
    expect($actions[4]->result)->toContain('.env');
    expect($actions[4]->result)->toContain('.env.example');
    expect($actions[4]->result)->toContain('config/database.php');
    expect($actions[4]->result)->not->toContain('secret-env');
    expect($actions[4]->result)->not->toContain('example-secret');
    expect($actions[4]->result)->not->toContain('secret-db');
});

test('agent action processor applies patch and commits only on agent branch', function () {
    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/protocol'], $path);

    $workspace = agentTestWorkspace($path);
    $agent = agentTestAgent('implementer');
    $session = agentTestSession($workspace, $agent, 'agent/protocol', ['mode' => 'auto']);

    $patch = agentTestReadmePatch($path);

    app(AgentActionProcessor::class)->process($session, $agent, [
        'message' => 'Patch and commit',
        'actions' => [
            ['tool' => 'apply_patch', 'patch' => $patch],
            ['tool' => 'commit', 'message' => 'test: apply protocol action'],
        ],
        'next_agent' => 'reviewer',
        'status' => 'continue',
    ]);

    expect($session->actions()->where('status', 'done')->count())->toBe(2);
    expect(AgentCommit::query()->count())->toBe(1);
    expect(file_get_contents($path.DIRECTORY_SEPARATOR.'README.md'))->toContain('Protocol action applied.');
});

test('openai compatible llm client sends chat completions request', function () {
    config([
        'agents.llm.base_url' => 'https://llm.example.test/v1',
        'agents.llm.api_key' => 'secret-test-key',
        'agents.llm.model' => 'agent-model',
        'agents.llm.timeout' => 5,
        'agents.llm.retry.times' => 1,
        'agents.llm.retry.sleep_ms' => 10,
    ]);

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'message' => 'ok',
                            'actions' => [],
                            'next_agent' => null,
                            'status' => 'completed',
                        ]),
                    ],
                ],
            ],
            'usage' => ['total_tokens' => 12],
        ]),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir());
    $agent = agentTestAgent('planner');
    $session = agentTestSession($workspace, $agent);

    $response = app(OpenAiCompatibleLlmClient::class)->send($session, $agent, 'Context body');

    expect(json_decode($response['content'], true))->toBeArray();
    expect($response['metadata']['driver'])->toBe('openai-compatible');
    expect(json_encode($response['metadata']))->not->toContain('secret-test-key');

    Http::assertSent(function ($request): bool {
        $attributes = $request->attributes();

        return $request->url() === 'https://llm.example.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer secret-test-key')
            && $request['model'] === 'agent-model'
            && data_get($request, 'messages.0.role') === 'system'
            && data_get($request, 'messages.1.role') === 'user'
            && $attributes['agent_llm_timeout'] === 5
            && $attributes['agent_llm_retry_times'] === 1
            && $attributes['agent_llm_retry_sleep_ms'] === 10;
    });
});

test('orchestrator records llm timeout without creating actions or touching workspace', function () {
    agentTestOpenAiConfig();
    config([
        'agents.llm.timeout' => 120,
        'agents.llm.retry.times' => 1,
        'agents.llm.retry.sleep_ms' => 500,
    ]);

    $path = agentTestTempDir();
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Public\n");
    $before = file_get_contents($path.DIRECTORY_SEPARATOR.'README.md');

    Http::fake(function () {
        throw new ConnectionException('cURL error 28 Operation timed out after 120000 milliseconds.');
    });

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Timeout', 'Provider timeout should fail cleanly', 'develop', 'agent/timeout', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    $metadata = $session->refresh()->metadata ?: [];

    expect($session->status)->toBe('failed');
    expect($metadata['failure_reason'])->toBe('llm_request_failed');
    expect($metadata['stop_reason'])->toBe('llm_request_failed');
    expect($metadata['llm_error'])->toContain('cURL error 28');
    expect($session->actions()->count())->toBe(0);
    expect($session->messages()->where('role', 'assistant')->count())->toBe(0);
    expect(file_get_contents($path.DIRECTORY_SEPARATOR.'README.md'))->toBe($before);
});

test('agents llm test command uses timeout option without creating a mission', function () {
    agentTestOpenAiConfig();

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'ok',
            'actions' => [],
            'next_agent' => null,
            'status' => 'completed',
        ])),
    ]);

    $this->artisan('agents:llm:test', ['--timeout' => 120])
        ->assertExitCode(0);

    expect(AgentSession::query()->count())->toBe(0);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://llm.example.test/v1/chat/completions'
            && ($request->attributes()['agent_llm_timeout'] ?? null) === 120;
    });
});

test('orchestrator fails the step when llm output is not valid json', function () {
    config([
        'agents.llm.driver' => 'openai-compatible',
        'agents.llm.base_url' => 'https://llm.example.test/v1',
        'agents.llm.api_key' => 'secret-test-key',
        'agents.llm.model' => 'agent-model',
    ]);

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'this is not json',
                    ],
                ],
            ],
        ]),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    $agent = agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Bad JSON', 'Return invalid response', 'develop', 'agent/bad-json', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('failed');
    expect(($session->metadata ?: [])['llm_error'])->toBe('LLM response was not valid JSON action protocol.');
    expect($session->messages()->where('role', 'assistant')->latest()->first()->content)->toBe('this is not json');
    expect($session->actions()->count())->toBe(0);
});

test('llm waiting approval status pauses the session and run stops', function () {
    agentTestOpenAiConfig();

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Need approval',
            'actions' => [],
            'next_agent' => 'reviewer',
            'status' => 'waiting_approval',
        ])),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Approval', 'Pause for approval', 'develop', 'agent/approval', 20);
    $orchestrator->startSession($session);

    $this->artisan('agents:run', ['session_id' => $session->id])
        ->assertExitCode(0);

    expect($session->refresh()->status)->toBe('paused');
    expect(($session->metadata ?: [])['paused_reason'])->toBe('waiting_approval');
    expect(($session->metadata ?: [])['next_agent_role'] ?? null)->toBeNull();
    expect($session->current_step)->toBe(1);
});

test('max actions per step exceeded pauses the session and records reason', function () {
    agentTestOpenAiConfig();

    $path = agentTestTempDir();
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Public\n");

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Too many reads',
            'actions' => [
                ['tool' => 'read_file', 'path' => 'README.md'],
                ['tool' => 'list_files', 'path' => ''],
                ['tool' => 'git_diff'],
            ],
            'next_agent' => 'reviewer',
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Limit', 'Trigger action limit', 'develop', 'agent/limit', 20, 'readonly', null, 1);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    $metadata = $session->refresh()->metadata ?: [];

    expect($session->status)->toBe('paused');
    expect($metadata['blocked_reason'])->toBe('max_actions_per_step_exceeded');
    expect($metadata['paused_reason'])->toBe('max_actions_per_step_exceeded');
    expect($metadata['max_actions_per_step'])->toBe(1);
    expect($metadata['received_actions_count'])->toBe(3);
    expect($session->actions()->where('status', 'blocked')->count())->toBe(2);
});

test('max steps reached without completed status pauses instead of completing', function () {
    agentTestOpenAiConfig();

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Still working',
            'actions' => [],
            'next_agent' => null,
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Max steps', 'Do not complete automatically', 'develop', 'agent/max-steps', 1);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('paused');
    expect(($session->metadata ?: [])['paused_reason'])->toBe('max_steps_reached');
});

test('invalid next agent falls back safely', function () {
    agentTestOpenAiConfig();

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Invalid next',
            'actions' => [],
            'next_agent' => 'hacker',
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Invalid next', 'Fallback safely', 'develop', 'agent/invalid-next', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('running');
    expect(($session->metadata ?: [])['invalid_next_agent'])->toBe('hacker');
    expect(($session->metadata ?: [])['next_agent_role'])->toBe('reviewer');
    expect(($session->metadata ?: [])['readonly_next_agent_fallback_reason'])->toBe('invalid_next_agent');
});

test('repeated same next agent more than twice pauses loop detection', function () {
    agentTestOpenAiConfig();

    Http::fakeSequence()
        ->push(agentTestOpenAiResponse([
            'message' => 'Again planner',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]))
        ->push(agentTestOpenAiResponse([
            'message' => 'Again planner',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]))
        ->push(agentTestOpenAiResponse([
            'message' => 'Again planner',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]));

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Loop', 'Detect repeated next agent', 'develop', 'agent/loop', 20, 'sandbox');
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);
    $orchestrator->runStep($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('paused');
    expect(($session->metadata ?: [])['paused_reason'])->toBe('agent_loop_detected');
});

test('completed status only completes when there is no critical blocked action', function () {
    agentTestOpenAiConfig();

    $path = agentTestTempDir();
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Public\n");

    Http::fakeSequence()
        ->push(agentTestOpenAiResponse([
            'message' => 'Clean complete',
            'actions' => [],
            'next_agent' => null,
            'status' => 'completed',
        ]))
        ->push(agentTestOpenAiResponse([
            'message' => 'Blocked complete',
            'actions' => [
                ['tool' => 'read_file', 'path' => '.env'],
            ],
            'next_agent' => null,
            'status' => 'completed',
        ]));

    file_put_contents($path.DIRECTORY_SEPARATOR.'.env', "APP_KEY=secret\n");

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $clean = $orchestrator->createMission($workspace, 'Clean complete', 'Complete cleanly', 'develop', 'agent/clean-complete', 20);
    $blocked = $orchestrator->createMission($workspace, 'Blocked complete', 'Do not complete with blocked action', 'develop', 'agent/blocked-complete', 20);

    $orchestrator->startSession($clean);
    $orchestrator->runStep($clean);

    $orchestrator->startSession($blocked);
    $orchestrator->runStep($blocked);

    expect($clean->refresh()->status)->toBe('completed');
    expect($blocked->refresh()->status)->toBe('paused');
    expect(($blocked->metadata ?: [])['paused_reason'])->toBe('critical_action_blocked');
});

test('readonly planner can continue to planner with readonly tools', function () {
    agentTestOpenAiConfig();

    $path = agentTestTempDir();
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Public\n");

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Need more read-only context',
            'actions' => [
                ['tool' => 'read_file', 'path' => 'README.md'],
                ['tool' => 'list_files', 'path' => ''],
            ],
            'next_agent' => 'planner',
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Planner self loop', 'Read more', 'develop', 'agent/planner-loop', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    $metadata = $session->refresh()->metadata ?: [];

    expect($session->status)->toBe('running');
    expect($metadata['next_agent_role'])->toBe('planner');
    expect($metadata['readonly_planner_self_loops'])->toBe(1);
    expect($metadata['paused_reason'] ?? null)->toBeNull();
});

test('readonly planner self loop limit routes to reviewer automatically', function () {
    agentTestOpenAiConfig();
    config(['agents.workflow.readonly_planner_max_self_loops' => 3]);

    Http::fakeSequence()
        ->push(agentTestOpenAiResponse([
            'message' => 'Again',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]))
        ->push(agentTestOpenAiResponse([
            'message' => 'Again',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]))
        ->push(agentTestOpenAiResponse([
            'message' => 'Again',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]))
        ->push(agentTestOpenAiResponse([
            'message' => 'Again',
            'actions' => [],
            'next_agent' => 'planner',
            'status' => 'continue',
        ]));

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    agentTestAgent('planner');
    agentTestAgent('reviewer');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Loop to reviewer', 'Limit loops', 'develop', 'agent/loop-reviewer', 20);
    $orchestrator->startSession($session);

    $orchestrator->runStep($session);
    $orchestrator->runStep($session);
    $orchestrator->runStep($session);
    $orchestrator->runStep($session);

    $metadata = $session->refresh()->metadata ?: [];

    expect($session->status)->toBe('running');
    expect($metadata['readonly_planner_self_loops'])->toBe(4);
    expect($metadata['readonly_planner_self_loop_limit_reached'])->toBeTrue();
    expect($metadata['next_agent_role'])->toBe('reviewer');

    $nextAgent = $orchestrator->nextAgent($session);

    expect($nextAgent?->role)->toBe('reviewer');
});

test('readonly planner to planner with apply patch still pauses because action is unsafe', function () {
    agentTestOpenAiConfig();

    $path = agentTestTempDir();
    agentTestGitRepo($path);
    agentTestRun(['git', 'checkout', '-b', 'agent/unsafe-readonly'], $path);
    $patch = agentTestReadmePatch($path, 'Should not apply in readonly.');

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Unsafe self-loop',
            'actions' => [
                ['tool' => 'apply_patch', 'patch' => $patch],
            ],
            'next_agent' => 'planner',
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Unsafe readonly', 'Patch should block', 'develop', 'agent/unsafe-readonly', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('paused');
    expect(($session->metadata ?: [])['paused_reason'])->toBe('invalid_readonly_workflow');
    expect($session->actions()->first()->status)->toBe('blocked');
});

test('invalid next agent in readonly falls back to reviewer when actions are safe', function () {
    agentTestOpenAiConfig();

    $path = agentTestTempDir();
    file_put_contents($path.DIRECTORY_SEPARATOR.'README.md', "# Public\n");

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Bad next but safe action',
            'actions' => [
                ['tool' => 'read_file', 'path' => 'README.md'],
            ],
            'next_agent' => 'architect',
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace($path, ['name' => 'rexmarket']);
    agentTestAgent('planner');
    agentTestAgent('reviewer');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Invalid safe', 'Fallback reviewer', 'develop', 'agent/invalid-safe', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    $metadata = $session->refresh()->metadata ?: [];

    expect($session->status)->toBe('running');
    expect($metadata['invalid_next_agent'])->toBe('architect');
    expect($metadata['next_agent_role'])->toBe('reviewer');
    expect($metadata['readonly_next_agent_fallback_reason'])->toBe('invalid_next_agent');
});

test('invalid next agent in readonly with unsafe action pauses', function () {
    agentTestOpenAiConfig();

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Bad next and unsafe action',
            'actions' => [
                ['tool' => 'run_command', 'command' => 'git status --short'],
            ],
            'next_agent' => 'architect',
            'status' => 'continue',
        ])),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    agentTestAgent('planner');

    $orchestrator = app(AgentOrchestrator::class);
    $session = $orchestrator->createMission($workspace, 'Invalid unsafe', 'Pause invalid unsafe', 'develop', 'agent/invalid-unsafe', 20);
    $orchestrator->startSession($session);
    $orchestrator->runStep($session);

    expect($session->refresh()->status)->toBe('paused');
    expect(($session->metadata ?: [])['paused_reason'])->toBe('invalid_readonly_workflow');
});

test('readonly reviewer completed makes the session completed', function () {
    agentTestOpenAiConfig();

    Http::fake([
        'https://llm.example.test/v1/chat/completions' => Http::response(agentTestOpenAiResponse([
            'message' => 'Review complete',
            'actions' => [],
            'next_agent' => null,
            'status' => 'completed',
        ])),
    ]);

    $workspace = agentTestWorkspace(agentTestTempDir(), ['name' => 'rexmarket']);
    $reviewer = agentTestAgent('reviewer');
    $session = agentTestSession($workspace, $reviewer, 'agent/reviewer-complete', [
        'mode' => 'readonly',
        'next_agent_role' => 'reviewer',
    ]);
    $session->forceFill([
        'metadata' => ['next_agent_role' => 'reviewer'],
    ])->save();

    app(AgentOrchestrator::class)->runStep($session);

    expect($session->refresh()->status)->toBe('completed');
});
