<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentSession;

interface LlmClientInterface
{
    /**
     * The content must be a JSON action protocol payload.
     *
     * @return array{content: string, metadata?: array<string, mixed>}
     */
    public function send(AgentSession $session, Agent $agent, string $context): array;
}
