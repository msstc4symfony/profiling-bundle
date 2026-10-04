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
5. `endAll()` / `reset()`: `endSpan()` вершины (лог вместо исключения), затем удаление её из
   стека по идентичности. `reset()` останавливается на верхнем незакрытом span, помеченном
   `ProfilingFactoryInterface::keepOpenOnReset()` (в `ProfilingFactory` — `WeakMap`; декоратор
   фабрики обязан делегировать): он и всё под ним остаются открытыми. Помечает
   `ConsoleEventListener` — span команды живёт до `console.terminate`, хотя
   `messenger:consume` зовёт `kernel.reset` после каждого сообщения. `endAll()` закрывает всё. `endSpan()` — для любого кода фреймворка (listener'ы бандла).
   Неявно закрытые дети получают контекст `ProfilingFactory::IMPLICIT_END`.

## Подключение

- `ProfilingBundle` (`AbstractBundle`, alias `msstc4symfony_profiling`): дерево конфига →
  параметры `msstc4symfony_profiling.{routes,commands,messages}.whitelist`,
  `msstc4symfony_profiling.spans.{whitelist,blacklist}`.
- `services.php`: `load()` всего namespace (кроме `ProfilingBundle.php`, `Resources/`, и
  `MessageEventListener` без `symfony/messenger`), autowire + autoconfigure. `kernel.reset` —
  через autoconfigure по `ResetInterface`. Алиас `ProfilingFactoryInterface` приватный.
- Listener'ы: `Request`/`Message` — `ResetInterface`; `Console` — нет (его span живёт до
  `console.terminate`). События: `kernel.request` (main, не OPTIONS, `_route` в whitelist) /
  `console.command` (priority 4096) / `WorkerMessageReceivedEvent` (priority -1024, после veto;
  `instanceof` по whitelist); конец — `*.terminate` (затем на -4096 `endAll()`) /
  Handled/Failed **своего** сообщения (контекст `message_failed`, `message_will_retry`), иначе
  `WorkerRunningEvent` (priority 0, до `kernel.reset`) или следующий Received
  (`MessageEventListener::NOT_ACKNOWLEDGED`). Ключи контекста с префиксом: `LoggerProcessor`
  делает `array_merge` с контекстом span, общие ключи затёрли бы прикладные.
- `LoggerProcessor` — `#[WithMonologChannel('profiling')]` (monolog-bundle >= 3.10: `conflict`
  `<3.10` в `composer.json` нужен — monolog-bundle опционален, а 3.9.0 ставится с Symfony 7.4;
  в `composer-ci.json` он снят как избыточный при `require-dev` `^3.11|^4.0`), `info`, сообщение `родитель > ... > span`, контекст `duration` + span + `end()`.
- Экспорт в Prometheus — отдельный пакет `msstc4symfony/metrics-bridge-profiling`
  (`MetricEndSpanProcessor`, берёт `getDuration()`): только мост объявляет и пишет
  `profiling_span_duration_histogram_seconds`, metrics-bundle этой метрики не знает.

## Слои (deptrac)

`Framework` — ядро без зависимостей на остальное; `EventListener` → `Framework`;
`ProfilingBundle` (корень) — от всех.

## Не-final классы

- `Framework\Span\AbstractSpan` — `abstract`, точка расширения для собственных span (наследник
  реализует `isRecorded()`). Его состояние `private`, `end()` / `endAt()` / `getEndedAt()` —
  `final`: фабрика закрывает детей через `endAt()`, переопределение пропустило бы handlers.

Все остальные конкретные классы `src/` — `final` / `final readonly`.
