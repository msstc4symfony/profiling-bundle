# Архитектура

## Жизненный цикл span

1. `ProfilingFactory::createSpan($message, $context)`:
   assemblers по очереди (`SpanAssembler` спрашивает decision makers по приоритету; первый
   не-`null` голос решает) → `Span` или `NullableSpan`; родитель — вершина стека; end handler
   фабрики; `CreateSpanProcessorInterface` могут заменить span; span кладётся в стек.
2. `$span->end($context)` → end handlers → `ProfilingFactory::onEnd`: сначала закрываются
   все открытые дочерние (с вершины), затем span удаляется из стека, затем — только если
   `isRecorded()` — `EndSpanProcessorInterface`.
3. `endAll()` / `reset()` закрывают стек с вершины. Span, у которого сняли end handler
   фабрики, `endTop()` выталкивает сам.

## Подключение

- `services.php`: `load()` всего namespace (кроме `ProfilingBundle.php`, `Resources/`),
  autowire + autoconfigure. Интерфейсы-точки расширения — `#[AutoconfigureTag]`, фабрика
  собирает их `#[AutowireIterator]`.
- `ProfilingFactory` — `kernel.reset`; алиас `ProfilingFactoryInterface` публичный.
- Listener'ы: `kernel.request` (main, не OPTIONS, `_route` из whitelist) / `console.command`
  (priority 4096); `*.terminate` — завершение своего span, затем на priority -4096 `endAll()`.
  Оба `ResetInterface` (autoconfigure → `kernel.reset`).
- `LoggerProcessor` — `#[WithMonologChannel('profiling')]`, уровень `info`, сообщение —
  путь `родитель > ... > span`, контекст `duration` + контекст span + контекст `end()`.
- `MetricProcessor` живёт в metrics-bundle (`Framework/Profiling/...`).

## Слои (deptrac)

`Framework` — ядро без зависимостей на остальное; `EventListener` → `Framework`;
`ProfilingBundle` (корень) — от всех.
