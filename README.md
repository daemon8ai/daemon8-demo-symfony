<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="brand/wordmark-dark.svg">
    <img src="brand/wordmark-light.svg" alt="Daemon8" width="360">
  </picture>
</p>

<p align="center">
  <strong>Runnable Symfony 7 reference for <code>daemon8/symfony</code>.</strong><br>
  Every subscriber, middleware, and decorator exercised end-to-end against a real daemon,
  plus a BYOK chaos/fixer loop showing two LLMs coordinating through the observation stream.
</p>

<p align="center">
  <a href="https://daemon8.ai">Website</a> ·
  <a href="https://github.com/daemon8ai/daemon8">Daemon</a> ·
  <a href="https://github.com/daemon8ai/daemon8-symfony">SDK</a> ·
  <a href="mailto:mail@daemon8.ai">Contact</a>
</p>

<p align="center">
  <em>Free and open source. No tiers, no license keys, no phone-home.</em>
</p>

# daemon8-demo-symfony

A fully-wired Symfony 7 application demonstrating every `daemon8/symfony` subscriber, middleware, decorator, and listener, plus the `Daemon8TestCase` / `Daemon8WebTestCase` testing primitives and the BYOK chaos/fixer scenario. Clone, install, boot, hit a route — observations appear in real time in the welcome page's live panel, in your `daemon8 tail` stream, and over MCP.

This is the reference you point at when someone asks "how do I actually use Daemon8 with Symfony?"

## Getting Started

### 1. Install Daemon8

```bash
cargo install daemon8 --features dev
daemon8 --help
curl http://127.0.0.1:9077/health
```

> **Required:** Daemon8 must be installed and running before this demo can emit observations.

### 2. Install the demo

Requires PHP 8.4+, Composer, Node 20+, and the Symfony CLI.

```bash
git clone https://github.com/daemon8ai/daemon8-demo-symfony
cd daemon8-demo-symfony
composer install
cp .env.example .env
mkdir -p var && touch var/data.db
php bin/console doctrine:migrations:migrate --no-interaction
```

Optional: set `LLM_PROVIDER_KEY` in `.env` to enable Chaos & Fixer.

### 3. Run the demo

```bash
composer dev
```

This starts `symfony serve`, `messenger:consume async`, and `daemon8 tail`.

### 4. Open the welcome page

```bash
open http://127.0.0.1:8000/
```

From the welcome page:
- Click **Try it** on any route card — the observation lights up in the right-side live panel within milliseconds.
- Switch to **Chaos & Fixer** after setting `LLM_PROVIDER_KEY` to watch two LLMs coordinate a break/repair loop through daemon8's stream.
- Switch to **Console** for copyable commands that exercise the remaining subscribers (`demo:run`, `doctrine:migrations:migrate`, `messenger:consume`, `daemon8:tour`).
- Switch to **Profiler** to see how every observation emitted during a request is stamped with both a `correlation_id` and a `profiler_token` for WebProfiler deep-linking.

## Command-line tour

```bash
php bin/console daemon8:tour
```

Prints the same route and subscriber inventory in the terminal, plus a footer pointing back at the web welcome.

## Demo routes

Every route exercises one or more subscribers so you can verify each one end-to-end.

| Route                | Fires                                                          |
|----------------------|----------------------------------------------------------------|
| `GET /demo/log`      | RequestSubscriber, Daemon8Handler                              |
| `GET /demo/query`    | RequestSubscriber, Daemon8QueryMiddleware, ModelListener       |
| `GET /demo/throw`    | RequestSubscriber, Daemon8Handler (via ErrorListener)          |
| `GET /demo/dispatch` | RequestSubscriber, MessengerSubscriber                         |
| `GET /demo/http`     | RequestSubscriber, Daemon8HttpClient decorator                 |
| `GET /demo/mail`     | RequestSubscriber, MailerSubscriber                            |

CommandSubscriber and MigrationListener fire via console commands — see the Console tab on the welcome page or the `daemon8:tour` output.

## Chaos & Fixer (BYOK)

The welcome page's **Chaos & Fixer** tab drives a scripted loop:

1. A chaos LLM picks one of the demo's failure-injection endpoints (`/demo/break-auth`, `/demo/break-job`, `/demo/break-js`) and invokes it.
2. The corresponding Symfony subscriber captures the failure and emits a real observation to the daemon.
3. A fixer LLM subscribes to the observation stream, reads the warning, and invokes the matching repair endpoint (`/demo/fix-auth`, `/demo/fix-job`).
4. A second observation lands confirming the fix, and the scenario ends.

`LLM_PROVIDER_KEY` is read server-side from `.env` and proxied through this Symfony process. Hard caps: 60-second overall timeout, 10 tool calls per role.

Anthropic is the working provider today; OpenAI and Gemini are stubs that surface a clean "coming soon" message.

## Profiler integration

Symfony's WebProfiler and Daemon8 are complementary. The bundle registers a `Daemon8DataCollector` that powers a profiler panel, plus a `ProfilerCorrelatingBuffer` that stamps every observation emitted during a request with both a `correlation_id` (daemon-wide, framework-agnostic) and the `profiler_token` (Symfony-only, for deep-linking).

- Click an observation in the live panel → the panel embeds the profiler token → click it to jump to `/_profiler/{token}`.
- Open `/_profiler/latest` directly and the **Daemon8** panel lists every observation from the most recent request.
- Sensitive fields redact through VarCloner casters before they reach the HTML dumper.

## Configuration

The full config lives at `config/packages/daemon8.yaml`. Every subscriber is toggleable:

```yaml
daemon8:
    enabled: true
    app: daemon8-demo-symfony
    transport:
        url: '%env(DAEMON8_URL)%'
    watchers:
        Daemon8\Symfony\EventSubscriber\RequestSubscriber: { enabled: true }
        Daemon8\Symfony\Doctrine\Daemon8QueryMiddleware: { enabled: true, all: true }
        # ... one key per subscriber
```

## Testing pattern

Two test cases ship from `daemon8/symfony`:

- `Daemon8\Symfony\Testing\Daemon8WebTestCase` — extends `WebTestCase`, boots a real disposable daemon per test class, rebinds the DI container so observations land in the test daemon. Used in `tests/Controller/DemoControllerTest.php` and `tests/Feature/ChaosControllerTest.php`.
- `Daemon8\Symfony\Testing\Daemon8TestCase` — extends `KernelTestCase` for non-HTTP subscriber coverage. Used in `tests/Integration/SubscriberCoverageTest.php`.

One-line assertion helper with three shapes:

```php
use Daemon8\Kind;
use Daemon8\Severity;
use Daemon8\Symfony\Channels;
use Daemon8\Symfony\Testing\Daemon8WebTestCase;

final class MyFeatureTest extends Daemon8WebTestCase
{
    public function test_something_observable(): void
    {
        $this->client->request('GET', '/demo/log');
        $this->assertResponseIsSuccessful();

        // Channel shorthand for Kind::Custom observations
        $this->assertDaemon8Observed([
            'channel' => Channels::REQUEST,
            'within' => 2.0,
        ]);

        // Kind + text for first-class observations (daemon drops channel)
        $this->assertDaemon8Observed([
            'kind' => Kind::Log,
            'text' => 'demo-log-endpoint',
            'within' => 2.0,
        ]);

        // Full DSL via closure
        $this->assertDaemon8Observed(
            fn ($a) => $a->kind(Kind::Log)->severityAtLeast(Severity::Warn)->atLeastOnce(),
        );
    }
}
```

Run the tests (requires the `daemon8` binary on PATH or at `DAEMON8_BIN`):

```bash
mkdir -p var && touch var/test.db
php bin/console doctrine:migrations:migrate --env=test --no-interaction
DAEMON8_BIN=$(which daemon8) composer test
```

## Wire-format gotchas (important)

The daemon normalises observations on the wire:

1. **First-class kinds drop the channel.** `Kind::Log`, `Kind::Query`, `Kind::HttpExchange`, `Kind::Exception` always return with `channel: null` — even if the SDK sent one. Filter by `kind + text`, not `channel`.
2. **`Kind::Custom` keeps the channel nested.** When the SDK sends `kind=Custom` with a channel, the daemon returns `kind: {type: "custom", channel: "symfony.request"}`. The `Daemon8Assertions` trait's `observationChannel()` helper normalises this for you.
3. **`correlation_id` is the base-SDK contract.** Watchers emit it under `data.correlation_id`; `ProfilerCorrelatingBuffer` also stamps `data.profiler_token` in dev/test. The base SDK's assertion DSL accepts both, and the Symfony-specific profiler deep-link uses the token when present.

## License

MIT. See [`LICENSE`](./LICENSE).
