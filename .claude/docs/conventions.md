# Соглашения

- Пользовательская настройка — только через дерево `msstc4symfony_profiling`; параметры
  `msstc4symfony_profiling.*` — внутренний механизм, инжектятся `#[Autowire(param: ...)]`.
- Сообщения встроенных span: `request <route>`, `cli command <name>`, `message <FQCN>`.
  Decision makers сравнивают по префиксу — новые встроенные span называть `<вид> <деталь>`.
  Сообщение = метка Prometheus в metrics → никаких id в сообщении.
- Новый extension point — интерфейс с `#[AutoconfigureTag]` + `#[AutowireIterator]` в фабрике;
  порядок — `getDefaultPriority()` (Symfony ищет его по умолчанию) или `priority` тега.
- Всё, что держит состояние между запросами, — `ResetInterface`.
- Ничто из профилирования не бросает в прикладной код (процессоры в try/catch).
