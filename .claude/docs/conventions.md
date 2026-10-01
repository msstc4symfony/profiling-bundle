# Соглашения

- Параметры — `msstc4symfony_profiling.{routes,commands}.whitelist` (list),
  `msstc4symfony_profiling.spans.{whitelist,blacklist}` (list|null = нет списка);
  инжектятся `#[Autowire(param: ...)]`, дефолты — в `services.php`.
- Сообщения встроенных span: `request <route>`, `cli command <name>`. Decision makers
  сравнивают по префиксу — новые встроенные span называть `<вид> <деталь>`.
- Новый extension point — интерфейс с `#[AutoconfigureTag]` + `#[AutowireIterator]` в фабрике.
- Всё, что держит состояние между запросами, — `ResetInterface`.
