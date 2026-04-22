<?php

declare(strict_types=1);

namespace App\Controller;

use Daemon8\Config;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side proxy for the daemon's /api/stream SSE endpoint.
 *
 * The welcome page's live panel cannot EventSource directly to
 * http://127.0.0.1:9077 — browsers enforce same-origin for some network
 * paths and localhost-to-localhost CORS is a long-standing footgun. The
 * proxy relays byte-for-byte, forwarding Last-Event-ID so reconnects
 * resume cleanly, and sets X-Accel-Buffering: no so Nginx/Caddy in front
 * don't buffer the stream.
 */
final class StreamProxyController extends AbstractController
{
    #[Route('/api/observations-stream', name: 'observations_stream', methods: ['GET'])]
    public function __invoke(
        Request $request,
        Config $config,
        HttpClientInterface $client,
    ): Response {
        $url = rtrim($config->baseUrl, '/') . '/api/stream?origins=' . urlencode('app:' . $config->appName);

        $headers = ['Accept' => 'text/event-stream'];
        $lastEventId = $request->headers->get('Last-Event-ID');
        if ($lastEventId !== null && $lastEventId !== '') {
            $headers['Last-Event-ID'] = $lastEventId;
        }

        /*
         * Long-lived stream. timeout=infinity would be ideal; Symfony's
         * HttpClient uses max_duration=0 for "no cap" but its idle timeout
         * still ticks. A generous 30-minute idle and 0-duration keeps the
         * stream alive through normal demo sessions. Browsers auto-
         * reconnect an EventSource on close, so even a timeout at 30m just
         * reopens the proxy.
         */
        $upstream = $client->request('GET', $url, [
            'headers' => $headers,
            'timeout' => 1800.0,
            'max_duration' => 0,
            'buffer' => false,
        ]);

        $response = new StreamedResponse(function () use ($upstream, $client): void {
            try {
                foreach ($client->stream($upstream, 30.0) as $chunk) {
                    if ($chunk->isTimeout()) {
                        continue;
                    }
                    if ($chunk->isLast()) {
                        break;
                    }
                    $bytes = $chunk->getContent();
                    if ($bytes === '') {
                        continue;
                    }
                    echo $bytes;
                    @ob_flush();
                    flush();
                }
            } catch (\Throwable) {
                /*
                 * Daemon unreachable or lost connection — emit one SSE
                 * comment so the browser EventSource notices and closes,
                 * triggering its own reconnect backoff.
                 */
                echo ": daemon-stream-closed\n\n";
                @ob_flush();
                flush();
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
