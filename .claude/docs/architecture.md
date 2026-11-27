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

## Отвязанные span (2.2)

`DetachedSpanFactoryInterface extends ProfilingFactoryInterface` (`createDetachedSpan()`, константы `DETACHED` =
`['profiling_detached' => true]` и `AUTOMATIC` = `['profiling_automatic' => true]`); реализуют `ProfilingFactory` и
`NullProfilingFactory`. Интерфейс расширяет `ProfilingFactoryInterface`, чтобы адаптеры закрывали span через
`endSpan()` (без исключений) и инжектили один сервис; методов в `ProfilingFactoryInterface`/`SpanInterface` не
добавлено (Roave BC).

- `createSpan()` и `createDetachedSpan()` делят приватный `open()` (assemblers → create processors, родитель —
  вершина стека). Отвязанный span не попадает в `activeSpans`, а в `SplObjectStorage $detachedSpans` (порядок
  создания, O(1) удаление); его handler — `onDetachedEnd()`: `forgetDetached()` (`false` → уже обработан, второй
  вызов handler'а ничего не делает), затем общая очередь `endedSpans` и `processEndedSpans()`.
- Маркер мерджится последним (`[...$context, ...DETACHED]`): контекст вызывающего его не перетирает.
- `OUTGOING_REQUEST` = `['profiling_outgoing_request' => true]` (2.3.0) ставит `OutgoingRequestSpan::context()` спанам HttpClient и Guzzle: по нему tracing-bridge-profiling 1.3 резервирует span id для `traceparent` запроса (спек `docs/superpowers/specs/2026-10-27-client-span-traceparent-design.md`). Пользовательские detached-спаны его не несут.
- `endOpenSpans()` (и `endAll()`, и `reset()`) раундами: все отвязанные (клон списка + `forgetDetached()` — span
  со снятым handler'ом иначе зациклил бы), потом снимок стека на начало раунда (`endStack()`, сверху вниз, с
  остановкой на `keepOpenOnReset()`); span, открытые в раунде, ждут следующего. Повтор, пока есть отвязанные или
  стек не «устоялся» (`isStackSettled()`: пуст или наверху открытый помеченный span); отвязанные, открытые на любой фазе, закрываются в следующем раунде. Не больше
  `MAX_END_PASSES` (64) раундов, затем `log()` — процессор, открывающий span (стековый или отвязанный) на каждый
  span, иначе крутил бы цикл вечно.
- Пользовательский span, бросающий из `setParentSpan()` (`adopt()`) или `addEndHandler()` (`bind()`), логируется;
  без handler'а фабрики span не попадает ни в стек, ни в список отвязанных. Все логи ошибок — через `log()`.
- `onEnd()`: счётчик `$settling` — пока дети закрываются (`endAt()` с их handler'ами), `processEndedSpans()` сразу
  возвращается; очередь разбирается в `finally` после всех детей. Раньше handler ребёнка, закрывший другой span,
  запускал процессоры, пока братья ещё были открыты.
- Отвязанный span может закончиться позже родителя — гарантия 2.1 «ребёнок не переживает родителя» его не касается.

### Защита от обратной связи

Пока идёт `processEndedSpans()` (`$this->processing`) или другой `open()` (счётчик `$opening`: assemblers,
decision makers, create processors), `open()` отдаёт `NullSpan` для контекста с `AUTOMATIC`
(`array_intersect_key($context, AUTOMATIC) === AUTOMATIC`). Иначе end-процессор, пишущий через инструментированную
библиотеку (Monolog-handler канала `profiling` в DBAL/HTTP), создавал бы span на каждый span — бесконечный цикл
(проверено: без guard'а тест падает по памяти). Ручные span из процессоров по-прежнему записываются.

## Автоматические span (2.2)

Единый источник ключей — `DependencyInjection/AutoSpan` (enum: `option()` = имя опции и параметра контейнера
`msstc4symfony_profiling.auto_spans.<x>`, `isEnabledIn()`, `info()`, `packages()`). `AutoSpanRequirements` проверяет
пакеты (в тестах — подменённая карта через `new ProfilingBundle(new AutoSpanRequirements([...]))`).

| Опция | Адаптер (`Infrastructure/`) | Проводка (`DependencyInjection/CompilerPass/`) |
|---|---|---|
| `dbal` | `Doctrine/DBAL/QuerySpansMiddleware` (опц. `SqlLabelling`) → `Driver` → `Connection` (`query`, `exec`) / `Statement` (`execute`); общий `QuerySpans` | `ProfileDoctrineDbalPass` (BEFORE_OPTIMIZATION, priority 1): `doctrine.middleware` с `connection: <name>`, `priority: -100` на каждое соединение из `doctrine.connections`, id `msstc4symfony_profiling.doctrine.middleware.<name>` |
| `http_client` | `HttpClient/ProfilingHttpClient` + `ProfiledResponse` + `CompletionStreamFilter` | `ProfileHttpClientPass`: декоратор `http_client.transport.decorator.profiling`, priority -25 |
| `guzzle` | `Guzzle/ProfilingGuzzleMiddleware` (`unshift()` — внешний), `Guzzle/ChainedClientConfigurator` | `RegisterGuzzleMiddlewarePass` (BEFORE_OPTIMIZATION) регистрирует middleware; `ProfileGuzzlePass` (BEFORE_REMOVING) — только переписывает клиентов: `handler` в конфиге `GuzzleHttp\Client` (если это `HandlerStack` — по классу или статической фабрике `[HandlerStack::class, 'create']` — или `null`; иначе запись в compiler log; разбор — `constructorConfig()` + `profileThroughConfig()`), configurator `addHandler`, иначе compiler log |

- Все три адаптера получают фабрику по `Reference(DetachedSpanFactoryInterface::class)` — один алиас.
  Декоратор фабрики должен декорировать сервис `ProfilingFactory` и реализовать `DetachedSpanFactoryInterface`
  (тест `AutoSpansKernelTest::testADecoratedFactoryReceivesTheAutomaticSpans`, оба варианта).
- Имя и контекст HTTP span — `Infrastructure/OutgoingRequestSpan` (`message()`, `context()`: `url_path` с
  заменой UUID/чисел/длинного hex на `:uuid`/`:id`/`:hash`, правила metrics-bundle `PathSanitizer`, скопированы;
  плюс `AUTOMATIC`).
- `QuerySpans` — вложенный span (`createSpan`); сбой `label()`/`createSpan()` → запрос без span; `endSpan()` в
  `finally` обёрнут в try/catch (не подменяет результат/исключение запроса); исключение →
  `['exception' => get_debug_type($e)]` (класс драйвера, до конвертера DBAL) и rethrow. Fetch строк в span не входит.
- `ProfiledResponse` закрывает span (флаг `ended`, один `endSpan()`): конец тела / ошибка (`exception` + `status`,
  если `http_code` > 0) / `cancel()` (`cancelled` без статуса или `body_cancelled` со статусом — так заканчиваются
  попытки `RetryableHttpClient`) / закрытие потока `toStream()` (`stream_closed`; оба callback фильтра сначала зовут `failIfTransportError()` —
  обрыв посреди тела даёт `exception`) / `__destruct` (`response_destroyed`).
  `ProfilingHttpClient::request()`: исключение внутреннего `request()` → span с `exception` и rethrow; ошибка самой
  фабрики → ответ без обёртки.
- Guzzle: span закрывается в `then()` промиса: fulfilled → `status` (если это `ResponseInterface`), rejected →
  `exception` (+ `status`, если у причины есть `getResponse()`); синхронное исключение handler'а → `exception` и
  rethrow; ошибка фабрики → запрос без span.

## Подключение

- `ProfilingBundle` (`AbstractBundle`, alias `msstc4symfony_profiling`): дерево конфига →
  параметры `msstc4symfony_profiling.{routes,commands,messages}.whitelist`,
  `msstc4symfony_profiling.spans.{whitelist,blacklist}`.
- `services.php`: `load()` всего namespace (кроме `ProfilingBundle.php`, `Resources/`, `Infrastructure/`,
  `DependencyInjection/` и `MessageEventListener` без `symfony/messenger`), autowire + autoconfigure. Алиас
  `DetachedSpanFactoryInterface` → `ProfilingFactory` (приватный). `kernel.reset` —
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

С 2.0 — канонический шаблон bundle-standard v3: `Application` (ядро: фабрика, спаны, ассемблеры, decision
makers, процессоры; бывший `Framework/` — это не точки расширения Symfony) ни от чего; `Presentation`
(`Presentation/EventListener/*`, бывший `EventListener/`) → `Application`. С 2.2 добавлены (возвращены из
канонического ruleset): `Infrastructure` → `Application`; `DependencyInjection` → `Application`, `Infrastructure`;
`ProfilingBundle` (BundleRoot) → `DependencyInjection` (не `Infrastructure`: поэтому Guzzle middleware
регистрирует `RegisterGuzzleMiddlewarePass`, а не `loadExtension()`). Все новые классы `Infrastructure/` и `DependencyInjection/` — `@internal`. Публичный API — раздел README «Public API»; `@internal`: listener'ы, `ListBasedDecisionMaker`,
`LoggerProcessor`. Мосты (`metrics-bridge-profiling`, `profiling-bridge-logic`) в тестах создают
`ProfilingFactory` и `SpanAssembler` напрямую — поэтому оба публичные.

## Не-final классы

- `Application\Span\AbstractSpan` — `abstract`, точка расширения для собственных span (наследник
  реализует `isRecorded()`). Его состояние `private`, `end()` / `endAt()` / `getEndedAt()` —
  `final`: фабрика закрывает детей через `endAt()`, переопределение пропустило бы handlers.

Все остальные конкретные классы `src/` — `final` / `final readonly`.
