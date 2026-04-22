<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke test for the daemon8:tour command. Idempotent, no side effects,
 * so it belongs in the fast lane — no daemon binary required.
 *
 * Assertions cover the four sections (routes, console, chaos, MCP) so a
 * future refactor can't quietly drop one.
 */
final class TourCommandTest extends KernelTestCase
{
    #[Test]
    public function tour_prints_route_and_subscriber_inventory(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $command = $application->find('daemon8:tour');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();

        /*
         * Section headers prove the IA is intact. If any section title
         * changes, the welcome page and the tour drift apart — fail
         * loudly here to catch it.
         */
        self::assertStringContainsString('Web routes', $output);
        self::assertStringContainsString('Console commands', $output);
        self::assertStringContainsString('Chaos & Fixer scenarios', $output);
        self::assertStringContainsString('MCP tools', $output);

        /*
         * Route inventory — spot-check two distinct subscribers so a
         * rename in DemoController::routeInventory() is caught.
         */
        self::assertStringContainsString('/demo/log', $output);
        self::assertStringContainsString('RequestSubscriber', $output);
        self::assertStringContainsString('Daemon8QueryMiddleware', $output);

        /* Chaos surface enumeration. */
        self::assertStringContainsString('/demo/break-auth', $output);
        self::assertStringContainsString('/scenario/start', $output);

        /* MCP tools. */
        self::assertStringContainsString('debug_observe', $output);
        self::assertStringContainsString('debug_ingest', $output);

        self::assertSame(0, $tester->getStatusCode());
    }
}
