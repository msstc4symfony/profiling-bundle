# Changelog

## 1.0.0

First release as `msstc4symfony/profiling-bundle` (`Msstc4Symfony\ProfilingBundle`), MIT.

- HTTP requests are closed properly: `kernel.terminate` ends the request span and then every
  span left open (the listener used to subscribe `onTerminate` twice and never `endAll()`).
- Ending a span ends its open children first, innermost first, and each reaches the end
  processors; rejected spans (`NullableSpan`) never reach them and no longer crash on
  `getStartTime()`.
- `endAll()` and `kernel.reset` close every open span; listeners drop their span on reset.
- A factory without assemblers records spans instead of failing.
- Parameters renamed to `msstc4symfony_profiling.*`; services are configured in PHP, no YAML
  dependency.
- PHP >= 8.4, Symfony 6.4 / 7.x / 8.x.
