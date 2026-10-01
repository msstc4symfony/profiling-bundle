# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

Runtime requirements: PHP >= 8.4, Symfony 6.4 LTS / 7.x / 8.x.

## Reporting a Vulnerability

Do **not** open public issues for security problems. Use:

1. **GitHub Security Advisory** (preferred):
   <https://github.com/msstc4symfony/profiling-bundle/security/advisories/new>
2. **Email**: `maxim.shamaev@gmail.com`

Include the bundle, PHP and Symfony versions, a reproducer and the impact. You will get an
acknowledgement within **7 days**; please allow 30–90 days before public disclosure.

## Threat Model

### 1. Span context ends up in logs

Everything passed as span context (`createSpan($message, $context)` and `end($context)`) is
written to the `profiling` log channel as-is, together with route and command names. Never
put secrets, tokens or personal data into span context, and route the `profiling` channel
only to log storage that is allowed to hold request metadata.

### 2. Profiling cost is opt-in

Nothing is profiled until routes, commands or messages are configured. Whitelisting a
high-traffic route adds one log record per request (plus one per nested span), so size the
log pipeline accordingly.

### 3. Long-running processes

Open spans are ended on `kernel.reset`. Custom loops outside the kernel's reset points must
call `ProfilingFactoryInterface::endAll()` themselves, or spans accumulate across iterations.
