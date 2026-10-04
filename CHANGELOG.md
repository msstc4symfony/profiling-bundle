# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

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

[1.0.0]: https://github.com/msstc4symfony/profiling-bundle/releases/tag/v1.0.0
