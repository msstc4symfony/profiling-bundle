# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/profiling-bundle`, namespace `Msstc4Symfony\ProfilingBundle`)
with nested timing spans for HTTP requests, console commands and custom code; finished spans
go to end processors (Monolog `profiling` channel built in, Prometheus via metrics-bundle).
PHP >= 8.4, Symfony 6.4 / 7.x / 8.x. Library code only.

## Common commands

Develop against the CI profile: `COMPOSER=composer-ci.json composer install`.

- `make check` — `php -l`, PHPStan level 9, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check`.
- `make test` — unit + integration suites; `make infection`, `make fix`, `make regenerate-baseline`.

## Architecture in 60 seconds

- `Framework\ProfilingFactory` keeps the stack of open spans (`ResetInterface`, `kernel.reset`).
  Assemblers → create processors → stack; on `end()` children close innermost first, then
  end processors run for recorded spans only.
- `EventListener\RequestEventListener` / `ConsoleEventListener` open spans for whitelisted
  routes/commands and call `endAll()` at the very end of terminate.
- `Resources/config/services.php` autoloads the whole namespace; extension points are
  autoconfigured by interface. Rector's `FromServicePublicToDefaultsPublicRector` and
  `ServiceSettersToSettersAutodiscoveryRector` are skipped on purpose.

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing
a "weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — wiring, span lifecycle, layers.
- `.claude/docs/conventions.md` — parameters, autoconfiguration, reset rules.
- `.claude/docs/testing.md` — unit layout, real-kernel test.
- `.claude/docs/tooling.md` — manifests, `make check`, Rector skips.
- `.claude/docs/ci.md` — reusable workflow.
- `.claude/docs/known-issues.md` — what was broken before 1.0.0 and current gotchas.
