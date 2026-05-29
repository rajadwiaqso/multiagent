<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\AgentSession;
use Illuminate\Support\Str;

class AgentContextService
{
    public function buildContext(AgentSession $session, Agent $agent): string
    {
        $session->loadMissing(['workspace', 'messages.agent', 'tasks']);

        $messages = $session->messages()
            ->with('agent')
            ->latest()
            ->limit(10)
            ->get()
            ->reverse()
            ->map(fn (AgentMessage $message): string => sprintf(
                '[%s%s] %s',
                $message->role,
                $message->agent?->role ? ':'.$message->agent->role : '',
                Str::limit($message->content, 600)
            ))
            ->implode(PHP_EOL);

        $tasks = $session->tasks()
            ->latest()
            ->limit(10)
            ->get()
            ->reverse()
            ->map(fn ($task): string => "- {$task->status}: {$task->title}")
            ->implode(PHP_EOL);

        return implode(PHP_EOL.PHP_EOL, array_filter([
            "Workspace: {$session->workspace->name} ({$session->workspace->type})",
            "Mission: {$session->title}".PHP_EOL.$session->mission,
            "Session status: {$session->status}; step {$session->current_step}/{$session->max_steps}",
            "Current agent: {$agent->name} ({$agent->role})".PHP_EOL.$agent->system_prompt,
            $session->summary_context ? "Summary: {$session->summary_context}" : null,
            $tasks ? 'Tasks:'.PHP_EOL.$tasks : null,
            $messages ? 'Recent messages:'.PHP_EOL.$messages : null,
        ]));
    }

    public function appendMessage(AgentSession $session, ?Agent $agent, string $role, string $content, array $metadata = []): AgentMessage
    {
        return AgentMessage::query()->create([
            'session_id' => $session->id,
            'agent_id' => $agent?->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    public function updateSummary(AgentSession $session): void
    {
        $summary = $session->messages()
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn (AgentMessage $message): string => '['.$message->role.'] '.Str::limit($message->content, 240))
            ->implode(PHP_EOL);

        $session->forceFill([
            'summary_context' => $summary,
        ])->save();
    }
}
