<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentSession;

class FakeLlmClient implements LlmClient
{
    public function send(AgentSession $session, Agent $agent, string $context): array
    {
        $content = match ($agent->role) {
            'planner' => "Planner stub: membuat task awal untuk mission \"{$session->title}\".",
            'implementer' => 'Implementer stub: struktur siap untuk patch kecil, belum menulis kode target.',
            'reviewer' => 'Reviewer stub: mengecek scope, safety, dan flow mission tanpa LLM asli.',
            default => "Agent stub ({$agent->role}): belum ada behavior khusus.",
        };

        return [
            'content' => $content,
            'metadata' => [
                'fake' => true,
                'agent_role' => $agent->role,
                'context_characters' => strlen($context),
            ],
        ];
    }
}
