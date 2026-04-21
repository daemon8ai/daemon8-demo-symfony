<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\DemoItem;
use App\Message\DemoMessage;
use Daemon8\Symfony\Channels;
use Daemon8\Symfony\Testing\Daemon8TestCase;
use Daemon8\Testing\BinaryDiscovery;
use Daemon8\Testing\Exceptions\BinaryNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Subscribers that fire outside the HTTP cycle — console, messenger from a
 * service-locator context, monolog from direct logger calls, doctrine
 * migration listener. Each test boots the kernel, exercises the subject
 * directly, and asserts against the suite-scoped TestDaemon.
 *
 * HTTP-cycle subscribers (request, exception, http_client) are covered in
 * Controller\DemoControllerTest against a KernelBrowser.
 */
#[Group('integration')]
final class SubscriberCoverageTest extends Daemon8TestCase
{
    protected function setUp(): void
    {
        try {
            BinaryDiscovery::discover();
        } catch (BinaryNotFoundException) {
            self::markTestSkipped('daemon8 binary not installed');
        }

        parent::setUp();
    }

    #[Test]
    public function command_subscriber_observes_console_run(): void
    {
        /*
         * ApplicationTester dispatches ConsoleEvents::COMMAND / TERMINATE.
         * CommandTester::execute bypasses the application loop and would
         * silently skip both — the CommandSubscriber would never fire.
         */
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'demo:run']);

        $this->assertDaemon8Observed([
            'channel' => Channels::COMMAND,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function monolog_handler_captures_info_log(): void
    {
        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get(LoggerInterface::class);
        $logger->info('integration-monolog-log');

        /*
         * Daemon coerces Kind::Log to drop the channel field — filter by
         * kind + text rather than channel.
         */
        $this->assertDaemon8Observed([
            'kind' => \Daemon8\Kind::Log,
            'text' => 'integration-monolog-log',
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function messenger_subscriber_observes_dispatch_from_service(): void
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new DemoMessage('integration-messenger'));

        $this->assertDaemon8Observed([
            'channel' => Channels::MESSENGER,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function doctrine_model_listener_observes_direct_persist(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist(new DemoItem('integration-model', 7));
        $em->flush();

        $this->assertDaemon8Observed([
            'channel' => Channels::DOCTRINE_MODEL,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function doctrine_query_middleware_observes_direct_sql(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->getConnection()->executeQuery('SELECT 1 AS marker_integration_query');

        /*
         * Daemon coerces Kind::Query to drop the channel field — filter by
         * kind + SQL substring rather than channel.
         */
        $this->assertDaemon8Observed([
            'kind' => \Daemon8\Kind::Query,
            'text' => 'marker_integration_query',
            'within' => 2.0,
        ]);
    }
}
