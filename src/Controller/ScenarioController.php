<?php

declare(strict_types=1);

namespace App\Controller;

use App\Byok\AnthropicProvider;
use App\Byok\GeminiProvider;
use App\Byok\LlmProvider;
use App\Byok\OpenAIProvider;
use App\Byok\ScenarioRunner;
use Daemon8\Daemon8Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP surface for the BYOK chaos/fixer scenario.
 *
 * POST /scenario/start opens the SSE stream directly; the POST body
 * carries provider + apiKey + scenario, and the response body IS the
 * event stream. Sidesteps background-job orchestration — the browser
 * reads the event stream as it arrives and pipes each frame into the
 * scenario panels.
 *
 * The API key never crosses Symfony's log surface: the scenario runner
 * hands it directly to curl via x-api-key header, and the request
 * doesn't populate $request->query or $request->request (it arrives as
 * a JSON body which we decode once and throw away).
 */
final class ScenarioController extends AbstractController
{
    private const PROVIDER_ENV_MAP = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ];

    #[Route('/scenario/start', name: 'scenario_start', methods: ['POST'])]
    public function start(
        Request $request,
        Daemon8Client $daemon8,
        HttpClientInterface $http,
    ): Response {
        $body = $this->decodeBody($request);
        $provider = (string) ($body['provider'] ?? '');
        $scenario = (string) ($body['scenario'] ?? 'auth-recovery');

        if ($provider === '') {
            return new JsonResponse(
                ['error' => 'provider is required'],
                400,
            );
        }

        $envVar = self::PROVIDER_ENV_MAP[strtolower($provider)] ?? null;
        if ($envVar === null) {
            return new JsonResponse(
                ['error' => "unknown provider '{$provider}'"],
                400,
            );
        }

        $apiKey = (string) ($_ENV[$envVar] ?? getenv($envVar) ?: '');
        if ($apiKey === '') {
            return new JsonResponse(
                ['error' => "No API key configured for '{$provider}'. Set {$envVar} in your .env file."],
                400,
            );
        }

        $llm = $this->resolveProvider($provider, $apiKey);
        if ($llm === null) {
            return new JsonResponse(
                ['error' => "unknown provider '{$provider}'"],
                400,
            );
        }

        $selfBase = $this->selfBaseUrl($request);
        $runner = new ScenarioRunner($daemon8, $http, $llm, $scenario, $selfBase);

        $response = new StreamedResponse(function () use ($runner): void {
            /*
             * Symfony's StreamedResponse runs the callback inside the
             * normal response lifecycle, so all output buffers must be
             * flushed+closed before we start yielding SSE frames or the
             * web server holds chunks until the handler returns.
             */
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $runner->run(function (array $event): void {
                echo 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n";
                @ob_flush();
                flush();
            });
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    private function resolveProvider(string $slug, string $apiKey): ?LlmProvider
    {
        return match (strtolower($slug)) {
            'anthropic' => new AnthropicProvider($apiKey),
            'openai' => new OpenAIProvider($apiKey),
            'gemini' => new GeminiProvider($apiKey),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function decodeBody(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function selfBaseUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
