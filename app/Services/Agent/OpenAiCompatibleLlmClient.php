<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\AgentSession;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiCompatibleLlmClient implements LlmClientInterface
{
    /**
     * @return array{content: string, metadata?: array<string, mixed>}
     */
    public function send(AgentSession $session, Agent $agent, string $context): array
    {
        $baseUrl = rtrim((string) config('agents.llm.base_url'), '/');
        $apiKey = (string) config('agents.llm.api_key');
        $model = (string) config('agents.llm.model');

        if ($baseUrl === '') {
            throw new RuntimeException('AGENT_LLM_BASE_URL is not configured.');
        }

        if ($apiKey === '') {
            throw new RuntimeException('AGENT_LLM_API_KEY is not configured.');
        }

        if ($model === '') {
            throw new RuntimeException('AGENT_LLM_MODEL is not configured.');
        }

        $timeout = (int) config('agents.llm.timeout', 120);
        $retryTimes = (int) config('agents.llm.retry.times', 1);
        $retrySleepMs = (int) config('agents.llm.retry.sleep_ms', 500);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->retry($retryTimes, $retrySleepMs)
            ->withAttributes([
                'agent_llm_timeout' => $timeout,
                'agent_llm_retry_times' => $retryTimes,
                'agent_llm_retry_sleep_ms' => $retrySleepMs,
            ])
            ->post($this->chatCompletionsUrl($baseUrl), [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $agent->system_prompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->userPrompt($session, $context),
                    ],
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('LLM request failed with HTTP status '.$response->status().'.');
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('LLM response did not contain choices.0.message.content.');
        }

        return [
            'content' => $content,
            'metadata' => [
                'driver' => 'openai-compatible',
                'model' => $model,
                'base_url' => $baseUrl,
                'usage' => $response->json('usage'),
            ],
        ];
    }

    private function chatCompletionsUrl(string $baseUrl): string
    {
        if (str_ends_with($baseUrl, '/chat/completions')) {
            return $baseUrl;
        }

        return $baseUrl.'/chat/completions';
    }

    private function userPrompt(AgentSession $session, string $context): string
    {
        return implode(PHP_EOL.PHP_EOL, [
            'Return only valid JSON using this schema:',
            <<<'JSON'
{
  "message": "...",
  "actions": [
    { "tool": "read_file", "path": "routes/web.php" },
    { "tool": "list_files", "path": "app/Models" },
    { "tool": "apply_patch", "patch": "..." },
    { "tool": "run_command", "command": "php artisan test" },
    { "tool": "git_status" },
    { "tool": "git_diff" },
    { "tool": "commit", "message": "feat(scope): message" }
  ],
  "next_agent": "implementer|planner|reviewer|null",
  "status": "continue|waiting_approval|completed|failed"
}
JSON,
            'Mission title: '.$session->title,
            'Context:',
            $context,
        ]);
    }
}
