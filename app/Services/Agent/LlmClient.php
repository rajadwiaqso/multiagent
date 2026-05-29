<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentSession;

interface LlmClient
{
    /**
     * @return array{content: string, metadata?: array<string, mixed>}
     */
    public function send(AgentSession $session, Agent $agent, string $context): array;
}
