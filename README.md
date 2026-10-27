# Profiling Symfony bundle

![Build Status](https://github.com/msstc4symfony/profiling-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Lightweight spans for Symfony applications: measure how long an HTTP request, a console
command, a DBAL query, an outgoing HTTP call or any block of code takes, nested into a tree,
and write each finished span to the `profiling` Monolog channel
(`request orders > sql select orders`, with `duration` in seconds and the span context).

## Compatibility

| Bundle | PHP  | Symfony       |
|--------|------|---------------|
| 2.2    | 8.2+ | 7.4, 8.x      |
| 2.1    | 8.2+ | 7.4, 8.x      |
| 2.0    | 8.2+ | 7.4, 8.x      |
| 1.1    | 8.2+ | 7.4, 8.x      |
| 1.0    | 8.4+ | 7.4, 8.x      |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

## Installation

The package is not on Packagist yet, so register its GitHub repository first:

```sh
composer config repositories.msstc4symfony-profiling vcs https://github.com/msstc4symfony/profiling-bundle
composer require msstc4symfony/profiling-bundle
```

`auto_spans.dbal` also needs `msstc4symfony/sql-labeller`, likewise only on GitHub (Composer does not read the
repositories of a dependency):

```sh
composer config repositories.msstc4symfony-sql-labeller vcs https://github.com/msstc4symfony/sql-labeller
composer require msstc4symfony/sql-labeller
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
    # Automatic spans for libraries, all off by default. Plain true/false only: an %env()% value is
    # rejected, the flag decides the container wiring.
    auto_spans:
        # "sql <type> <table>" around every Doctrine DBAL query (needs doctrine/dbal >= 3.7, DoctrineBundle
        # >= 2.13 and msstc4symfony/sql-labeller).
        dbal: false
        # Detached "http <METHOD> <host>" span for every Symfony HttpClient request (needs symfony/http-client
        # >= 7.4 and framework.http_client).
        http_client: false
        # Detached "http <METHOD> <host>" span for every request of a Guzzle client service (needs
        # guzzlehttp/guzzle >= 7.8).
        guzzle: false
```

### Automatic spans

| Option | Span message | Context | End context |
|---|---|---|---|
| `dbal` | `sql <select\|insert\|update\|delete\|other> <table>` (`unknown` without a table), e.g. `sql select orders` | `connection` (DoctrineBundle connection name), `profiling_automatic` | `exception` (class) when the query throws |
| `http_client` | `http <METHOD> <host>`, e.g. `http GET api.example.com` | `url_path`, `profiling_automatic`, `profiling_outgoing_request`, `profiling_detached` | `status`; `exception` (class) on a transport or HTTP error; `cancelled` (before the headers) or `body_cancelled` + `status` (after them); `stream_closed` when a `toStream()` stream is closed; `response_destroyed` when the response is destroyed unfinished |
| `guzzle` | `http <METHOD> <host>` | `url_path`, `profiling_automatic`, `profiling_outgoing_request`, `profiling_detached` | `status`; `exception` (class) when the promise is rejected, plus `status` when the rejection carries a response; `exception` when the handler throws |

Enabling an option whose packages are missing is a configuration error (`"msstc4symfony_profiling.auto_spans.<option>"
needs <packages> installed.`). With the packages present, `dbal` without DoctrineBundle registered and
`doctrine.dbal` configured, and `http_client` with `framework.http_client` disabled fail the container compilation
with a `LogicException` naming the option.

`url_path` is the path without the query, with ids replaced: UUIDs become `:uuid`, numbers `:id`, hex strings of 24+
characters `:hash` (`/orders/42` → `/orders/:id`). Other secrets in path segments (tokens, e-mails) are kept: do not
put them in paths, or drop such spans with a decision maker.

- DBAL: a driver middleware per DoctrineBundle connection (tag `doctrine.middleware`, priority -100: outside
  middlewares of the default priority 0, metrics-bundle's included), around `Connection::query()`, `exec()` and
  `Statement::execute()`. The span ends when the driver returns: fetching the rows is not included, and on failure
  `exception` names the driver's exception class, before DBAL converts it. `prepare()` and transactions
  (`beginTransaction()`, `commit()`, `rollBack()`) get no span. Table and statement type come from
  `msstc4symfony/sql-labeller`. `spans.blacklist: ['sql ']` drops every SQL span; `['sql select ']` only the
  SELECTs.
- HttpClient: decorates `http_client.transport` (decoration priority -25), so every client, default and scoped,
  gets one span with the resolved host. The span ends when the body was fully received (`getContent()`,
  `toArray()`, the last chunk of `stream()`, the end of `toStream()`), on a failure (also a transport error in the
  middle of a `toStream()` body), on `cancel()`, when the `toStream()` stream is closed or when the response is
  destroyed. Nothing is read in `request()`: concurrent
  requests stay concurrent; a `request()` that throws (invalid options) still gets a span with `exception`.
  Clients above the transport see each attempt: with `retry_failed` every attempt is its own span, a retried one
  ending with `body_cancelled` and its status (e.g. 503); responses served by the HttpClient `caching` option never
  reach the transport and get no span.
- Guzzle: a handler-stack middleware added as the **outermost** one of every Guzzle client service (`unshift()`),
  so one span covers redirects and retries. tracing-bundle pushes its middleware to the other end of the stack, so
  the order of the two bundles does not matter. Clients built from `GuzzleHttp\Client::__construct()` get it through
  their `handler` config, other clients with `getConfig()` through a service configurator (an existing configurator
  is kept; also clients configured with `max_host_connections`, `max_total_connections`, `transport_sharing` or
  `multiplex` and no `handler`, which Guzzle 8 refuses next to a given handler); a client the bundle cannot reach, or whose `handler` is not a `HandlerStack`, is named in the container
  compilation log and not profiled (at run time the configurator leaves such a client alone too). Only client
  services are seen: a client defined inline (`inline_service()`) as an argument of another service is not
  profiled. The span ends when the promise settles, for `send()` and asynchronous pools alike; a promise never
  waited for keeps its span open until `endAll()` or `kernel.reset` (in a long-running command without
  `kernel.reset`, such spans accumulate).
- No feedback loop: spans the integrations open (`profiling_automatic` in their context,
  `DetachedSpanFactoryInterface::AUTOMATIC`) are not recorded while end processors run or while a span is being
  created (decision makers, assemblers, create processors), so a log handler of the `profiling` channel or a
  decision maker reading its rules through DBAL or HTTP does not profile itself.
- All three integrations reach the factory through the `DetachedSpanFactoryInterface` service. To decorate the
  factory, decorate the `ProfilingFactory` service (every span then goes through the decorator) with a class that
  implements `DetachedSpanFactoryInterface` and delegates `createDetachedSpan()` as well; decorating only the
  `ProfilingFactoryInterface` alias leaves the automatic spans undecorated.

### Detached spans

An HTTP response completes after the code that sent the request went on, while the factory's span stack is strictly
nested. `DetachedSpanFactoryInterface::createDetachedSpan()` (implemented by `ProfilingFactory` and
`NullProfilingFactory`, autowirable) opens a span whose parent is the span open now, but which is not pushed on the
stack: spans opened meanwhile do not become its children, and ending its parent does not end it — a detached span
may end after its parent, the guarantees below about children ending with their parent do not apply to it.
Decision makers and create processors apply as for `createSpan()`; its context carries `DetachedSpanFactoryInterface::DETACHED`
(`{"profiling_detached": true}`) so a create processor can recognise it. End it yourself, preferably with
`endSpan()`; detached spans still open are ended with `IMPLICIT_END` by `endAll()` and by `kernel.reset` (also
under a span kept by `keepOpenOnReset()`), including detached spans opened while those end the stack. End
processors that open a new span for every span they process would keep that going: after
`ProfilingFactory::MAX_END_PASSES` (64) rounds the factory stops and logs an error.

The spans of the `http_client` and `guzzle` integrations also carry `DetachedSpanFactoryInterface::OUTGOING_REQUEST`
(`{"profiling_outgoing_request": true}`), so a create processor can tell an outgoing request from other detached work:
tracing-bridge-profiling, for one, reserves the trace span id of such a span for the request's `traceparent`.

## Usage

```php
use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;

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
  child still open (except [detached spans](#detached-spans)), innermost first, at the parent's end time — when both the parent and the
  child implement `EndableAtInterface` (`AbstractSpan` does; a create-processor wrapper should
  delegate it); otherwise the child ends when the factory gets to it, after the parent — with the context
  `ProfilingFactoryInterface::IMPLICIT_END` (`{"profiling_implicit_end": true}`), as do spans
  closed by `endAll()` / `kernel.reset`. `end()` fixes
  the duration (monotonic clock); calling it again does nothing.
- Spans rejected by the decision makers are `NullSpan`s: they keep nesting intact but are
  never passed to end processors.
- Open spans are ended on `kernel.terminate` / `console.terminate` and on `kernel.reset`; a
  running command's span (`commands`) and the spans below it survive `kernel.reset`
  (`ProfilingFactoryInterface::keepOpenOnReset()`). If you decorate `ProfilingFactoryInterface`,
  delegate `keepOpenOnReset()` to the inner factory, or the command span ends on the first
  `kernel.reset`.
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
| `CreateSpanProcessorInterface` | can enrich or wrap a span when it is created. A wrapper must delegate `end()`, `isEnded()` and `addEndHandler()` to the wrapped span, and should implement `EndableAtInterface` by delegating too (so its children and itself end at their parent's end time); the factory binds its end handler to the returned span |
| `EndSpanProcessorInterface` | receives every recorded span once, after it ended (`LoggerProcessor` is built in; `msstc4symfony/metrics-bridge-profiling` adds Prometheus durations). Order with `#[AsTaggedItem(priority: ...)]` |

Do not remove the factory's own end handler (`getEndHandlers()` lists it): such a span is
never processed and is dropped from the stack.

Span messages become log messages and, with metrics-bridge-profiling, Prometheus label values: keep
them low-cardinality (`request orders_show`, not `request /orders/42`) and put ids into the
context.

## Public API

Applications and bridges use or implement only these; every other class is `@internal` and may change in a minor
release.

| Namespace `Msstc4Symfony\ProfilingBundle\` | Classes |
|---|---|
| `Application` | `ProfilingFactoryInterface`, `DetachedSpanFactoryInterface`, `ProfilingFactory`, `NullProfilingFactory`, `ProfilingFactoryOwnerTrait` |
| `Application\Span` | `SpanInterface`, `EndableAtInterface`, `AbstractSpan`, `Span`, `NullSpan` |
| `Application\Assembler` | `SpanAssemblerInterface`, `SpanAssembler` |
| `Application\DecisionMaker\AllowSpan` | `AllowSpanDecisionMakerInterface` |
| `Application\Processor\CreateSpan` | `CreateSpanProcessorInterface` |
| `Application\Processor\EndSpan` | `EndSpanProcessorInterface` |
| (root) | `ProfilingBundle` |

The configuration keys under `msstc4symfony_profiling` (see [Configuration](#configuration)) are public too.

## Local development

```sh
COMPOSER=composer-ci.json composer install
COMPOSER=composer-ci.json make check
make test
make fix
```

## License

MIT, see [LICENSE](LICENSE).
