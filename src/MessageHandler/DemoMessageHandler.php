<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DemoMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DemoMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DemoMessage $message): void
    {
        $this->logger->info('demo-message-handled', ['payload' => $message->payload]);
    }
}
