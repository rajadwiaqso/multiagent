<?php

return [
    'target_env_key' => 'TARGET_WORKSPACE_PATH',

    'llm' => [
        'driver' => env('AGENT_LLM_DRIVER', 'fake'),
        'base_url' => env('AGENT_LLM_BASE_URL'),
        'api_key' => env('AGENT_LLM_API_KEY'),
        'model' => env('AGENT_LLM_MODEL'),
        'timeout' => env('AGENT_LLM_TIMEOUT', 120),
        'retry' => [
            'times' => env('AGENT_LLM_RETRY_TIMES', 1),
            'sleep_ms' => env('AGENT_LLM_RETRY_SLEEP_MS', 500),
        ],
    ],

    'workflow' => [
        'action_limit_exceeded_status' => env('AGENT_ACTION_LIMIT_EXCEEDED_STATUS', 'paused'),
        'readonly_planner_max_self_loops' => env('AGENT_READONLY_PLANNER_MAX_SELF_LOOPS', 3),
    ],

    'workspace' => [
        'name' => 'rexmarket',
        'type' => 'laravel-vilt',
        'base_branch' => 'develop',
        'agent_branch_prefix' => 'agent',
    ],

    'protected_paths' => [
        '.env',
        '.env.*',
        'storage/',
        'vendor/',
        'node_modules/',
        'bootstrap/cache/',
        'config/services.php',
        'config/database.php',
        'app/Services/Payment/',
        'app/Services/Withdraw/',
        'app/Services/Balance/',
    ],

    'allowed_commands' => [
        'git status --short',
        'git diff',
        'php artisan test',
        'npm run build',
        './vendor/bin/pint --test',
    ],

    'approval_required_commands' => [
        'php artisan migrate',
        'composer install',
        'composer update',
        'npm install',
        'git push',
    ],

    'blocked_commands' => [
        'rm -rf',
        'sudo',
        'php artisan migrate:fresh',
        'php artisan db:wipe',
        'deploy',
        'ssh',
        'scp',
        'chmod -R 777',
    ],

    'dangerous_branches' => [
        'main',
        'master',
        'develop',
        'production',
        'staging',
    ],
];
