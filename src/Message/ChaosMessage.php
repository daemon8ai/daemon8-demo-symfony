<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Two-mode chaos payload driven by the chaos/fixer scenario. mode=broken
 * routes through a handler that throws so MessengerSubscriber captures the
 * failure; mode=clean lets the handler complete and emit a success log.
 *
 * Routed through the default sync:// transport to keep the demo self-
 * contained — no worker required for the scenario loop, but `composer dev`
 * still runs `messenger:consume async -vv` so the transport is exercised.
 */
final class ChaosMessage
{
    public function __construct(
        public readonly string $mode = 'broken',
        public readonly string $reason = '',
    ) {
    }
}
