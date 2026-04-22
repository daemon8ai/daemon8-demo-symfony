<?php

declare(strict_types=1);

namespace App\Byok;

use Daemon8\Daemon8Client;
use Daemon8\Kind;
use Daemon8\Severity;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestrates the chaos → observe → fix loop inside a single SSE
 * response. The runner writes events directly to stdout via a callback
 * supplied by the HTTP handler, so the browser sees each phase land in
 * real time.
 *
 * Shape of events written to the stream (one JSON object per SSE frame):
 *   {phase: "starting"}
 *   {phase: "chaos-tool", tool: "break_auth", input: {...}}
 *   {phase: "chaos-result", response: {...}}
 *   {phase: "observation", observation: {...}}
 *   {phase: "fixer-tool", tool: "fix_auth", input: {...}}
 *   {phase: "fixer-result", response: {...}}
 *   {phase: "resolved"}   | {phase: "timeout"} | {phase: "error", message: ...}
 *
 * The loop has hard safety rails: 60s overall timeout, 10 tool calls per
 * role. Providers other than Anthropic raise ProviderException immediately
 * and the runner writes a clean error frame.
 *
 * Symfony-specific wiring:
 *   - Uses HttpClientInterface for the self-HTTP loop so the outbound
 *     decorator picks up each internal tool invocation as an observation.
 *   - Daemon8Client is constructed from the bundle-managed Config — the
 *     same one the subscribers write to, so observations emitted here
 *     share the correlation_id / profiler_token stamp with the request.
 */
final class ScenarioRunner
{
    private const int OVERALL_TIMEOUT_SEC = 60;
    private const int MAX_TOOL_CALLS = 10;

    public function __construct(
        private readonly Daemon8Client $client,
        private readonly HttpClientInterface $http,
        private readonly LlmProvider $provider,
        private readonly string $scenarioSlug,
        private readonly string $selfBaseUrl,
    ) {
    }

    /** @param callable(array<string, mixed>): void $emit */
    public function run(callable $emit): void
    {
        $start = microtime(true);
        $emit(['phase' => 'starting', 'scenario' => $this->scenarioSlug, 'provider' => $this->provider->name()]);

        $this->client->send(
            data: ['message' => 'chaos scenario started', 'scenario' => $this->scenarioSlug],
            severity: Severity::Info->value,
            kind: Kind::Custom->value,
            channel: 'scenario',
        );

        try {
            $chaosSince = $this->currentCheckpoint();
            $this->runRole($emit, 'chaos', $this->chaosSystemPrompt(), $this->chaosTools(), $start);

            $emit(['phase' => 'observing', 'note' => 'watching daemon8 stream for warnings and errors']);
            $warnings = $this->collectWarnings($chaosSince, 3.0);
            foreach ($warnings as $observation) {
                $emit(['phase' => 'observation', 'observation' => $observation]);
            }

            if ($warnings === []) {
                $emit(['phase' => 'resolved', 'note' => 'no chaos observations surfaced — nothing to fix']);
                return;
            }

            $this->runRole(
                emit: $emit,
                role: 'fixer',
                systemPrompt: $this->fixerSystemPrompt($warnings),
                tools: $this->fixerTools(),
                start: $start,
            );

            $emit(['phase' => 'resolved']);
        } catch (ProviderException $exception) {
            $emit([
                'phase' => 'error',
                'message' => $exception->getMessage(),
                'provider' => $exception->providerName,
                'http_status' => $exception->httpStatus,
            ]);
        } catch (\Throwable $exception) {
            $emit([
                'phase' => 'error',
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ]);
        }
    }

    /**
     * @param callable(array<string, mixed>): void $emit
     * @param array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     */
    private function runRole(callable $emit, string $role, string $systemPrompt, array $tools, float $start): void
    {
        $toolCalls = 0;
        $messages = [[
            'role' => 'user',
            'content' => $role === 'chaos'
                ? 'Begin your chaos run. Pick one tool call and execute it.'
                : 'The observation stream shows these warnings. Call the right fix tool to restore order.',
        ]];

        while (true) {
            if ((microtime(true) - $start) >= self::OVERALL_TIMEOUT_SEC) {
                $emit(['phase' => 'timeout', 'role' => $role, 'reason' => 'overall 60s budget exhausted']);
                return;
            }
            if ($toolCalls >= self::MAX_TOOL_CALLS) {
                $emit(['phase' => 'tool-limit', 'role' => $role, 'reason' => '10 tool calls reached']);
                return;
            }

            $turn = [];
            foreach ($this->provider->stream($systemPrompt, $tools, $messages) as $event) {
                if ($event['type'] === 'text') {
                    $emit(['phase' => "{$role}-text", 'text' => (string) ($event['text'] ?? '')]);
                    continue;
                }
                if ($event['type'] === 'tool_use') {
                    $toolName = (string) ($event['name'] ?? '');
                    /** @var array<string, mixed> $input */
                    $input = is_array($event['input'] ?? null) ? $event['input'] : [];
                    $emit(['phase' => "{$role}-tool", 'tool' => $toolName, 'input' => $input]);

                    $result = $this->invokeTool($toolName, $input);
                    $emit(['phase' => "{$role}-result", 'tool' => $toolName, 'response' => $result]);

                    $turn[] = [
                        'type' => 'tool_use',
                        'id' => (string) ($event['id'] ?? ''),
                        'name' => $toolName,
                        'input' => $input,
                    ];
                    $turn[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($event['id'] ?? ''),
                        'content' => json_encode($result, JSON_THROW_ON_ERROR),
                    ];
                    $toolCalls++;
                }
            }

            if ($turn === []) {
                return;
            }

            $toolUse = array_values(array_filter($turn, static fn($b): bool => $b['type'] === 'tool_use'));
            $toolResult = array_values(array_filter($turn, static fn($b): bool => $b['type'] === 'tool_result'));
            $messages[] = ['role' => 'assistant', 'content' => $toolUse];
            $messages[] = ['role' => 'user', 'content' => $toolResult];
        }
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function invokeTool(string $toolName, array $input): array
    {
        $endpoint = match ($toolName) {
            'break_auth' => '/demo/break-auth',
            'break_job' => '/demo/break-job',
            'break_js' => '/demo/break-js',
            'fix_auth' => '/demo/fix-auth',
            'fix_job' => '/demo/fix-job',
            default => null,
        };

        if ($endpoint === null) {
            return ['error' => "unknown tool '{$toolName}'"];
        }

        $url = rtrim($this->selfBaseUrl, '/') . $endpoint;
        try {
            $response = $this->http->request('POST', $url, [
                'json' => $input,
                'timeout' => 5,
                'max_duration' => 8,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(throw: false);
            $decoded = json_decode($content, true);
            return [
                'status' => $status,
                'body' => is_array($decoded) ? $decoded : ['raw' => $content],
            ];
        } catch (\Throwable $e) {
            return ['error' => 'invocation failed', 'message' => $e->getMessage()];
        }
    }

    private function currentCheckpoint(): int
    {
        $result = $this->client->observe(limit: 1);
        return $result['checkpoint'];
    }

    /** @return list<array<string, mixed>> */
    private function collectWarnings(int $since, float $waitSec): array
    {
        $deadline = microtime(true) + $waitSec;
        $collected = [];
        while (microtime(true) < $deadline) {
            $result = $this->client->observe(severityMin: 'warn', since: $since, limit: 50);
            if ($result['observations'] !== []) {
                $collected = $result['observations'];
                break;
            }
            usleep(250_000);
        }
        return $collected;
    }

    private function chaosSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the CHAOS agent in a daemon8 Symfony demo. You have two tools that
simulate production failures: break_auth (invalidates the current security
token) and break_job (dispatches a Messenger message whose handler throws).

Pick ONE tool and call it with a brief reason. Keep the input small — just
a short "reason" string. Do not call more than one tool. Do not emit chat
text before the tool call.
PROMPT;
    }

    /** @param list<array<string, mixed>> $warnings */
    private function fixerSystemPrompt(array $warnings): string
    {
        $summary = json_encode(array_map(static function (array $obs): array {
            return [
                'kind' => $obs['kind'] ?? null,
                'severity' => $obs['severity'] ?? null,
                'data' => $obs['data'] ?? null,
            ];
        }, $warnings), JSON_THROW_ON_ERROR);

        return <<<PROMPT
You are the FIXER agent in a daemon8 Symfony demo. The observation stream
shows the following warnings captured by daemon8:

{$summary}

You have two repair tools: fix_auth (re-issues the security token and logs
the user back in) and fix_job (retries the failing message with a clean
payload). Choose exactly one based on what you see. Call it with a short
"reason". Do not emit chat text before the tool call.
PROMPT;
    }

    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    private function chaosTools(): array
    {
        return [
            [
                'name' => 'break_auth',
                'description' => 'Invalidate the current security token to simulate a 401 cascade.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string']],
                    'required' => ['reason'],
                ],
            ],
            [
                'name' => 'break_job',
                'description' => 'Dispatch a Messenger message whose handler throws.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string']],
                    'required' => ['reason'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    private function fixerTools(): array
    {
        return [
            [
                'name' => 'fix_auth',
                'description' => 'Refresh the session and restore authenticated access.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string']],
                    'required' => ['reason'],
                ],
            ],
            [
                'name' => 'fix_job',
                'description' => 'Retry the failing background job with a corrected payload.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['reason' => ['type' => 'string']],
                    'required' => ['reason'],
                ],
            ],
        ];
    }
}
