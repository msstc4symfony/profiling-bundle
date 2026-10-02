# Profiling Symfony bundle

![Build Status](https://github.com/msstc4symfony/profiling-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Lightweight spans for Symfony applications: measure how long an HTTP request, a console
command or any block of code takes, nested into a tree, and write each finished span to the
`profiling` Monolog channel (`request orders > sql select > http GET api`, with `duration`
in seconds and the span context).

## Compatibility

| Bundle | PHP  | Symfony       |
|--------|------|---------------|
| 1.x    | 8.4+ | 6.4, 7.x, 8.x |

## Installation

The package is not on Packagist yet, so register its GitHub repository first:

```sh
composer config repositories.msstc4symfony-profiling vcs https://github.com/msstc4symfony/profiling-bundle
composer require msstc4symfony/profiling-bundle
```

Symfony Flex registers the bundle automatically; without Flex add it to `config/bundles.php`:

```php
Msstc4Symfony\ProfilingBundle\ProfilingBundle::class => ['all' => true],
```

Declare the `profiling` log channel (`doc/monolog.yaml`) and route it to a handler.

## Configuration

Nothing is profiled by default. `config/packages/msstc4symfony_profiling.yaml`:

```yaml
msstc4symfony_profiling:
    # Main HTTP requests with these route names get a "request <route>" span. It starts on
    # kernel.request at priority 0 (after the router and the firewall) and ends on
    # kernel.terminate, i.e. after the response was sent.
    routes: ['api_orders_list']
    # Console commands with these names get a "cli command <name>" span, from console.command
    # to console.terminate (kernel.reset in between, e.g. in messenger:consume, keeps it open).
    commands: ['app:import']
    # Messages of these classes (or their parents/interfaces) get a "message <class>" span
    # while a worker handles them (needs symfony/messenger).
    messages: ['App\Message\ImportOrders']
    # Span message prefixes; set at most one list.
    spans:
        # Only spans starting with one of these are recorded ([] records nothing).
        whitelist: ~
        # Spans starting with one of these are dropped; everything else is recorded.
        blacklist: ['sql ']
```

## Usage

```php
use Msstc4Symfony\ProfilingBundle\Framework\ProfilingFactoryInterface;

final class OrderImporter
{
    public function __construct(private ProfilingFactoryInterface $profiling) {}

    public function import(): void
    {
        $span = $this->profiling->createSpan('import orders', ['source' => 'erp']);
        // ...
        $span->end(['rows' => 120]);
    }
}
```

Or `use ProfilingFactoryOwnerTrait;` in an autowired service: the factory is injected through
a `#[Required]` setter and falls back to a no-op factory outside the container.

- A span opened while another is open becomes its child; ending a parent first ends every
  child still open, innermost first, at the parent's end time (spans wrapped by a create
  processor end when they are reached), with the context
  `ProfilingFactoryInterface::IMPLICIT_END` (`{"profiling_implicit_end": true}`), as do spans
  closed by `endAll()` / `kernel.reset`. `end()` fixes
  the duration (monotonic clock); calling it again does nothing.
- Spans rejected by the decision makers are `NullSpan`s: they keep nesting intact but are
  never passed to end processors.
- Open spans are ended on `kernel.terminate` / `console.terminate` and on `kernel.reset`; a
  running command's span (`commands`) and the spans below it survive `kernel.reset`
  (`ProfilingFactory::keepOpenOnReset()`).
- A failing assembler, decision maker, create or end processor never breaks the profiled
  code: the exception is logged on the default `logger` and profiling carries on. An
  exception from your own end handler reaches the code that called `end()` on that span
  (after every handler ran and the span was processed; only the first exception is
  rethrown). Framework code ends spans with `ProfilingFactoryInterface::endSpan()`, which
  logs instead; so do implicit and `endAll()` endings.

### Workers

`kernel.reset` runs after every consumed message and ends every open span except the span of
a command listed in `commands`: a `messenger:consume` entry measures the whole run, until
`console.terminate`. Profile each message with `messages`; its span becomes a child of that
command span when both are configured. Spans left open by a handler end with the message
span or, without one, with the next `kernel.reset`. A message span ends with the message's own
handled/failed event; batch handlers acknowledge later, so their span ends when the worker
moves on (`WorkerRunningEvent`, before `kernel.reset`), with `message_acknowledged: false`.
Failures add `message_failed` and `message_will_retry`. Vetoed messages are not profiled. This
assumes Messenger's default synchronous execution; with an asynchronous execution strategy
(Symfony 8.1+) handled events come after `WorkerRunningEvent`, so message spans would only
measure the dispatch.

### Extension points (autoconfigured by interface)

| Interface | Role |
|---|---|
| `AllowSpanDecisionMakerInterface` | votes whether a span is recorded (`true`/`false`/`null` = abstain); asked by priority (`#[AsTaggedItem(priority: ...)]`), the first vote wins. The built-in list maker runs last (-1024) and abstains when no list is set |
| `SpanAssemblerInterface` | builds the span object for a message |
| `CreateSpanProcessorInterface` | can enrich or wrap a span when it is created. A wrapper must delegate `end()`, `isEnded()` and `addEndHandler()` to the wrapped span; the factory binds its end handler to the returned span |
| `EndSpanProcessorInterface` | receives every recorded span once, after it ended (`LoggerProcessor` is built in; `msstc4symfony/metrics-bridge-profiling` adds Prometheus durations). Order with `#[AsTaggedItem(priority: ...)]` |

Do not remove the factory's own end handler (`getEndHandlers()` lists it): such a span is
never processed and is dropped from the stack.

Span messages become log messages and, with metrics-bridge-profiling, Prometheus label values: keep
them low-cardinality (`request orders_show`, not `request /orders/42`) and put ids into the
context.

## Local development

```sh
COMPOSER=composer-ci.json composer install
COMPOSER=composer-ci.json make check
make test
make fix
```

## License

MIT, see [LICENSE](LICENSE).
