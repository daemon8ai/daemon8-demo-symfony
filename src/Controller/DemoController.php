<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DemoItem;
use App\Message\DemoMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_index')]
    public function index(LoggerInterface $logger): Response
    {
        $logger->info('demo-index-hit');

        return $this->render('demo/index.html.twig', [
            'message' => 'Daemon8 Symfony Demo',
        ]);
    }

    #[Route('/demo/log', name: 'demo_log')]
    public function log(LoggerInterface $logger): JsonResponse
    {
        $logger->info('demo-log-endpoint');
        $logger->warning('demo-warning-endpoint', ['source' => 'demo']);

        return new JsonResponse(['logged' => true]);
    }

    #[Route('/demo/throw', name: 'demo_throw')]
    public function throwException(): JsonResponse
    {
        throw new \RuntimeException('demo-exception-endpoint');
    }

    #[Route('/demo/query', name: 'demo_query')]
    public function query(EntityManagerInterface $em): JsonResponse
    {
        $item = new DemoItem('query-sample-' . bin2hex(random_bytes(3)), 42);
        $em->persist($item);
        $em->flush();

        $found = $em->getRepository(DemoItem::class)->findAll();

        return new JsonResponse(['count' => count($found)]);
    }

    #[Route('/demo/dispatch', name: 'demo_dispatch')]
    public function dispatch(MessageBusInterface $bus): JsonResponse
    {
        $bus->dispatch(new DemoMessage('demo-payload'));

        return new JsonResponse(['dispatched' => true]);
    }

    #[Route('/demo/http', name: 'demo_http')]
    public function http(HttpClientInterface $client): JsonResponse
    {
        /*
         * The HttpClient decorator observes outbound requests. We hit a
         * guaranteed-reachable URL (localhost to the daemon's health
         * endpoint) so the test can rely on the call completing.
         */
        try {
            $client->request('GET', 'http://127.0.0.1:9077/api/observe?limit=1', ['timeout' => 1]);
        } catch (\Throwable) {
            /*
             * Swallow — we only care that the decorator observed the attempt.
             * The daemon may or may not be reachable in every environment.
             */
        }

        return new JsonResponse(['attempted' => true]);
    }
}
