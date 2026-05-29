<?php

namespace App\Console\Commands;

use App\Models\AgentMessage;
use App\Models\AgentSession;
use App\Services\Workspace\TargetGitService;
use Illuminate\Console\Command;

class AgentsReportCommand extends Command
{
    protected $signature = 'agents:report {session_id : Agent session id}';

    protected $description = 'Show a mission report with tasks, actions, commits, and recent messages.';

    public function handle(TargetGitService $gitService): int
    {
        $session = AgentSession::query()
            ->with(['workspace', 'tasks', 'actions.agent', 'commits', 'messages.agent'])
            ->find($this->argument('session_id'));

        if ($session === null) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        $this->info("Mission: {$session->title}");
        $this->line('Workspace: '.$session->workspace->name);
        $this->line('Branch: '.$session->agent_branch);
        $this->line('Mode: '.$session->mode);
        $this->line('Status: '.$session->status);
        $this->line('Reason: '.($this->stopReason($session) ?: '-'));
        $this->line('Readonly planner self-loops: '.(($session->metadata ?: [])['readonly_planner_self_loops'] ?? 0));
        $this->line('Diff empty: '.$this->diffEmpty($gitService, $session));

        if (($session->metadata ?: [])['blocked_reason'] ?? null) {
            $this->warn('Warning: '.($session->metadata['blocked_reason']));
        }

        if (($session->metadata ?: [])['readonly_planner_self_loop_limit_reached'] ?? false) {
            $this->warn('Warning: readonly_planner_self_loop_limit_reached');
        }

        $this->newLine();
        $this->line($session->mission);

        $this->newLine();
        $this->info('Tasks');
        $session->tasks->each(fn ($task) => $this->line("[{$task->status}] {$task->title}"));

        $this->newLine();
        $this->info('Actions');
        $session->actions->each(fn ($action) => $this->line("[{$action->status}] {$action->type} ".($action->agent?->name ?: '-').($action->error ? ' error: '.$action->error : '')));

        $blockedActions = $session->actions->where('status', 'blocked');

        if ($blockedActions->isNotEmpty()) {
            $this->newLine();
            $this->warn('Blocked Actions');
            $blockedActions->each(fn ($action) => $this->line("#{$action->id} {$action->type}: {$action->error}"));
        }

        $this->newLine();
        $this->info('Commits');
        $session->commits->each(fn ($commit) => $this->line("[{$commit->branch}] {$commit->commit_hash} {$commit->commit_message}"));

        $this->newLine();
        $this->info('Recent Messages');
        $session->messages()
            ->with('agent')
            ->latest()
            ->limit(5)
            ->get()
            ->reverse()
            ->each(fn (AgentMessage $message) => $this->line("[{$message->role}] ".($message->agent?->name ?: 'system').': '.$message->content));

        return self::SUCCESS;
    }

    private function stopReason(AgentSession $session): ?string
    {
        $metadata = $session->metadata ?: [];

        return $metadata['stop_reason']
            ?? $metadata['paused_reason']
            ?? $metadata['failure_reason']
            ?? $metadata['blocked_reason']
            ?? $metadata['llm_error']
            ?? null;
    }

    private function diffEmpty(TargetGitService $gitService, AgentSession $session): string
    {
        try {
            return trim($gitService->diff($session->workspace, $session->base_branch)) === '' ? 'yes' : 'no';
        } catch (\Throwable $throwable) {
            return 'unknown ('.$throwable->getMessage().')';
        }
    }
}
