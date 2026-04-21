<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="brand/wordmark-dark.svg">
    <img src="brand/wordmark-light.svg" alt="Daemon8" width="360">
  </picture>
</p>

<p align="center">
  <strong>Runnable Symfony 7 reference for <code>daemon8/symfony</code>.</strong><br>
  Every subscriber, decorator, and listener exercised end-to-end against a real daemon; <code>Daemon8TestCase</code> and <code>Daemon8WebTestCase</code> covered by canonical tests.
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

---

> **Active development.** This demo is in public alpha. Tracked work for this demo lives as [GitHub Issues](https://github.com/daemon8ai/daemon8-demo-symfony/issues); the broader roadmap is maintained on the [primary daemon8 repo](https://github.com/daemon8ai/daemon8).

> **Requires the Daemon8 daemon.** This demo exercises observations against a real local daemon. Install Daemon8 from [daemon8ai/daemon8](https://github.com/daemon8ai/daemon8) before running the demo scripts — start at the [Quickstart](https://daemon8.ai/docs/quickstart) if you haven't set it up yet.

# daemon8-demo-symfony

A fully-wired Symfony 7 application demonstrating every `daemon8/symfony` subscriber, decorator, and listener, plus the `Daemon8TestCase` / `Daemon8WebTestCase` testing primitives. Clone, install, boot the daemon, hit a route — observations appear in real time in your Daemon8 console or over MCP.

This is the reference you point at when someone asks "how do I actually use Daemon8 with Symfony?"

## Getting Started

```bash
git clone https://github.com/daemon8ai/daemon8-demo-symfony
cd daemon8-demo-symfony
composer install
cp .env.example .env
touch var/data.db
php bin/console doctrine:migrations:migrate --no-interaction
daemon8 serve &                           # in a separate terminal
php -S 127.0.0.1:8000 -t public/ public/index.php
curl -s -u admin:admin http://127.0.0.1:8000/
```

That last `curl` fires `RequestSubscriber` + `Daemon8Handler` observations into the running daemon. Watch them arrive:

```bash
daemon8 observe --follow
```

## Demo routes

Every route exercises one or more subscribers so you can verify each end-to-end. Basic auth: `admin` / `admin`.

| Route             | Fires subscribers                                              |
|-------------------|----------------------------------------------------------------|
| `GET /`           | RequestSubscriber, SecuritySubscriber, Daemon8Handler          |
| `GET /demo/log`   | RequestSubscriber, Daemon8Handler                              |
| `GET /demo/throw` | RequestSubscriber, Daemon8Handler (via ErrorListener logging)  |
| `GET /demo/query` | RequestSubscriber, Daemon8QueryMiddleware, ModelListener       |
| `GET /demo/dispatch` | RequestSubscriber, MessengerSubscriber                      |
| `GET /demo/http`  | RequestSubscriber, Daemon8HttpClient decorator                 |
| `GET /demo/mail`  | RequestSubscriber, MailerSubscriber                            |

Console + migration observations via:

```bash
php bin/console demo:run                                  # CommandSubscriber
php bin/console doctrine:migrations:migrate               # MigrationListener
```

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

- `Daemon8\Symfony\Testing\Daemon8WebTestCase` — extends `WebTestCase`, boots a real disposable daemon per test class, rebinds the DI container so observations land in the test daemon. Used in `tests/Controller/DemoControllerTest.php`.
- `Daemon8\Symfony\Testing\Daemon8TestCase` — extends `KernelTestCase` for non-HTTP subscriber coverage. Used in `tests/Integration/SubscriberCoverageTest.php`.

One-line assertion helper with two shapes:

```php
use Daemon8\Kind;
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
            fn($a) => $a->kind(Kind::Log)->severityAtLeast(Severity::Warn)->atLeastOnce(),
        );
    }
}
```

Run the tests (requires the `daemon8` binary on PATH or at `DAEMON8_BIN`):

```bash
touch var/test.db
php bin/console doctrine:migrations:migrate --env=test --no-interaction
DAEMON8_BIN=$(which daemon8) composer test
```

## Wire-format gotchas (important)

The daemon normalises observations on the wire:

1. **First-class kinds drop the channel.** `Kind::Log`, `Kind::Query`, `Kind::HttpExchange`, `Kind::Exception` always return with `channel: null` — even if the SDK sent one. Filter by `kind + text`, not `channel`.
2. **`Kind::Custom` keeps the channel nested.** When the SDK sends `kind=Custom` with a channel, the daemon returns `kind: {type: "custom", channel: "symfony.request"}`. The `Daemon8Assertions` trait's `observationChannel()` helper normalises this for you.

## Local development note

This project uses a Composer path repository to consume `daemon8/symfony` and `daemon8/php` directly from a sibling clone of the monorepo at `../../daemonai/sdks/`. To swap to the published Packagist versions before release, see [`DEPLOY.md`](./DEPLOY.md).

## License

MIT. See [`LICENSE`](./LICENSE).
