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

The package lives in a private GitHub repository, so register it as a VCS repository first
(`no-api` makes Composer clone over SSH instead of calling the GitHub API):

```sh
composer config repositories.msstc4symfony-profiling '{"type": "vcs", "url": "git@github.com:msstc4symfony/profiling-bundle.git", "no-api": true}'
composer require msstc4symfony/profiling-bundle
```

Register the bundle in `config/bundles.php` (Flex does not know private packages):

```php
Msstc4Symfony\ProfilingBundle\ProfilingBundle::class => ['all' => true],
```

Declare the `profiling` log channel (`doc/monolog.yaml`) and route it to a handler.

## Configuration

Nothing is profiled by default. Enable it with container parameters:

```yaml
parameters:
    # Main HTTP requests whose route name is listed get a "request <route>" span.
    msstc4symfony_profiling.routes.whitelist: ['api_orders_list']
    # Console commands listed get a "cli command <name>" span.
    msstc4symfony_profiling.commands.whitelist: ['app:import']
    # Span message prefixes. A whitelist match always records; otherwise a blacklist match
    # drops the span. null = no list.
    msstc4symfony_profiling.spans.whitelist: null
    msstc4symfony_profiling.spans.blacklist: ['sql ']
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
  child still open, innermost first.
- Spans rejected by the decision makers are `NullableSpan`s: they keep nesting intact but are
  never passed to end processors.
- Open spans are ended on `kernel.terminate` / `console.terminate` and on `kernel.reset`.

### Extension points (autoconfigured by interface)

| Interface | Role |
|---|---|
| `AllowSpanDecisionMakerInterface` | votes whether a span is recorded (`true`/`false`/`null` = abstain), ordered by `getDefaultPriority()` |
| `SpanAssemblerInterface` | builds the span object for a message |
| `CreateSpanProcessorInterface` | can replace or enrich a span when it is created |
| `EndSpanProcessorInterface` | receives every recorded span when it ends (`LoggerProcessor` is built in; `msstc4symfony/metrics-bundle` adds Prometheus durations) |

## Local development

```sh
COMPOSER=composer-ci.json composer install
COMPOSER=composer-ci.json make check
make test
make fix
```

## License

MIT, see [LICENSE](LICENSE).
