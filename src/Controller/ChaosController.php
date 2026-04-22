<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\ChaosMessage;
use Daemon8\Daemon8Client;
use Daemon8\Kind;
use Daemon8\Severity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Chaos + fixer endpoints driven by the BYOK scenario. Each endpoint wires
 * through a real Symfony primitive so the observation stream captures
 * authentic subscriber output — TokenStorage::setToken(null) fires the
 * SecuritySubscriber, a throwing ChaosMessageHandler trips the
 * MessengerSubscriber (+ exception logger), etc.
 *
 * All observations carry a correlation_id under data.* so the
 * ProfilerCorrelatingBuffer stamps them onto the request's profiler
 * token — WebProfiler deep-links from the live panel straight back to
 * the profiler panel that produced the correlation.
 */
final class ChaosController extends AbstractController
{
    public function __construct(
        private readonly Daemon8Client $daemon8,
    ) {
    }

    #[Route('/demo/break-auth', name: 'demo_break_auth', methods: ['POST'])]
    public function breakAuth(
        Request $request,
        TokenStorageInterface $tokenStorage,
    ): JsonResponse {
        $reason = $this->extractReason($request, 'chaos-monkey invalidation');
        $correlationId = $this->correlationId($request);

        $tokenStorage->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        $this->daemon8->send(
            data: [
                'message' => 'authentication session invalidated',
                'code' => 401,
                'reason' => $reason,
                'scenario_role' => 'chaos',
                'correlation_id' => $correlationId,
            ],
            severity: Severity::Warn->value,
            kind: Kind::Custom->value,
            channel: 'scenario.auth',
        );

        return new JsonResponse([
            'broke' => 'auth',
            'status' => 401,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }

    #[Route('/demo/break-js', name: 'demo_break_js', methods: ['POST'])]
    public function breakJs(Request $request): JsonResponse
    {
        $reason = $this->extractReason($request, 'injected broken JS');
        $correlationId = $this->correlationId($request);

        $this->daemon8->send(
            data: [
                'message' => 'frontend JS error injected',
                'reason' => $reason,
                'scenario_role' => 'chaos',
                'snippet' => "throw new Error('chaos injected: {$reason}')",
                'correlation_id' => $correlationId,
            ],
            severity: Severity::Warn->value,
            kind: Kind::Custom->value,
            channel: 'scenario.js',
        );

        return new JsonResponse([
            'broke' => 'js',
            'status' => 200,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }

    #[Route('/demo/break-job', name: 'demo_break_job', methods: ['POST'])]
    public function breakJob(
        Request $request,
        MessageBusInterface $bus,
    ): JsonResponse {
        $reason = $this->extractReason($request, 'bad payload');
        $correlationId = $this->correlationId($request);

        try {
            $bus->dispatch(new ChaosMessage('broken', $reason));
        } catch (HandlerFailedException $exception) {
            /*
             * Sync transport re-raises handler exceptions wrapped in
             * HandlerFailedException. The MessengerSubscriber already
             * observed the failure path; we emit a typed Exception
             * observation here so the fixer agent has a richer signal
             * to act on than the generic worker envelope.
             */
            $first = $exception->getWrappedExceptions()[0] ?? $exception;

            $this->daemon8->send(
                data: [
                    'message' => $first->getMessage(),
                    'trace' => $first->getTraceAsString(),
                    'reason' => $reason,
                    'scenario_role' => 'chaos',
                    'correlation_id' => $correlationId,
                ],
                severity: Severity::Error->value,
                kind: Kind::Exception->value,
                channel: 'scenario.jobs',
            );

            return new JsonResponse([
                'broke' => 'job',
                'status' => 500,
                'reason' => $reason,
                'error' => $first->getMessage(),
                'correlation_id' => $correlationId,
            ]);
        }

        return new JsonResponse([
            'broke' => 'job',
            'status' => 200,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }

    #[Route('/demo/fix-auth', name: 'demo_fix_auth', methods: ['POST'])]
    public function fixAuth(Request $request): JsonResponse
    {
        $reason = $this->extractReason($request, 'token refresh');
        $correlationId = $this->correlationId($request);

        if ($request->hasSession()) {
            $request->getSession()->migrate(true);
        }

        $this->daemon8->send(
            data: [
                'message' => 'authentication session refreshed',
                'reason' => $reason,
                'scenario_role' => 'fixer',
                'correlation_id' => $correlationId,
            ],
            severity: Severity::Info->value,
            kind: Kind::Custom->value,
            channel: 'scenario.auth',
        );

        return new JsonResponse([
            'fixed' => 'auth',
            'status' => 200,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }

    #[Route('/demo/fix-job', name: 'demo_fix_job', methods: ['POST'])]
    public function fixJob(
        Request $request,
        MessageBusInterface $bus,
    ): JsonResponse {
        $reason = $this->extractReason($request, 'retry with corrected payload');
        $correlationId = $this->correlationId($request);

        $bus->dispatch(new ChaosMessage('clean', $reason));

        $this->daemon8->send(
            data: [
                'message' => 'background job retried successfully',
                'reason' => $reason,
                'scenario_role' => 'fixer',
                'correlation_id' => $correlationId,
            ],
            severity: Severity::Info->value,
            kind: Kind::Custom->value,
            channel: 'scenario.jobs',
        );

        return new JsonResponse([
            'fixed' => 'job',
            'status' => 200,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * JSON body wins, then form input, then the default — mirrors the way
     * the scenario runner posts (application/json) and a curl user hitting
     * the endpoint with -d reason=... both work.
     */
    private function extractReason(Request $request, string $default): string
    {
        $json = $this->decodeJson($request);
        if (isset($json['reason']) && is_string($json['reason']) && $json['reason'] !== '') {
            return $json['reason'];
        }
        $form = (string) $request->request->get('reason', '');
        return $form !== '' ? $form : $default;
    }

    /** @return array<string, mixed> */
    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Stable per-request id stamped onto every observation emitted from
     * this chaos surface. Keeps fixer → chaos attribution unambiguous in
     * the live panel and feeds the Daemon8 profiler correlation.
     */
    private function correlationId(Request $request): string
    {
        $existing = $request->attributes->get('_daemon8_correlation_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $generated = 'chaos-' . bin2hex(random_bytes(6));
        $request->attributes->set('_daemon8_correlation_id', $generated);
        return $generated;
    }
}
