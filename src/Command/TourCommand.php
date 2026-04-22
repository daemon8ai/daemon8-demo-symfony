<?php

declare(strict_types=1);

namespace App\Command;

use App\Controller\DemoController;
use Daemon8\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks a newcomer through the Symfony demo surface: web routes, console
 * commands, chaos scenarios, MCP tools. Pure print — no side effects, safe
 * to run from a CI smoke stage.
 *
 * Mirrors the welcome page's information architecture 1:1 so the terminal
 * and browser tell the same story.
 */
#[AsCommand(
    name: 'daemon8:tour',
    description: 'Print a guided tour of the demo surface — routes, console commands, chaos scenarios, MCP tools.',
)]
final class TourCommand extends Command
{
    public function __construct(
        private readonly Config $daemon8Config,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Daemon8 — Symfony demo tour');
        $io->text([
            sprintf('Endpoint:  <info>%s</info>', $this->daemon8Config->baseUrl),
            sprintf('App:       <info>%s</info>', $this->daemon8Config->appName),
        ]);
        $io->newLine();

        $io->section('Web routes');
        $routeRows = array_map(static fn (array $r): array => [
            sprintf('%s %s', $r['method'], $r['path']),
            $r['title'],
            implode(', ', $r['subscribers']),
        ], DemoController::routeInventory());
        $io->table(['Route', 'Purpose', 'Subscribers'], $routeRows);

        $io->section('Console commands');
        foreach (DemoController::consoleInventory() as $cmd) {
            $io->writeln(sprintf('<options=bold>%s</>', $cmd['command']));
            $io->writeln('  ' . $cmd['blurb']);
            if ($cmd['subscribers'] !== []) {
                $io->writeln('  <comment>subscribers:</comment> ' . implode(', ', $cmd['subscribers']));
            }
            $io->newLine();
        }

        $io->section('Chaos & Fixer scenarios');
        $io->text([
            'An opt-in BYOK loop where one LLM breaks the app while a second watches the',
            'daemon stream and fixes it. Endpoints ship as real Symfony controllers:',
            '',
            '  POST /demo/break-auth    (chaos) TokenStorage::setToken(null) + Session::invalidate + 401 observation',
            '  POST /demo/break-job     (chaos) dispatches ChaosMessage(broken) which throws in the handler',
            '  POST /demo/break-js      (chaos) emits a js-error marker observation',
            '  POST /demo/fix-auth      (fixer) Session::migrate(true) + info observation',
            '  POST /demo/fix-job       (fixer) dispatches ChaosMessage(clean) + info observation',
            '  POST /scenario/start     orchestrator SSE endpoint — body carries provider, apiKey, scenario',
        ]);

        $io->section('MCP tools');
        $io->table(
            ['Tool', 'Purpose'],
            [
                ['debug_observe',     'Query stored observations with filters (kind, severity, origin, text).'],
                ['debug_subscribe',   'Live-subscribe to the observation stream, optionally filtered.'],
                ['debug_checkpoint',  'Mark a point in time; later queries reference it for incremental reads.'],
                ['debug_summary',     'Counts, error rates, active connections.'],
                ['debug_act',         'Browser control: eval_js, screenshot, inject_css, navigate.'],
                ['debug_connect',     'Attach the daemon to a Chrome endpoint.'],
                ['debug_connections', 'Inspect currently-attached data sources.'],
                ['debug_ingest',      'Emit an observation from the agent side (for coordination signals).'],
            ],
        );

        $io->section('Try the web demo');
        $io->text([
            '  composer dev          # boots symfony serve + messenger worker + daemon8 tail',
            '  open http://127.0.0.1:8000/',
        ]);
        $io->newLine();
        $io->success('Open http://127.0.0.1:8000/ for the full interactive demo.');

        return Command::SUCCESS;
    }
}
