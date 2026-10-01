# Соглашения

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
