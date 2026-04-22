<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use Daemon8\Kind;
use Daemon8\Severity;
use Daemon8\Symfony\Testing\Daemon8WebTestCase;
use Daemon8\Testing\BinaryDiscovery;
use Daemon8\Testing\Exceptions\BinaryNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for the chaos / fixer HTTP surface. Every endpoint is exercised
 * with a POST body the scenario runner would send, the response shape is
 * asserted for the frontend contract, and the matching daemon8 observation
 * is asserted against the suite-scoped TestDaemon.
 *
 * The correlation_id that the chaos controller stamps onto each response
 * and each observation is the load-bearing field for the live panel ↔
 * WebProfiler deep-link. Tests assert it lands in both places.
 */
#[Group('integration')]
final class ChaosControllerTest extends Daemon8WebTestCase
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
    public function break_auth_returns_401_shape_and_emits_warn_observation(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/demo/break-auth',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'unit-test-invalidation'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('auth', $body['broke']);
        self::assertSame(401, $body['status']);
        self::assertSame('unit-test-invalidation', $body['reason']);
        self::assertNotEmpty($body['correlation_id']);

        $this->assertDaemon8Observed([
            'kind' => Kind::Custom,
            'channel' => 'scenario.auth',
            'text' => 'unit-test-invalidation',
            'severity' => Severity::Warn,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function break_js_emits_warn_observation_with_snippet(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/demo/break-js',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'unit-test-js'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'kind' => Kind::Custom,
            'channel' => 'scenario.js',
            'text' => 'unit-test-js',
            'severity' => Severity::Warn,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function break_job_dispatches_failing_message_and_emits_exception(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/demo/break-job',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'unit-test-job'], JSON_THROW_ON_ERROR),
        );

        /*
         * HandlerFailedException is caught inside the controller so the
         * HTTP response is a clean 200 with a 500 status field in the
         * body — mirrors the way the Laravel demo handles it so the
         * fixer agent has a readable response envelope to parse.
         */
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('job', $body['broke']);
        self::assertSame(500, $body['status']);
        self::assertNotEmpty($body['correlation_id']);

        /*
         * Two observations should land: one from MessengerSubscriber (the
         * failure path) and the typed Exception observation the chaos
         * controller emits directly. Assert on the directly-emitted one.
         *
         * Kind::Exception is a first-class kind — the daemon drops the
         * channel field on the wire. Filter by kind + text rather than
         * channel, same as the Kind::Log / Kind::Query assertions elsewhere.
         */
        $this->assertDaemon8Observed([
            'kind' => Kind::Exception,
            'text' => 'chaos job broken payload',
            'severity' => Severity::Error,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function fix_auth_emits_info_observation(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/demo/fix-auth',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'unit-test-refresh'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'kind' => Kind::Custom,
            'channel' => 'scenario.auth',
            'text' => 'unit-test-refresh',
            'severity' => Severity::Info,
            'within' => 2.0,
        ]);
    }

    #[Test]
    public function fix_job_dispatches_clean_message_and_emits_info(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/demo/fix-job',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reason' => 'unit-test-retry'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->assertDaemon8Observed([
            'kind' => Kind::Custom,
            'channel' => 'scenario.jobs',
            'text' => 'unit-test-retry',
            'severity' => Severity::Info,
            'within' => 2.0,
        ]);
    }
}
