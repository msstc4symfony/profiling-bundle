# Тестирование

- `tests/Unit` — фабрика (вложенность, порядок завершения, reset, NullableSpan),
  span, assembler + decision maker, `LoggerProcessor` (Monolog `TestHandler`),
  `NullableProfilingFactory` + трейт (фикстура `FactoryOwner`), оба listener'а.
  Фикстура `RecordingEndProcessor` запоминает завершённые span.
- `tests/Integration/ContainerCompileTest` — ядро Framework + Monolog + Profiling: маршрут из
  whitelist пишет `request ping` в канал `profiling`, другой маршрут — ничего,
  `services_resetter` завершает открытые span.
- **Один запрос на тест**: второй `kernel->handle()` вызывает `services_resetter`, а
  `TestHandler` реализует `ResettableInterface` и очищает записи.
- Каталог кеша ядра — на процесс (`getmypid()`): infection параллелит PHPUnit.
- Моки без ожиданий дают notice — `createStub()`; классы, которые тест задевает, но не
  покрывает, — в `#[UsesClass]` (иначе risky под coverage).
