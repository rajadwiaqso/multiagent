<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentSession;

class FakeLlmClient implements LlmClient
{
    public function send(AgentSession $session, Agent $agent, string $context): array
    {
        $protocol = match ($agent->role) {
            'planner' => [
                'message' => "Planner stub membaca struktur awal untuk mission \"{$session->title}\".",
                'actions' => [
                    [
                        'tool' => 'list_files',
                        'path' => '',
                        'depth' => 1,
                    ],
                    [
                        'tool' => 'read_file',
                        'path' => 'routes/web.php',
                    ],
                ],
                'next_agent' => 'implementer',
                'status' => 'continue',
            ],
            'implementer' => [
                'message' => 'Implementer stub mengecek status dan diff tanpa menulis patch otomatis.',
                'actions' => [
                    [
                        'tool' => 'git_status',
                    ],
                    [
                        'tool' => 'git_diff',
                    ],
                ],
                'next_agent' => 'reviewer',
                'status' => 'continue',
            ],
            'reviewer' => [
                'message' => 'Reviewer stub melakukan review status/diff dan menyelesaikan mission fake.',
                'actions' => [
                    [
                        'tool' => 'git_status',
                    ],
                    [
                        'tool' => 'git_diff',
                    ],
                ],
                'next_agent' => null,
                'status' => 'completed',
            ],
            default => [
                'message' => "Agent stub ({$agent->role}) belum punya behavior khusus.",
                'actions' => [],
                'next_agent' => null,
                'status' => 'continue',
            ],
        };

        return [
            'content' => json_encode($this->withoutInvalidActions($protocol), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'metadata' => [
                'fake' => true,
                'agent_role' => $agent->role,
                'context_characters' => strlen($context),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return array<string, mixed>
     */
    private function withoutInvalidActions(array $protocol): array
    {
        $actions = $protocol['actions'] ?? [];

        if (! is_array($actions)) {
            $protocol['actions'] = [];

            return $protocol;
        }

        $protocol['actions'] = array_values(array_filter($actions, function (mixed $action): bool {
            return is_array($action)
                && isset($action['tool'])
                && is_string($action['tool'])
                && trim($action['tool']) !== '';
        }));

        return $protocol;
    }
}
