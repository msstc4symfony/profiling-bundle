# Соглашения

- Раскладка и правила семейства — спек этапа B (`docs/superpowers/specs/2026-10-17-stage-b-conventions-design.md`
  в корне msstc4symfony): канонические слои, `@internal` вне Public API (только в мажорах), `#[\Override]` везде.
- Пути в `src/Resources/config/services.php` (`exclude`) — строки, перенос класса их не обновляет:
  `tests/Unit/Resources/ServicesConfigTest` требует, чтобы каждый путь находил файл.
- Пользовательская настройка — только через дерево `msstc4symfony_profiling`; параметры
  `msstc4symfony_profiling.*` — внутренний механизм, инжектятся `#[Autowire(param: ...)]`.
- Сообщения встроенных span: `request <route>`, `cli command <name>`, `message <FQCN>`, с 2.2 —
  `sql <select|insert|update|delete|other> <table|unknown>`, `http <METHOD> <host|unknown>`. Путь URL — только в
  контексте (`url_path`, без query), id — никогда в сообщении.
  Decision makers сравнивают по префиксу — новые встроенные span называть `<вид> <деталь>`.
  Сообщение = метка Prometheus в metrics → никаких id в сообщении.
- Новый extension point — интерфейс с `#[AutoconfigureTag]` + `#[AutowireIterator]` в фабрике;
  порядок — `#[AsTaggedItem(priority: ...)]` (не `getDefaultPriority()`: deprecated в 8.1).
- Всё, что держит состояние между запросами, — `ResetInterface`.
- Ничто из профилирования не бросает в прикладной код: assemblers, decision makers, процессоры
  и неявно вызванные handlers — в try/catch с логом. Исключение — handler, который приложение
  само повесило на span, который само закрывает.
- Работа, завершающаяся вне вложенности стека (асинхронный HTTP), — отвязанный span (`createDetachedSpan()`), не
  `createSpan()`: иначе ответ, дочитанный позже, «закрыл» бы всё, что открыто после запроса. Адаптеры закрывают span
  только через `endSpan()` и никогда не бросают из обработки span (span не создался → отдать результат как есть).
- Приоритет декорации `http_client.transport`: -25 (реестр семейства — `bundle-standard/doc/decoration-priorities.md`:
  mock -10, tracing -15, metrics -20); от порядка относительно других декораторов не зависит.
- Пути URL в контексте — только через `OutgoingRequestSpan::context()` (замена id).
- Пасс на `TYPE_BEFORE_REMOVING` не регистрирует сервисы: регистрация — в пассе до оптимизации, позже — только
  ссылки на уже зарегистрированные id (см. known-issues).
- Адаптеры получают фабрику только через `DetachedSpanFactoryInterface` и помечают свои span
  `DetachedSpanFactoryInterface::AUTOMATIC` (защита от обратной связи end-процессоров).
- Ключи авто-интеграций — только из enum `DependencyInjection/AutoSpan`; сообщения ошибок начинаются с полного
  имени опции `msstc4symfony_profiling.auto_spans.<x>`.
- Классы, требующие опциональной библиотеки, — в `Infrastructure/` (исключён из скана) и регистрируются пассами.
