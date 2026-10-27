# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [2.3.0] - 2026-10-27

### Added

- `DetachedSpanFactoryInterface::OUTGOING_REQUEST` (`{"profiling_outgoing_request": true}`), merged into the context of
  the `http_client` and `guzzle` spans, so a create processor can tell an outgoing request from other detached work.

## [2.2.0] - 2026-10-22

### Added

- Opt-in automatic spans, all off by default (`true`/`false` only, no `%env()%`):
  - `auto_spans.dbal`: `sql <type> <table>` (context `connection`) around Doctrine DBAL `query()`, `exec()` and
    `Statement::execute()`, through a driver middleware per DoctrineBundle connection (tag priority -100);
  - `auto_spans.http_client`: detached `http <METHOD> <host>` (context `url_path`) for Symfony HttpClient requests,
    by decorating `http_client.transport` at priority -25; one span per attempt behind `retry_failed`, none for
    `caching` hits;
  - `auto_spans.guzzle`: detached `http <METHOD> <host>` (context `url_path`) for Guzzle client services, through the
    outermost handler-stack middleware (one span covers redirects and retries); clients whose options make Guzzle 8
    build its own handler get it through a service configurator.

  `url_path` replaces ids (`:uuid`, `:id`, `:hash`). Enabling an option without its packages is a configuration
  error; `dbal` without DoctrineBundle configured and `http_client` without `framework.http_client` fail the
  compilation with a `LogicException`. Spans the integrations open while end processors run or while a span is
  being created (decision makers, create processors) are not recorded, so a log handler or decision maker writing
  through DBAL or HTTP cannot feed itself.
- `Application\DetachedSpanFactoryInterface` (extends `ProfilingFactoryInterface`; `createDetachedSpan()`, context
  markers `DETACHED` and `AUTOMATIC`), implemented by `ProfilingFactory` and `NullProfilingFactory`: spans that are
  not pushed on the stack, for work completing outside its nesting such as asynchronous HTTP responses. A detached
  span may end after its parent: the 2.1 guarantee that a child never outlasts its parent does not cover it.
  `endAll()` and `reset()` end the open ones, in at most `ProfilingFactory::MAX_END_PASSES` rounds. The
  integrations reach the factory through this service: a factory decorator decorates `ProfilingFactory` and
  implements this interface.

### Fixed

- End processors run only once an ended span and all its implicitly ended children are settled: an end handler of a
  child that ended another span no longer made processors see siblings still open.
- `endAll()` and `reset()` stop after `ProfilingFactory::MAX_END_PASSES` rounds, with an error log, when end
  processors keep opening spans; a custom span throwing from `setParentSpan()` or `addEndHandler()` is logged instead
  of reaching the profiled code.

### Changed

- `ProfilingBundle::__construct()` takes an optional argument (internal, for tests); `new ProfilingBundle()` is
  unchanged.

### Requirements

- Optional, per auto span: `doctrine/dbal` >= 3.7, `doctrine/doctrine-bundle` >= 2.13 and
  `msstc4symfony/sql-labeller` ^1.0 (`dbal`); `symfony/http-client` >= 7.4 (`http_client`); `guzzlehttp/guzzle`
  >= 7.8 (`guzzle`). Older versions are declared as conflicts. `msstc4symfony/sql-labeller` is not on Packagist:
  register `composer config repositories.msstc4symfony-sql-labeller vcs https://github.com/msstc4symfony/sql-labeller`
  in the application (Composer ignores the repositories of a dependency).

## [2.1.0] - 2026-10-22

### Added

- `Application\Span\EndableAtInterface` (`endAt()`, `getEndedAt()`), implemented by `AbstractSpan`. The factory
  ends implicitly closed children at their parent's end time through it instead of requiring `AbstractSpan`, so a
  span wrapper returned by a create processor that implements it by delegating keeps "a child never outlasts its
  parent".

## [2.0.0] - 2026-10-20

No changes since 2.0.0-rc.1.

## [2.0.0-rc.1] - 2026-10-19

### Changed

- `Msstc4Symfony\ProfilingBundle\Framework\*` → `Msstc4Symfony\ProfilingBundle\Application\*` (factory, spans,
  assemblers, decision makers, processors; sub-namespaces unchanged).
- `Msstc4Symfony\ProfilingBundle\EventListener\*` → `Msstc4Symfony\ProfilingBundle\Presentation\EventListener\*`.
- Classes outside the README "Public API" are `@internal`: the event listeners, `ListBasedDecisionMaker`,
  `LoggerProcessor`.
- Service ids and autoconfiguration tag names follow the new FQCNs (services are registered by class, tags by
  interface name): update decorations or manual tags that spell the old ones.
- Configuration keys under `msstc4symfony_profiling` are unchanged.
- PHPStan enforces `#[\Override]` on every overriding or implementing method (`checkMissingOverrideMethodAttribute`).
- Adopts bundle-standard v3 (canonical hex layout, anchored deptrac `BundleRoot`).

## [1.1.0] - 2026-10-14

### Added

- `symfony/polyfill-php84` dependency (`array_all`/`array_any` on PHP 8.2/8.3).

### Changed

- Requires PHP 8.2 instead of 8.4 (Symfony 8 still needs PHP 8.4).
- Class constants no longer declare a native type (PHP 8.2 has none); values are unchanged.

## [1.0.0] - 2026-10-04

First release of `msstc4symfony/profiling-bundle` (namespace `Msstc4Symfony\ProfilingBundle`).

### Added

- Nested timing spans kept by `ProfilingFactoryInterface`: open spans form a tree, children end
  before their parent, `end()` is idempotent, durations use `hrtime`.
- Automatic spans for configured routes (`kernel.request` to `kernel.terminate`), console
  commands (kept open across `kernel.reset`, so `messenger:consume` is measured as a whole) and
  Messenger messages handled by a worker (outcome, retry and batch acknowledgement in the context).
- Extension points autoconfigured by interface: `AllowSpanDecisionMakerInterface`,
  `SpanAssemblerInterface`, `CreateSpanProcessorInterface`, `EndSpanProcessorInterface`.
- Built-in `LoggerProcessor` writing every finished span to the `profiling` Monolog channel;
  Prometheus durations through `msstc4symfony/metrics-bridge-profiling`.
- Configuration under the `msstc4symfony_profiling` root: `routes`, `commands`, `messages`,
  `spans.whitelist` / `spans.blacklist`.

### Requirements

- PHP >= 8.4, Symfony ^7.4|^8.0, Monolog ^3.5, `psr/log` ^2.0|^3.0, `symfony/service-contracts` ^3.
- Optional: Symfony Messenger (message spans), MonologBundle (the `profiling` channel).

[2.3.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v2.3.0
[2.2.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v2.2.0
[2.1.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v2.1.0
[2.0.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v2.0.0
[2.0.0-rc.1]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v2.0.0-rc.1
[1.1.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v1.0.0
