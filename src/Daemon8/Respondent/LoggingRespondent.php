<?php

declare(strict_types=1);

namespace App\Daemon8\Respondent;

use Daemon8\Contracts\Respondent;
use Daemon8\Filter;
use Daemon8\Observation;
use Psr\Log\LoggerInterface;

/**
 * Minimal respondent fixture — logs every observation the daemon routes back
 * into the app. Auto-tagged via Daemon8Bundle's registerForAutoconfiguration;
 * no manual wiring required.
 */
final class LoggingRespondent implements Respondent
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function interest(): Filter
    {
        return new Filter();
    }

    public function respond(Observation $observation): void
    {
        $this->logger->debug('respondent-saw-observation', [
            'kind' => $observation->kind->value,
            'channel' => $observation->channel,
        ]);
    }
}
