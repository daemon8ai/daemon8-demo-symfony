<?php

declare(strict_types=1);

namespace App\Byok;

/**
 * Stub — returns a "coming soon" ProviderException when used. The scenario
 * UI still lists OpenAI in the provider select so the path is discoverable,
 * but the scenario runner surfaces the typed exception cleanly rather than
 * half-implementing a second provider.
 */
final class OpenAIProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    /**
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param  array<int, array{role: string, content: mixed}> $messages
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $systemPrompt, array $tools, array $messages): \Generator
    {
        $note = 'OpenAI provider is not yet implemented — use anthropic for now'
            . ' (key length: ' . strlen($this->apiKey) . ')';
        throw new ProviderException($note, 0, 'openai');
        /** @phpstan-ignore-next-line — unreachable yield keeps this a valid Generator */
        yield ['type' => 'done', 'system' => $systemPrompt, 'tools' => $tools, 'messages' => $messages];
    }
}
