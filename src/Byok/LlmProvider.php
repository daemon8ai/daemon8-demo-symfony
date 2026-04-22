<?php

declare(strict_types=1);

namespace App\Byok;

/**
 * Minimal contract for a chat-with-tools provider. Implementations yield
 * incremental events as the LLM streams its response: text chunks, tool
 * calls, and an end-of-message sentinel. The scenario runner pumps this
 * stream directly into its own SSE output to the browser.
 *
 * Shape intentionally narrow — the demo only needs tool-use streaming.
 * Full provider parity is out of scope.
 */
interface LlmProvider
{
    /**
     * @param  array<int, array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param  array<int, array{role: string, content: mixed}> $messages
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $systemPrompt, array $tools, array $messages): \Generator;

    public function name(): string;
}
