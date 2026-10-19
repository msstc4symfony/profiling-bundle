# Соглашения

- Раскладка и правила семейства — спек этапа B (`docs/superpowers/specs/2026-10-17-stage-b-conventions-design.md`
  в корне msstc4symfony): канонические слои, `@internal` вне Public API (только в мажорах), `#[\Override]` везде.
- Пути в `src/Resources/config/services.php` (`exclude`) — строки, перенос класса их не обновляет:
  `tests/Unit/Resources/ServicesConfigTest` требует, чтобы каждый путь находил файл.
- Пользовательская настройка — только через дерево `msstc4symfony_profiling`; параметры
  `msstc4symfony_profiling.*` — внутренний механизм, инжектятся `#[Autowire(param: ...)]`.
- Сообщения встроенных span: `request <route>`, `cli command <name>`, `message <FQCN>`.
  Decision makers сравнивают по префиксу — новые встроенные span называть `<вид> <деталь>`.
  Сообщение = метка Prometheus в metrics → никаких id в сообщении.
- Новый extension point — интерфейс с `#[AutoconfigureTag]` + `#[AutowireIterator]` в фабрике;
  порядок — `#[AsTaggedItem(priority: ...)]` (не `getDefaultPriority()`: deprecated в 8.1).
- Всё, что держит состояние между запросами, — `ResetInterface`.
- Ничто из профилирования не бросает в прикладной код: assemblers, decision makers, процессоры
  и неявно вызванные handlers — в try/catch с логом. Исключение — handler, который приложение
  само повесило на span, который само закрывает.
