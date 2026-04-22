<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DemoItem;
use App\Message\DemoMessage;
use Daemon8\Config;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demo routes and the welcome-page renderer. Each /demo/* endpoint fires a
 * specific subscriber surface so the live panel and the test suite can
 * assert against distinct subscribers without cross-contamination.
 *
 * The static routeInventory() + consoleInventory() methods are the single
 * source of truth for route metadata — both the welcome template and the
 * daemon8:tour console command read from them.
 */
final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_index', methods: ['GET'])]
    public function index(Config $daemon8Config): Response
    {
        return $this->render('demo/index.html.twig', [
            'daemonBaseUrl' => rtrim($daemon8Config->baseUrl, '/'),
            'routes' => self::routeInventory(),
            'consoleCommands' => self::consoleInventory(),
            'scenarioKeyConfigured' => (getenv('ANTHROPIC_API_KEY') ?: '') !== '',
        ]);
    }

    #[Route('/demo/log', name: 'demo_log', methods: ['GET'])]
    public function log(LoggerInterface $logger): JsonResponse
    {
        $logger->info('demo-log-endpoint');
        $logger->warning('demo-warning-endpoint', ['source' => 'demo']);

        return new JsonResponse(['logged' => true]);
    }

    #[Route('/demo/throw', name: 'demo_throw', methods: ['GET'])]
    public function throwException(): JsonResponse
    {
        throw new \RuntimeException('demo-exception-endpoint');
    }

    #[Route('/demo/query', name: 'demo_query', methods: ['GET'])]
    public function query(EntityManagerInterface $em): JsonResponse
    {
        $item = new DemoItem('query-sample-' . bin2hex(random_bytes(3)), 42);
        $em->persist($item);
        $em->flush();

        $found = $em->getRepository(DemoItem::class)->findAll();

        return new JsonResponse(['count' => count($found)]);
    }

    #[Route('/demo/dispatch', name: 'demo_dispatch', methods: ['GET'])]
    public function dispatch(MessageBusInterface $bus): JsonResponse
    {
        $bus->dispatch(new DemoMessage('demo-payload'));

        return new JsonResponse(['dispatched' => true]);
    }

    #[Route('/demo/http', name: 'demo_http', methods: ['GET'])]
    public function http(HttpClientInterface $client): JsonResponse
    {
        /*
         * The HttpClient decorator observes outbound requests. We hit a
         * guaranteed-reachable URL (localhost to the daemon's observe
         * endpoint) so the test can rely on the call completing. If the
         * daemon isn't running, we still want a 200 so the test suite
         * can assert on the decorator's observation alone.
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

    /**
     * Structured route inventory used by the welcome page and the
     * daemon8:tour command. One source of truth for path, purpose,
     * subscriber names, and captured fields.
     *
     * @return list<array{
     *     path: string,
     *     method: string,
     *     title: string,
     *     blurb: string,
     *     subscribers: list<string>,
     *     fields: string
     * }>
     */
    public static function routeInventory(): array
    {
        return [
            [
                'path' => '/demo/log',
                'method' => 'GET',
                'title' => 'Logger info + warning',
                'blurb' => 'Writes one info + one warning through Monolog. Daemon8Handler forwards both; RequestSubscriber frames the exchange.',
                'subscribers' => ['RequestSubscriber', 'Daemon8Handler'],
                'fields' => 'method, uri, status, duration_ms; log message, level, context.',
            ],
            [
                'path' => '/demo/query',
                'method' => 'GET',
                'title' => 'Doctrine persist + fetchAll',
                'blurb' => 'Creates a DemoItem and lists the table. Daemon8QueryMiddleware captures the SQL, ModelListener captures the entity event.',
                'subscribers' => ['RequestSubscriber', 'Daemon8QueryMiddleware', 'ModelListener'],
                'fields' => 'sql, params, duration_ms; entity class, event (postPersist/postUpdate/postRemove).',
            ],
            [
                'path' => '/demo/throw',
                'method' => 'GET',
                'title' => 'Uncaught RuntimeException',
                'blurb' => 'Throws so Symfony\'s ErrorListener logs it. The Daemon8 Monolog handler picks the stringified exception up as Kind::Log.',
                'subscribers' => ['RequestSubscriber', 'Daemon8Handler'],
                'fields' => 'message, file, line; rendered as a Kind::Log observation because ErrorListener stops propagation before ExceptionSubscriber runs.',
            ],
            [
                'path' => '/demo/dispatch',
                'method' => 'GET',
                'title' => 'Messenger dispatch (sync transport)',
                'blurb' => 'Dispatches DemoMessage. MessengerSubscriber captures the SendMessageToTransportsEvent + WorkerMessage* pair.',
                'subscribers' => ['RequestSubscriber', 'MessengerSubscriber'],
                'fields' => 'message class, transport, bus, handled stamp, retry count.',
            ],
            [
                'path' => '/demo/http',
                'method' => 'GET',
                'title' => 'Outbound HttpClient request',
                'blurb' => 'Calls the daemon\'s own /api/observe endpoint through Symfony\'s HttpClient. Daemon8HttpClient decorator records method, url, status, duration.',
                'subscribers' => ['RequestSubscriber', 'Daemon8HttpClient'],
                'fields' => 'method, url, status, duration_ms, response headers, final URL after redirects.',
            ],
            [
                'path' => '/demo/mail',
                'method' => 'GET',
                'title' => 'Symfony Mailer send',
                'blurb' => 'Sends a plain-text Email through the null:// transport. MailerSubscriber captures the envelope.',
                'subscribers' => ['RequestSubscriber', 'MailerSubscriber'],
                'fields' => 'transport, from, to, cc/bcc, subject, body size, redacted headers.',
            ],
        ];
    }

    /**
     * Console commands worth highlighting on the welcome page and in the
     * tour. Each command exercises a distinct subscriber surface.
     *
     * @return list<array{command: string, blurb: string, subscribers: list<string>}>
     */
    public static function consoleInventory(): array
    {
        return [
            [
                'command' => 'php bin/console demo:run',
                'blurb' => 'Emits one info log inside a console command. CommandSubscriber observes ConsoleEvents::COMMAND + TERMINATE.',
                'subscribers' => ['CommandSubscriber', 'Daemon8Handler'],
            ],
            [
                'command' => 'php bin/console doctrine:migrations:migrate --no-interaction',
                'blurb' => 'Runs every pending migration. MigrationListener observes each step through the doctrine/migrations EventManager.',
                'subscribers' => ['MigrationListener'],
            ],
            [
                'command' => 'php bin/console messenger:consume async -vv',
                'blurb' => 'Boots the async transport worker. MessengerSubscriber observes every envelope flowing through the worker loop.',
                'subscribers' => ['MessengerSubscriber'],
            ],
            [
                'command' => 'php bin/console daemon8:tour',
                'blurb' => 'Prints the same route + subscriber inventory in the terminal, with cross-refs back to the welcome page.',
                'subscribers' => [],
            ],
        ];
    }
}
