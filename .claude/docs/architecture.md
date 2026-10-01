# Архитектура

## Жизненный цикл span

1. `ProfilingFactory::createSpan($message, $context)`:
   - снимает с вершины стека span, завершённые без handler'а фабрики (`isEnded()`);
   - assemblers по очереди (`SpanAssembler` спрашивает decision makers по приоритету, первый
     не-`null` голос решает; встроенный `ListBasedDecisionMaker` — последний,
     `#[AsTaggedItem(priority: -1024)]`) →
     `Span` или `NullSpan`; без assemblers — `NullSpan`;
   - `CreateSpanProcessorInterface` могут обернуть span; родитель и end handler фабрики
     ставятся на **итоговый** span (замыкание захватывает его, а не аргумент handler'а).
2. `AbstractSpan::end()` = `endAt(hrtime)`: идемпотентен, запускает все handlers (даже если
   один бросил), первое исключение бросает в конце. Ошибки assemblers/create-процессоров
   ловятся в `createSpan()` (fallback — `NullSpan` / span до упавшего процессора).
3. `ProfilingFactory::onEnd`: сначала стек — span и все выше него снимаются, дети
   (изнутри наружу, кроме уже закрытых «отвязанных») встают в очередь `endedSpans` и
   получают `endAt(время родителя)` в try/catch (их handler видит, что их нет в стеке, и
   молчит); очередь разбирается в `finally`. Очередь разбирается одним нереентерабельным циклом
   (`processing`), поэтому процессор, открывающий/закрывающий span, не меняет порядок и не
   вызывает повторной обработки.
4. Процессоры — только для `isRecorded()`, каждый в try/catch → `logger->error`
   (логгер `logger`, не канал `profiling`: падающий `LoggerProcessor` не должен логировать в себя).
5. `endAll()` / `reset()`: `end()` вершины, затем удаление её из стека по идентичности.

## Подключение

- `ProfilingBundle` (`AbstractBundle`, alias `msstc4symfony_profiling`): дерево конфига →
  параметры `msstc4symfony_profiling.{routes,commands,messages}.whitelist`,
  `msstc4symfony_profiling.spans.{whitelist,blacklist}`.
- `services.php`: `load()` всего namespace (кроме `ProfilingBundle.php`, `Resources/`, и
  `MessageEventListener` без `symfony/messenger`), autowire + autoconfigure. `kernel.reset` —
  через autoconfigure по `ResetInterface`. Алиас `ProfilingFactoryInterface` приватный.
- Listener'ы (все `ResetInterface`): `kernel.request` (main, не OPTIONS, `_route` в whitelist) /
  `console.command` (priority 4096) / `WorkerMessageReceivedEvent` (priority -1024, после veto;
  `instanceof` по whitelist); конец — `*.terminate` (затем на -4096 `endAll()`) /
  Handled/Failed **своего** сообщения (контекст `failed`, `will_retry`) или следующий Received
  (`acknowledged: false`).
- `LoggerProcessor` — `#[WithMonologChannel('profiling')]` (monolog-bundle >= 3.10, в
  `conflict`), `info`, сообщение `родитель > ... > span`, контекст `duration` + span + `end()`.
- `MetricProcessor` живёт в metrics-bundle (`Framework/Profiling/...`); должен брать
  `getDuration()`, а не `microtime - getStartTime()`.

## Слои (deptrac)

`Framework` — ядро без зависимостей на остальное; `EventListener` → `Framework`;
`ProfilingBundle` (корень) — от всех.
