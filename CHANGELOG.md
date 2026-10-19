# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

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

[2.0.0-rc.1]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v2.0.0-rc.1
[1.1.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v1.0.0
