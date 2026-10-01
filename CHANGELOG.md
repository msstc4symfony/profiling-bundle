# Changelog

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
- Failures of assemblers, decision makers, processors and implicitly run end handlers are
  logged instead of breaking the profiled code; children end at their parent's end time.
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
