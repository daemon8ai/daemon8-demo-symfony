<?php

declare(strict_types=1);

namespace App\Byok;

/**
 * Stub — Gemini provider is on the roadmap but not wired. The scenario
 * UI surfaces a clean "coming soon" event rather than failing mysteriously.
 */
final class GeminiProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    /**
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param  array<int, array{role: string, content: mixed}> $messages
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $systemPrompt, array $tools, array $messages): \Generator
    {
        $note = 'Gemini provider is not yet implemented — use anthropic for now'
            . ' (key length: ' . strlen($this->apiKey) . ')';
        throw new ProviderException($note, 0, 'gemini');
        /** @phpstan-ignore-next-line — unreachable yield keeps this a valid Generator */
        yield ['type' => 'done', 'system' => $systemPrompt, 'tools' => $tools, 'messages' => $messages];
    }
}
