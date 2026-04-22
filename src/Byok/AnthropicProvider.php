<?php

declare(strict_types=1);

namespace App\Byok;

/**
 * Anthropic Messages API streaming provider.
 *
 * Uses curl with CURLOPT_WRITEFUNCTION to parse server-sent events as they
 * arrive, yielding normalized event dicts that the scenario runner pumps
 * straight through to the browser. Tool use blocks are accumulated across
 * content_block_delta frames until content_block_stop emits the final call.
 *
 * Wire format follows docs.anthropic.com/en/api/messages — stream=true,
 * anthropic-version: 2023-06-01, x-api-key header. No Bearer prefix.
 *
 * Symfony's HttpClient intentionally not used for the stream: it buffers
 * SSE bodies more aggressively than raw curl, and we need chunked write
 * callbacks to yield tool-use frames in order as they arrive. The BYOK
 * key never hits any log — curl writes request bytes directly to the
 * socket, and the response parser discards on-wire tokens after decode.
 */
final class AnthropicProvider implements LlmProvider
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';
    private const string MODEL = 'claude-sonnet-4-5-20250929';

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    /**
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param  array<int, array{role: string, content: mixed}> $messages
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $systemPrompt, array $tools, array $messages): \Generator
    {
        $payload = [
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => $messages,
            'tools' => $tools,
            'stream' => true,
        ];

        $buffer = '';
        $events = [];
        $currentEvent = null;
        $toolBlocks = [];

        $handle = curl_init(self::API_URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function (
                $_curl,
                string $data
            ) use (
                &$buffer,
                &$events,
                &$currentEvent
): int {
                $buffer .= $data;
                while (($lineEnd = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $lineEnd), "\r");
                    $buffer = substr($buffer, $lineEnd + 1);
                    if ($line === '') {
                        continue;
                    }
                    if (str_starts_with($line, 'event: ')) {
                        $currentEvent = substr($line, 7);
                        continue;
                    }
                    if (str_starts_with($line, 'data: ')) {
                        $json = substr($line, 6);
                        try {
                            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            continue;
                        }
                        if (! is_array($decoded)) {
                            continue;
                        }
                        $events[] = ['event' => $currentEvent ?? 'unknown', 'data' => $decoded];
                    }
                }
                return strlen($data);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (! $ok && $status === 0) {
            throw new ProviderException("anthropic transport failed: {$error}", 0, 'anthropic');
        }
        if ($status >= 400) {
            throw new ProviderException("anthropic returned HTTP {$status}", $status, 'anthropic');
        }

        foreach ($events as $raw) {
            $event = $raw['event'];
            $data = $raw['data'];

            if ($event === 'content_block_start' && isset($data['content_block']['type'])) {
                $type = $data['content_block']['type'];
                $index = (int) ($data['index'] ?? 0);
                if ($type === 'tool_use') {
                    $toolBlocks[$index] = [
                        'id' => (string) ($data['content_block']['id'] ?? ''),
                        'name' => (string) ($data['content_block']['name'] ?? ''),
                        'input' => '',
                    ];
                }
                continue;
            }

            if ($event === 'content_block_delta' && isset($data['delta']['type'])) {
                $deltaType = $data['delta']['type'];
                $index = (int) ($data['index'] ?? 0);
                if ($deltaType === 'text_delta') {
                    yield ['type' => 'text', 'text' => (string) ($data['delta']['text'] ?? '')];
                } elseif ($deltaType === 'input_json_delta' && isset($toolBlocks[$index])) {
                    $toolBlocks[$index]['input'] .= (string) ($data['delta']['partial_json'] ?? '');
                }
                continue;
            }

            if ($event === 'content_block_stop') {
                $index = (int) ($data['index'] ?? 0);
                if (isset($toolBlocks[$index])) {
                    $block = $toolBlocks[$index];
                    $input = [];
                    if ($block['input'] !== '') {
                        try {
                            $decoded = json_decode($block['input'], true, 8, JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                $input = $decoded;
                            }
                        } catch (\JsonException) {
                        }
                    }
                    yield ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => $input];
                    unset($toolBlocks[$index]);
                }
                continue;
            }

            if ($event === 'message_stop') {
                yield ['type' => 'done'];
            }
        }
    }
}
