# Тестирование

- `tests/Unit` — фабрика (вложенность, порядок завершения, reset, NullSpan),
  span, assembler + decision maker, `LoggerProcessor` (Monolog `TestHandler`),
  `NullProfilingFactory` + трейт (фикстура `FactoryOwner`), оба listener'а.
  Фикстура `RecordingEndProcessor` запоминает завершённые span.
- `tests/Integration/KernelProfilingTest` — ядро Framework + Monolog + Profiling (конфиг через
  `extension('msstc4symfony_profiling', ...)`): маршрут и команда из whitelist, «осиротевший»
  корневой span (`OrphanSpanOpener` — контроллер и команда) закрывается на terminate, порядок
  подписок на `kernel.terminate`/`console.terminate`, `services_resetter`, валидация дерева.
  Приватные сервисы — через `test.service_container`.
- Реентерабельность фабрики: `RecordingEndProcessor::$onProcess` открывает/закрывает span из
  процессора; `DecoratingSpan` — обёртка из create-процессора.
- **Один запрос на тест**: второй `kernel->handle()` вызывает `services_resetter`, а
  `TestHandler` реализует `ResettableInterface` и очищает записи.
- Каталог кеша ядра — на процесс (`getmypid()`). Infection гоняет только unit-набор.
- Моки без ожиданий дают notice — `createStub()`; классы, которые тест задевает, но не
  покрывает, — в `#[UsesClass]` (иначе risky под coverage).
