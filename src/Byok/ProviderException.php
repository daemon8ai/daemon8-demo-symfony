<?php

declare(strict_types=1);

namespace App\Byok;

/**
 * Raised when a provider refuses or fails — bad key, rate limit, network
 * failure, unsupported provider. The scenario runner catches this and
 * writes a typed SSE event so the browser can surface a clean message
 * without crashing the stream.
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $providerName = '',
    ) {
        parent::__construct($message);
    }
}
