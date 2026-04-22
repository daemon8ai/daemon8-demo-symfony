<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ChaosMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * ChaosMessage handler. mode=broken throws so MessengerSubscriber captures
 * the failure-path observation (WorkerMessageFailedEvent under async, or a
 * direct throw under sync:// — the subscriber handles both). mode=clean
 * emits an info log so the fixer can assert "success" cleanly.
 */
#[AsMessageHandler]
final class ChaosMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChaosMessage $message): void
    {
        if ($message->mode === 'broken') {
            throw new \RuntimeException('chaos job broken payload: ' . $message->reason);
        }

        $this->logger->info('chaos-message-handled', [
            'mode' => $message->mode,
            'reason' => $message->reason,
        ]);
    }
}
