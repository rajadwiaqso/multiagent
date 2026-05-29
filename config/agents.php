<?php

return [
    'target_env_key' => 'TARGET_WORKSPACE_PATH',

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
