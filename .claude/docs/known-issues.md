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

- Команды-воркеры в `commands` (`messenger:consume`): `kernel.reset` после первого
  сообщения закрывает span команды. Для воркеров — опция `messages` (описано в README).
- Ревью 2 (2026-10-01 UTC): один span в `MessageEventListener` путал исходы батчей
  (Received A, Received B, Failed A приписывалось B) — теперь span закрывается только событием
  своего сообщения, незакрытый — на следующем Received с `acknowledged: false`; listener на
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
  обнулял listener, и `acknowledged: false` не доходил. `AbstractSpan::end()` final — фабрика
  закрывает детей через `endAt()`, override `end()` пропускался бы.
- Тест приоритета listener'а: callable брать из `getListeners()`, а не из
  `test.service_container` — для приватного listener'а это может быть другой экземпляр, и
  `getListenerPriority()` вернёт `null` (тест флакал).
- Symfony 8.1 объявил устаревшим `defaultPriorityMethod`/`getDefaultPriority()` для tagged
  iterators — приоритеты только через `#[AsTaggedItem]`.
- `symfony/service-contracts` не объявлен в `require`, хотя используется (`ResetInterface`,
  `#[Required]`): верификатор стандарта требует для всех `symfony/*` `^6.4|^7.0|^8.0`, а
  contracts версионируются `^3`. Приходит через `symfony/dependency-injection`.
- PHPStan `level: 9`, хотя код чист на `max`: верификатор стандарта требует строку `level: 9`.
- Повторный `end()` у span безвреден: его уже нет в стеке, процессоры второй раз не идут.
- В тестах ядра второй `handle()` сбрасывает `TestHandler` (см. `testing.md`).
