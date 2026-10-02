# Changelog

## 1.1.0

- A command listed in `commands` keeps its span across `kernel.reset`: `messenger:consume`
  (which resets services after every message) is now measured until `console.terminate`
  instead of until the first message. Spans opened above it still end on reset.
- New `ProfilingFactory::keepOpenOnReset(SpanInterface)`: `reset()` leaves a marked span and
  the spans below it open; `endAll()` still ends them. `ConsoleEventListener::reset()` no
  longer forgets the command span.
- `symfony/service-contracts` (`^2.5|^3`) is declared in `require`; it was only pulled in
  transitively.
- Tooling: bundle-standard v1.8.0 (blocking BC check and Infection with minimum MSI 82, a
  `--prefer-lowest` PHPUnit cell), PHPStan level 10.

## 1.0.0

First release as `msstc4symfony/profiling-bundle` (`Msstc4Symfony\ProfilingBundle`), MIT.

- HTTP requests are closed properly: `kernel.terminate` ends the request span and then every
  span left open (the listener used to subscribe `onTerminate` twice and never `endAll()`).
- Ending a span ends its open children first, innermost first, and each reaches the end
  processors; rejected spans (`NullSpan`) never reach them and no longer crash on
  `getStartTime()`.
- `endAll()` and `kernel.reset` close every open span; listeners drop their span on reset.
- `end()` is idempotent and fixes the duration (`getDuration()`, monotonic clock); each span
  reaches the end processors once, in order, even when a processor opens or ends spans.
- Failures of assemblers, decision makers, processors and end handlers run by the bundle
  (implicit endings, `endAll()`, listeners via the new `ProfilingFactoryInterface::endSpan()`)
  are logged instead of breaking the profiled code; children end at their parent's end time
  with the context `ProfilingFactoryInterface::IMPLICIT_END`; `AbstractSpan::end()` is final.
- BC for custom implementations: `ProfilingFactoryInterface` gained `endSpan(SpanInterface,
  array): void` (must not throw); `SpanInterface` gained `isEnded()`, `getDuration()`,
  `isRecorded()` and `setParentSpan()`.
- Create processors may wrap spans; the factory tracks the returned span.
- A factory without assemblers creates unrecorded spans instead of failing.
- New `messages` option: one span per consumed Messenger message (class, parent or interface).
- Configuration tree `msstc4symfony_profiling` (routes, commands, messages, spans) with
  validation replaces the `hot.profiling_bundle.*` parameters; whitelist and blacklist are
  mutually exclusive. Services are configured in PHP, no YAML dependency.
- Renamed: `NullableSpan` → `NullSpan`, `NullableProfilingFactory` → `NullProfilingFactory`,
  `AllowSpanDecisionMakerInterface::isAllow()` → `isAllowed()`; `getDefaultPriority()` removed
  from the extension interfaces (deprecated by Symfony 8.1) — use `#[AsTaggedItem]`; the
  list-based decision maker abstains without lists and runs last.
- PHP >= 8.4, Symfony 6.4 / 7.x / 8.x.
