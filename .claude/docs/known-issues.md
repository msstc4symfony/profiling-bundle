# Известные проблемы и история

## Исправлено в 1.0.0

- `RequestEventListener` подписывал `onTerminate` дважды и никогда `onTerminateEnd` —
  `endAll()` после HTTP не вызывался, span копились в воркерах.
- `NullSpan` без `startTime` → `getStartTime()` падал в `LoggerProcessor`; теперь
  отклонённые span процессоры вообще не видят (`isRecorded()`).
- Фабрика без assemblers — неопределённая переменная; теперь fallback на `NullSpan`.
- `endAll()`: `array_reverse` перенумеровывал ключи, `unset` бил мимо.
- Вложенные span снимались со стека без `end()` — процессоры их не видели.

- Ревью 2026-10-01 (UTC): реентерабельный `end()` предка из процессора обрабатывал span
  дважды и не по порядку; исключение процессора уходило в прикладной код и оставляло стек
  полузакрытым; `endTop()` по счётчику терял span, открытый процессором; обёртка из
  create-процессора не обрабатывалась; длительность считалась в момент обработки
  (`microtime`). Всё — очередь + стек до процессоров + идемпотентный `end()` с `hrtime`.
- Конфиг: `array_all($v, is_string(...))` падает — `array_all` передаёт и ключ, а
  `is_string` принимает ровно один аргумент (ArgumentCountError). Только замыкание.

## Текущие особенности

- До 1.1.0: команды-воркеры в `commands` (`messenger:consume`) — `kernel.reset` после первого
  сообщения закрывал span команды (`ProfilingFactory::reset()` = `endAll()`, а
  `ConsoleEventListener::reset()` забывал span). С 1.1.0 span команды помечается
  `keepOpenOnReset()`, `ConsoleEventListener::reset()` ничего не делает. С 1.1.1 метод — в
  отдельном `Framework\SpanKeeperInterface` (в `ProfilingFactoryInterface` нельзя — BC break):
  listener проверяет `instanceof SpanKeeperInterface`. Декоратор фабрики, который его не
  реализует, получает поведение 1.0.0 (span команды закрывается на первом `kernel.reset`).
- 1.1.1: `reset()` пропускает помеченный span, если он уже закрыт без фабрики (снят её end
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
- С 1.1.0 (bundle-standard v1.8.0) `symfony/service-contracts: ^2.5|^3` объявлен в `require`,
  PHPStan — `level: 10` (раньше верификатор разрешал только `^6.4|^7.0|^8.0` и `level: 9`).
- `--prefer-lowest` (PHP 8.4, Symfony 6.4.0): виновник risky «did not remove its own exception
  handlers» — транзитивный `symfony/error-handler` < 6.4.44 (исправлен в 6.4.44:
  `ErrorHandler::register()` снимает свой exception handler, если ставил его поверх чужого).
  Поднимать нижнюю границу не стали — пакет не прямая зависимость, а проблема только в тестах;
  `KernelProfilingTest::tearDown()` восстанавливает стек handler'ов. Найдено бисекцией
  2026-10-02 UTC. На messenger 6.4.0 + PHP 8.4 в stderr сыплются deprecation «Implicitly
  marking parameter as nullable» из vendor — это не падение.
- `final`: все конкретные классы `src/` уже `final`; `AbstractSpan` — точка расширения. На 2.0:
  `AbstractSpan::endAt()`/`getEndedAt()` (`@internal`, но public и не final — Roave считает
  final/сужение видимости BC break), `protected` свойства `AbstractSpan` → `private`,
  `keepOpenOnReset()` → в `ProfilingFactoryInterface` (вместо `SpanKeeperInterface`), убрать no-op `ConsoleEventListener::reset()`
  вместе с `ResetInterface`.
- Повторный `end()` у span безвреден: его уже нет в стеке, процессоры второй раз не идут.
- В тестах ядра второй `handle()` сбрасывает `TestHandler` (см. `testing.md`).

- Guard в `services.php` (`class_exists(Worker::class)` → exclude `MessageEventListener`) не
  нужен для загрузки ядра: без него контейнер тоже собирается (`#[AsEventListener]` с
  несуществующим классом события просто регистрирует listener на строковое имя). Guard лишь
  не держит в контейнере listener, чьи события никогда не придут. Проверено вручную в
  минимальной установке (2026-10-01 UTC).

- Ревью v1.0.0..v1.1.0 (2026-10-02 UTC), отклонено:
  - «`symfony/service-contracts: ^2.5|^3` — ветка `^2.5` недостижима»: неверно.
    `symfony/dependency-injection` 6.4.0 требует `^2.5|^3.0`; `--prefer-lowest` на PHP 8.4 +
    Symfony 6.4.* ставит `service-contracts` 2.5.0, тесты зелёные. Сужение до `^3` не нужно.
  - Пустой `ConsoleEventListener::reset()` с `ResetInterface` — оставлен до 2.0 (убрать
    интерфейс у класса — BC break), см. список на 2.0 выше.
  - `#[RequiresMethod(MonologBundle::class, 'build')]` / `(Worker::class, 'run')` оставлены:
    в PHPUnit нет атрибута «нужен класс», атрибут декларативен и срабатывает до `setUp()`;
    пояснение — в docblock `KernelProfilingTest`.
