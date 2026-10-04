# Тестирование

- `tests/Unit` — фабрика (вложенность, порядок завершения, reset, NullSpan),
  span, assembler + decision maker, `LoggerProcessor` (Monolog `TestHandler`),
  `NullProfilingFactory` + трейт (фикстура `FactoryOwner`), оба listener'а.
  Фикстура `RecordingEndProcessor` запоминает завершённые span.
- `tests/Integration/KernelProfilingTest` — ядро Framework + Monolog + Profiling (конфиг через
  `extension('msstc4symfony_profiling', ...)`): маршрут и команда из whitelist, «осиротевший»
  корневой span (`OrphanSpanOpener` — контроллер и команда) закрывается на terminate, порядок
  подписок на `kernel.terminate`/`console.terminate`, `services_resetter`, валидация дерева.
  Приватные сервисы — через `test.service_container`. С messenger ядро поднимает транспорт
  `memory` (`in-memory://`) и `ProfiledMessageHandler`; тест гоняет настоящий
  `messenger:consume memory --limit=1`. Span ловит `test.recorder` (`RecordingEndProcessor`):
  `TestHandler` и `InMemoryTransport` — `ResetInterface`, их чистит каждый `kernel.reset`
  (поэтому сообщение одно — второе reset выбросил бы из очереди).
- `tearDown()` ядра снимает exception handler'ы, оставшиеся сверх сохранённого в `setUp()`:
  на `--prefer-lowest` (Symfony 7.4.0) `FrameworkBundle::boot()` оставляет свой handler поверх
  PHPUnit'ового, и каждый kernel-тест становился risky (`failOnRisky`). Проверено 2026-10-04 UTC:
  подъём `symfony/error-handler` до 7.4.14 не помогает. `composer-ci.json` (только CI) объявляет
  общий для всех бандлов `conflict` `symfony/error-handler: <7.4.17 || >=8.0,<8.1.5`; разворот в
  `tearDown()` остаётся (безвреден).
- Реентерабельность фабрики: `RecordingEndProcessor::$onProcess` открывает/закрывает span из
  процессора; `DecoratingSpan` — обёртка из create-процессора.
- **Один запрос на тест**: второй `kernel->handle()` вызывает `services_resetter`, а
  `TestHandler` реализует `ResettableInterface` и очищает записи.
- Каталог кеша ядра — на процесс (`getmypid()`). Infection гоняет только unit-набор.
- Моки без ожиданий дают notice — `createStub()`; классы, которые тест задевает, но не
  покрывает, — в `#[UsesClass]` (иначе risky под coverage).
- **Опциональные пакеты** (`symfony/messenger`, `symfony/monolog-bundle` — только в
  `composer-ci.json`): CI-джоба «PHPUnit without optional libraries» ставит лишь `composer.json`.
  Тесты, которым они нужны, помечены `#[RequiresMethod(<Class>::class, '<method>')]`
  (`method_exists` на отсутствующем классе даёт `false` → skip, без автозагрузки ошибок):
  `MessageEventListenerTest` целиком (`Envelope::getMessage`), в `KernelProfilingTest` —
  тесты на `TestHandler` (`MonologBundle::build`) и на messenger-listener (`Worker::run`).
  `TestKernel` регистрирует `MonologBundle`, его конфиг и алиас `test.profiling_handler`
  только при `TestKernel::hasMonologBundle()` — остальные kernel-тесты проверяют, что бандл
  загружается без обоих пакетов. Фикстуры с опциональными типами — только в отдельных файлах
  `tests/Unit/Fixture/` (класс на уровне файла теста грузится вместе с тестом).
  Локальная проверка: копия репо без vendor, `rm composer.lock`, `composer update` по
  `composer.json`, затем `vendor/bin/phpunit` → 69 тестов, 16 skipped.
