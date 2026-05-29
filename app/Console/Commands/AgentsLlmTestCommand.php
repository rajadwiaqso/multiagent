<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Services\Agent\OpenAiCompatibleLlmClient;
use Illuminate\Console\Command;
use Throwable;

class AgentsLlmTestCommand extends Command
{
    protected $signature = 'agents:llm:test {--timeout=120 : Request timeout in seconds}';

    protected $description = 'Test the configured OpenAI-compatible LLM provider without creating a mission.';

    public function handle(OpenAiCompatibleLlmClient $client): int
    {
        $timeout = max(1, (int) $this->option('timeout'));

        config(['agents.llm.timeout' => $timeout]);

        $agent = new Agent([
            'name' => 'LLM Test Agent',
            'role' => 'planner',
            'system_prompt' => 'Return only valid JSON for the RexMarket local multi-agent protocol.',
            'enabled' => true,
            'sort_order' => 0,
        ]);

        $session = new AgentSession([
            'title' => 'LLM provider smoke test',
            'mission' => 'Return a minimal valid JSON response for provider connectivity testing.',
            'status' => 'running',
            'mode' => 'readonly',
            'base_branch' => config('agents.workspace.base_branch', 'develop'),
            'agent_branch' => config('agents.workspace.agent_branch_prefix', 'agent').'/llm-test',
            'current_step' => 0,
            'max_steps' => 1,
            'max_actions_per_step' => 1,
            'metadata' => [],
        ]);

        try {
            $response = $client->send(
                $session,
                $agent,
                'Provider smoke test. Return {"message":"ok","actions":[],"next_agent":null,"status":"completed"}.'
            );
        } catch (Throwable $throwable) {
            $this->error('LLM provider test failed: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $decoded = json_decode(trim($response['content']), true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $this->error('LLM provider responded, but content was not valid JSON action protocol.');
            $this->line(substr($response['content'], 0, 500));

            return self::FAILURE;
        }

        $this->info('LLM provider test succeeded.');
        $this->line('Model: '.(string) config('agents.llm.model'));
        $this->line('Timeout: '.$timeout.'s');
        $this->line('Status: '.($decoded['status'] ?? '-'));
        $this->line('Message: '.($decoded['message'] ?? '-'));

        return self::SUCCESS;
    }
}
