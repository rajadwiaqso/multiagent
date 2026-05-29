<?php

namespace App\Console\Commands;

use App\Models\AgentMessage;
use App\Models\AgentSession;
use Illuminate\Console\Command;

class AgentsReportCommand extends Command
{
    protected $signature = 'agents:report {session_id : Agent session id}';

    protected $description = 'Show a mission report with tasks, actions, commits, and recent messages.';

    public function handle(): int
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
        $this->line('Status: '.$session->status);
        $this->newLine();
        $this->line($session->mission);

        $this->newLine();
        $this->info('Tasks');
        $session->tasks->each(fn ($task) => $this->line("[{$task->status}] {$task->title}"));

        $this->newLine();
        $this->info('Actions');
        $session->actions->each(fn ($action) => $this->line("[{$action->status}] {$action->type} ".($action->agent?->name ?: '-')));

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
}
