<?php

declare(strict_types=1);

namespace App\Controller;

use Daemon8\Config;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Same-origin health probe. The browser can't reliably CORS its way to
 * http://127.0.0.1:9077/health (cross-origin + localhost quirks), so we
 * relay the request server-side. Returns the daemon's own JSON when
 * reachable, or a shaped offline payload the live panel can key off of.
 */
final class HealthProxyController extends AbstractController
{
    #[Route('/health-proxy', name: 'health_proxy', methods: ['GET'])]
    public function __invoke(Config $config, HttpClientInterface $client): JsonResponse
    {
        $url = rtrim($config->baseUrl, '/') . '/health';

        try {
            $response = $client->request('GET', $url, [
                'timeout' => 1.0,
                'max_duration' => 2.0,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                return new JsonResponse(
                    ['status' => 'offline', 'baseUrl' => $config->baseUrl, 'httpStatus' => $status],
                    502,
                );
            }
            $body = json_decode($response->getContent(throw: false), true);
            if (! is_array($body)) {
                return new JsonResponse(['status' => 'offline', 'baseUrl' => $config->baseUrl], 502);
            }
            return new JsonResponse($body);
        } catch (\Throwable $exception) {
            return new JsonResponse(
                ['status' => 'offline', 'baseUrl' => $config->baseUrl, 'error' => $exception->getMessage()],
                502,
            );
        }
    }
}
