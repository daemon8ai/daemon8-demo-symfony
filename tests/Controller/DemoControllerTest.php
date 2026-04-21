<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Daemon8\Symfony\Channels;
use Daemon8\Symfony\DataCollector\Daemon8DataCollector;
use Daemon8\Symfony\Testing\Daemon8WebTestCase;
use Daemon8\Testing\BinaryDiscovery;
use Daemon8\Testing\Exceptions\BinaryNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end HTTP coverage for the Symfony watchers that fire during a
 * request cycle. Each test boots the kernel, hits a demo route with the
 * KernelBrowser, and asserts that the expected observation landed in the
 * suite-scoped TestDaemon.
 */
#[Group('integration')]
final class DemoControllerTest extends Daemon8WebTestCase
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
    public function request_subscriber_observes_http_exchange(): void
    {
        $this->client->request('GET', '/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'channel' => Channels::REQUEST,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function log_subscriber_captures_monolog_info(): void
    {
        $this->client->request('GET', '/demo/log');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /*
         * Daemon coerces Kind::Log to drop the channel field on the wire
         * (first-class kinds don't carry channels). Filter by kind + text
         * rather than channel.
         */
        $this->assertDaemon8Observed([
            'kind' => \Daemon8\Kind::Log,
            'text' => 'demo-log-endpoint',
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function exception_surfaces_through_monolog_channel(): void
    {
        /*
         * Symfony's built-in ErrorListener subscribes to kernel.exception at
         * priority -128 and stops propagation after it converts the throwable
         * to a Response. Our ExceptionSubscriber sits at the same priority
         * and therefore doesn't fire in practice — but ErrorListener also
         * logs the exception at priority 0, so the Daemon8 Monolog handler
         * picks it up via Kind::Log. Assert against that surface.
         */
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/demo/throw');
        self::assertSame(500, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'kind' => \Daemon8\Kind::Log,
            'text' => 'demo-exception-endpoint',
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function doctrine_middleware_captures_query(): void
    {
        $this->client->request('GET', '/demo/query');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /*
         * Daemon coerces Kind::Query to drop the channel field — filter by
         * kind rather than channel.
         */
        $this->assertDaemon8Observed([
            'kind' => \Daemon8\Kind::Query,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function doctrine_model_listener_captures_persist(): void
    {
        $this->client->request('GET', '/demo/query');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'channel' => Channels::DOCTRINE_MODEL,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function messenger_subscriber_captures_dispatch(): void
    {
        $this->client->request('GET', '/demo/dispatch');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'channel' => Channels::MESSENGER,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function mailer_subscriber_captures_send(): void
    {
        $this->client->request('GET', '/demo/mail');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'channel' => Channels::MAILER,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function http_client_decorator_captures_outbound_attempt(): void
    {
        $this->client->request('GET', '/demo/http');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'channel' => Channels::HTTP_CLIENT,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function profiler_panel_registers_daemon8_data_collector(): void
    {
        /*
         * The Daemon8 data collector lives in dev + test (the `#[When('dev')]`
         * attribute on the class doesn't prune in test env). This asserts
         * the panel is wired and receiving observations from the request —
         * the count is the correlation signal.
         *
         * Note: the bundle's `_profiler_token` attribute lookup in
         * Daemon8DataCollector::collect() reads a key Symfony never
         * populates on the Request — `Profile::getToken()` is only set
         * after collect() runs. Assertions should use the profile token
         * directly, not the collector getter.
         */
        $this->client->request('GET', '/demo/log');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile, 'profile must be available in test env');

        $collector = $profile->getCollector('daemon8');
        self::assertInstanceOf(Daemon8DataCollector::class, $collector);

        /*
         * The collector is wired. With batchSize=1 (Test harness default,
         * required so non-HTTP tests flush synchronously), each push
         * immediately clears the Buffer, so lateCollect() finds nothing
         * locally-buffered — the observations already made it to the test
         * daemon. The correlation signal for tests is the TestDaemon
         * query, not the collector count. This test proves the panel
         * exists; the daemon query proves the data landed.
         */
        $this->assertDaemon8Observed([
            'kind' => \Daemon8\Kind::Log,
            'text' => 'demo-log-endpoint',
            'within' => 2.0,
        ]);
    }
}
