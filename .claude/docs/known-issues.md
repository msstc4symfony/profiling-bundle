# Известные проблемы и история

## Типичные ошибки (не повторять)

- `RequestEventListener` подписывает `onTerminate` и `onTerminateEnd`: без `onTerminateEnd`
  `endAll()` после HTTP не вызывается, и span копятся в воркерах.
- Отклонённые span процессоры не видят (`isRecorded()`): у `NullSpan` нет `startTime`, и
  `getStartTime()` упал бы в `LoggerProcessor`.
- Фабрика без assemblers отдаёт `NullSpan`.
- Обход стека в обратном порядке: `array_reverse` перенумеровывает ключи — `unset` по ним бьёт мимо.
- Вложенные span снимаются со стека только через `end()` — иначе процессоры их не видят.

- Ревью 2026-10-01 (UTC): реентерабельный `end()` предка из процессора обрабатывал span
  дважды и не по порядку; исключение процессора уходило в прикладной код и оставляло стек
  полузакрытым; `endTop()` по счётчику терял span, открытый процессором; обёртка из
  create-процессора не обрабатывалась; длительность считалась в момент обработки
  (`microtime`). Всё — очередь + стек до процессоров + идемпотентный `end()` с `hrtime`.
- Конфиг: `array_all($v, is_string(...))` падает — `array_all` передаёт и ключ, а
  `is_string` принимает ровно один аргумент (ArgumentCountError). Только замыкание.

## Текущие особенности

- Команды-воркеры в `commands` (`messenger:consume`): `kernel.reset` идёт после каждого
  сообщения, поэтому span команды помечается `ProfilingFactoryInterface::keepOpenOnReset()`.
  Декоратор фабрики обязан делегировать этот метод, иначе span команды закроется на первом
  `kernel.reset`.
- `reset()` пропускает помеченный span, если он уже закрыт без фабрики (снят её end
  handler) — иначе «мёртвая» метка навсегда держала открытыми span под ней.
- Ревью 2 (2026-10-01 UTC): один span в `MessageEventListener` путал исходы батчей
  (Received A, Received B, Failed A приписывалось B) — теперь span закрывается только событием
  своего сообщения, незакрытый — на следующем Received с `message_acknowledged: false`; listener на
  Received с priority -1024, чтобы veto (`shouldHandle(false)`) уже были выставлены.
  Бросающий пользовательский handler ребёнка обрывал `onEnd` и терял span — теперь
  `AbstractSpan::endAt()` вызывает все handlers и бросает первое исключение в конце, а
  фабрика закрывает детей в try/catch и обрабатывает очередь в `finally`. Дети закрываются
  временем родителя (`endAt`) — иначе медленный handler внука удлинял ребёнка сверх родителя.
- Ревью 3 (2026-10-01 UTC): `endAll()` и listener'ы звали `end()` напрямую — исключение
  handler'а (например, повешенного create-процессором) уходило в `services_resetter` и роняло
  worker, а стек оставался полуоткрытым. Теперь всё «фреймворковое» закрытие идёт через
  `ProfilingFactoryInterface::endSpan()` (лог вместо исключения). Span батча закрывается на
  `WorkerRunningEvent` (priority 0, раньше `kernel.reset` на -1024): иначе reset сначала
  обнулял listener, и `message_acknowledged: false` не доходил. `AbstractSpan::end()` final — фабрика
  закрывает детей через `endAt()`, override `end()` пропускался бы.
- `ResetServicesListener` не подписан в контейнере: `messenger:consume` добавляет его в
  диспетчер во время работы. Тест порядка сравнивает наш приоритет с
  `ResetServicesListener::getSubscribedEvents()` установленной версии Symfony.
- Ревью 4: тест порядка decision makers без `LateDecisionMaker` (-2048) проходил и без
  `#[AsTaggedItem]` — сервисы приложения регистрируются раньше сервисов бандла.
- Тест приоритета listener'а: callable брать из `getListeners()`, а не из
  `test.service_container` — для приватного listener'а это может быть другой экземпляр, и
  `getListenerPriority()` вернёт `null` (тест флакал).
- Symfony 8.1 объявил устаревшим `defaultPriorityMethod`/`getDefaultPriority()` для tagged
  iterators — приоритеты только через `#[AsTaggedItem]`.
- `symfony/service-contracts: ^3` объявлен в `require`, PHPStan — `level: 10`.
- Повторный `end()` у span безвреден: его уже нет в стеке, процессоры второй раз не идут.
- В тестах ядра второй `handle()` сбрасывает `TestHandler` (см. `testing.md`).

- Guard в `services.php` (`class_exists(Worker::class)` → exclude `MessageEventListener`) не
  нужен для загрузки ядра: без него контейнер тоже собирается (`#[AsEventListener]` с
  несуществующим классом события просто регистрирует listener на строковое имя). Guard лишь
  не держит в контейнере listener, чьи события никогда не придут. Проверено вручную в
  минимальной установке (2026-10-01 UTC).

- Ревью удержания span команды на `kernel.reset` (2026-10-02 UTC), отклонено:
  - `#[RequiresMethod(MonologBundle::class, 'build')]` / `(Worker::class, 'run')` оставлены:
    в PHPUnit нет атрибута «нужен класс», атрибут декларативен и срабатывает до `setUp()`;
    пояснение — в docblock `KernelProfilingTest`.
- **Код должен разбираться на PHP 8.2** (с 2026-10-14 UTC, bundle-standard v2.0.0, профиль `php82`): без
  типизированных констант (`const string X`), без анонимных `new readonly class {}` (оба — 8.3;
  именованный `readonly class` — 8.2, можно) и без `new X()->m()` / `new X()(...)` (8.4) — только
  `(new X())->m()`. PHPStan (`phpVersion.min: 80200`) ловит типизированные
  константы; остальное ловят только `php -l` в job «minimal» (PHP 8.2) и ячейки PHPUnit на 8.2. Шаблоны Rector
  (`withPhpVersion(PHP_82)`) и CS-Fixer (`new_expression_parentheses`) синтаксис обратно не поднимают.
  `#[Override]` (8.3) допустим: на 8.2 атрибут не инстанцируется. `array_any/all/find` (8.4) — только с
  `symfony/polyfill-php84` в `require`. Symfony 8 ставится только на PHP 8.4+.
  `composer-ci.lock` собран на PHP 8.4 (Symfony 8): на 8.2/8.3 локально — `COMPOSER=composer-ci.json composer update`
  (или `--prefer-lowest`, как ячейка CI), не `install`.

## Обёртки span'ов теряли время конца родителя (2.1.0, 2026-10-22 UTC)

`ProfilingFactory::onEnd()` закрывал детей временем конца родителя только при `instanceof AbstractSpan`. Обёртка,
которую возвращает create-процессор (`TracedSpan` из tracing-bridge-profiling), не наследует `AbstractSpan` (`end()`
/`endAt()` final) — её дети и она сама закрывались «сейчас», ребёнок переживал родителя на время handler'ов
(замер ревью: +32 мкс). Теперь фабрика проверяет `EndableAtInterface`; обёртка реализует его делегированием
(`tests/Unit/Fixture/DecoratingSpan`, тест `testWrappedChildrenEndAtTheirWrappedParentsEndTime`). Метод в
`SpanInterface` не добавлен — это BC break для реализаций (Roave). `TracedSpan` (tracing-bridge-profiling) реализует
интерфейс делегированием — без этого исправление в связке не работает; мост требует profiling ^2.1.

## Автоматические span (2.2.0, 2026-10-22 UTC)

- `symfony/dependency-injection` 7.4.0 при resource-скане грузит каждый класс и падает на отсутствующем интерфейсе
  (известно по metrics-bundle). Поэтому `Infrastructure/` и `DependencyInjection/` исключены из скана целиком, всё
  регистрируют пассы. Проверка: job «PHPUnit without optional libraries» — `AutoSpansKernelTest::testTheKernelBoots…`.
- `ProfileDoctrineDbalPass` обязан идти до `MiddlewaresPass` DoctrineBundle (priority 0): priority 1. Проверено:
  с priority 0 вариант `profiling_first` теряет span. Соединения — `ChildDefinition` без класса, список берётся из
  параметра `doctrine.connections`. `MiddlewaresPass` сортирует по убыванию priority тега и применяет по порядку:
  первый — самый внутренний; наш -100 — снаружи middleware с приоритетом 0 (metrics-bundle).
- Пасс на BEFORE_REMOVING не может ссылаться ни на приватный алиас (`DetachedSpanFactoryInterface` уже удалён),
  ни на id `ProfilingFactory` (при декорации приложения он сам становится приватным алиасом и удаляется
  `RemovePrivateAliasesPass` → ServiceNotFoundException; декорация алиаса `ProfilingFactoryInterface` молча
  обходилась). Именованные аргументы там тоже уже разрешены (в дамп попал бы `'$profiling'` → ParseError). Решение
  ревью: middleware регистрирует `RegisterGuzzleMiddlewarePass` до оптимизации, BEFORE_REMOVING только ссылается
  на его id.
- `php_user_filter::filter()` НЕ получает `$closing` при `fclose()` недочитанного потока (проверено ревью на
  8.4.17: fread 1 байт + fclose → 0 вызовов). Закрытие ловит `onClose()` → `stream_closed`; конец тела — `$closing`
  в `filter()`. metrics-bundle `CompletionStreamFilter` имеет тот же дефект (переносит координатор).
- `ErrorChunk::isTimeout()`/`isFirst()`/`isLast()` выставляют `didThrow` — трогать error-чанк в `stream()` нельзя.
  Транспортная ошибка в потоке — по `getInfo('error')` ответа (у таймаута его нет) → `TransportException::class`.
- `MockResponse` с `error` всё равно отдаёт `http_code` 200; у настоящего транспорта `http_code` = 0, `status` нет.
- Пустая строка в теле `MockResponse` = idle timeout чанк (так тест таймаута гарантирует, что таймаут был).
- `RetryableHttpClient` (`retry_failed`) стоит над транспортом: каждая попытка — свой span, отброшенная попытка
  закрывается через `cancel()` после заголовков → `body_cancelled` + `status` (раньше было `cancelled` + 503).
  Ответы `caching` до транспорта не доходят — span нет. Тест: `AutoSpansKernelTest::testEachRetryAttemptGetsItsOwnSpan`.
- Деструктор `ProfiledResponse` вызывает внутренний `__destruct()`: непрочитанный 4xx/5xx бросает из деструктора —
  span закрывается с `exception` + `status`, исключение пробрасывается. PHPStan считает `unset()` нечем бросить
  (dead catch) — в тесте ответ уничтожается возвратом из замыкания.
- Guzzle 8: `getResponse()` только у `ResponseException`, в 7 — у `RequestException` (nullable) → `method_exists`.
- `%env()%` в `auto_spans.*` проходит BooleanNode как строка-плейсхолдер и раньше молча выключал интеграцию; теперь
  `loadExtension()` бросает `InvalidConfigurationException` для не-bool. Замыкание `ifTrue()` принимает `mixed`:
  код Symfony без strict_types привёл бы плейсхолдер к `true`.
- `msstc4symfony/sql-labeller` — `suggest`, не `require` (ревью): иначе каждому потребителю нужен vcs-репозиторий.
  Требуется для `dbal` (`AutoSpan::DBAL->packages()`); unit-тесты DBAL помечены `#[RequiresMethod(SqlLabeller::class, 'label')]`.
- prefer-lowest (2026-10-22 UTC, PHP 8.4, копия репо без roave/deptrac/infection/rector/cs-fixer): DBAL 3.7.0,
  DoctrineBundle 2.13.0, Symfony 7.4.0, http-client 7.4.0, Guzzle 7.15.2 (ниже резолвер не опустил) — 253 теста
  зелёные, 2 skipped (только Guzzle 8). На DBAL 3 `Driver\Connection::exec()` объявлен `int` — тест exec с
  `'3'` ветвится по типу возврата.
- Эквивалентные мутанты (Infection, unit-набор): `Statement::execute()` — `...$arguments` на DBAL 4 отбрасывает
  `AbstractStatementMiddleware::execute()`; `CompletionStreamFilter::filter()` — мутанты `$consumed` (счётчик ни на
  что не влияет, `PSFS_PASS_ON`). Остальные выжившие — код до 2.2.
- `ProfilingBundle` покрыт unit-тестом `tests/Unit/ProfilingBundleTest` (там же проверки старого дерева конфига).
  Загрузку расширения (`services.php`) unit-тест делать не может: risky под coverage →
  `tests/Integration/AutoSpansExtensionTest`.
- `composer-ci.lock` в git, CI ставит из него. Добавлять новые пакеты только так (иначе `--with-dependencies`
  поднимает уже залоченные зависимости):
  `COMPOSER=composer-ci.json composer update <новые пакеты> --with-dependencies --minimal-changes --ignore-platform-req=php`
  (вне sandbox: кеш Composer). Для локальной работы с `../sql-labeller` — git-ignored `composer-local.json`.
- Ревью 2 (2026-10-23 UTC), исправлено: `QuerySpans` ронял запрос при сбое labeller'а/декоратора фабрики;
  отвязанный span, открытый во время закрытия стека в `endAll()`/`reset()`, выживал; AUTOMATIC-span из decision
  maker'а рекурсивно вызывал decision maker (→ счётчик `$opening`); процессоры видели открытых братьев, если handler
  ребёнка закрывал другой span (→ `$settling`); обрыв транспорта посреди `toStream()` закрывал span как
  `stream_closed` без `exception`; `addHandler()` бросал `LogicException` при создании сервиса (теперь no-op).
- `HandlerStack` в Guzzle помечен `@final` (PHPStan `class.extendsFinalByPhpDoc`): подклассы не поддерживаются,
  `ProfileGuzzlePass` сравнивает класс строго.
- Двойной `__destruct()` (наш вызов внутреннего + собственный деструктор внутреннего ответа, как в Symfony
  `TraceableResponse`) у `NativeResponse`/`AmpResponse` дважды уменьшает счётчик активных ответов — только раньше
  чистится DNS-кеш, на корректность не влияет. Код оставлен.
- `ProfileGuzzlePass` видит только сервисы-клиенты: клиент, объявленный inline (`inline_service()`) аргументом
  другого сервиса, не профилируется (README).
- Эквивалентные мутанты ревью 2: `clone` в цикле `endOpenSpans()` (без клона пропущенный при изменении во время
  обхода span закроется в следующем раунде); `failIfTransportError()` в EOF-callback фильтра (на 8.4 при обрыве
  `filter()` не получает `$closing`, отчитывается только `onClose()` — вызов оставлен на случай другого поведения
  потоков); в `ProfileGuzzlePass::isHandlerStack()` — `$factory[0]`/`&&` для фабрики-строки (`setFactory('a::b')`
  превращается в массив, имя функции классом не станет).
- Ревью 3 (2026-10-23 UTC), исправлено: `MAX_END_PASSES` ограничивал только отвязанные span — процессор, открывающий
  стековый span на каждый обработанный, вешал `endAll()`/`reset()` (>1000 итераций); теперь раунд закрывает снимок
  стека, ограничение общее. Сбой `endSpan()` декоратора фабрики больше не превращает fulfilled-промис Guzzle в
  rejection, не подменяет исключение handler'а и не вылетает из `fread()`/`fclose()`/деструктора `ProfiledResponse`;
  `getInfo('error')` защищён.
- Guzzle 8 отвергает опции `max_host_connections`, `max_total_connections`, `transport_sharing`, `multiplex` рядом с
  заданным `handler` (`InvalidArgumentException` в конструкторе клиента; нашёл metrics-bundle 2.2). Клиент с ними и
  без `handler` `ProfileGuzzlePass` не переписывает, а ведёт через configurator `addHandler()` — стек Guzzle строит
  сам. Тесты: `ProfileGuzzlePassTest::testConfiguresAClientWhoseDefaultHandlerOptionsForbidAHandler`,
  `AutoSpansKernelTest::testAGuzzleClientThatBuildsItsOwnHandlerIsProfiledThroughItsConfig` (без фикса падает).

## Подавленный automatic span проходил через create-процессоры (2.2.0, CI 2026-10-23 UTC)

Guard `AUTOMATIC` делал `NullSpan`, но отдавал его create-процессорам; процессор, который сам открывает automatic
span (тест `testAnAutomaticSpanOpenedByACreateProcessorIsNotRecorded`), уходил в бесконечную рекурсию. Локально на
PHP 8.4 тест проходил, в CI на PHP 8.2 — segfault/exhausted memory (ячейки 8.2 и job без опциональных библиотек).
Теперь подавленный span возвращается сразу, без assemble и процессоров. Воспроизведение: Docker `php:8.2-cli` с
`COMPOSER_AUTH` (vcs sql-labeller).

## HttpClient span opens before the request (2.3.1, 2026-10-27 UTC)

- 2.3.0 opened the `http_client` span after `inner->request()` (named from `getInfo('url')`); tracing-bridge-profiling
  1.3 reserves the trace span id in a create processor and tracing-bundle's decorator (−15, inside) takes it while the
  request passes — so the span must exist first. Now `RequestedUrl::resolve()` (HttpClientTrait via
  `SymfonyUrlResolver`) names it from the requested URL + base_uri (request option incl. `null`/parts → `withOptions()`
  → `$defaultOptions['base_uri']` of a custom transport, read with `findDefinition()` so an aliased transport works).
- `framework.http_client.default_options` has no `base_uri` key; scoped clients / Retryable / UriTemplate resolve
  before the transport. Behind `mock_response_factory` (MockHttpClient at −10, inside) a relative URL is `http GET
  unknown` — test-only, documented in CHANGELOG.
- A compiler pass that copies a resolved value into a service argument must keep `%%` escaped (`resolveValue()` only,
  no `unescapeValue()`): `ResolveParameterPlaceHoldersPass`/`PhpDumper` unescape the argument again, so an unescaped
  `https://x/%41%42/` reads `%41%` as parameter "41" and the container fails to compile (re-review 2026-10-27 UTC).

## Infection падал на risky-тестах (2026-11-27 UTC)

- CI main был красным с 2026-10-27 (7d870c7): Infection прерывается на первом risky-тесте («Project tests must be in a
  passing state»), а локальный `make test` без покрытия risky не показывает. `ProfilingHttpClientTest` задевал
  `RequestedUrl` и `SymfonyUrlResolver`, `ProfileHttpClientPassTest` — `ProfilingHttpClient` без `#[UsesClass]`.
  Проверка перед push: `XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-text=/dev/null` — Risky: 0.
