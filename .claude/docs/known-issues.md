# Известные проблемы и история

## Типичные ошибки (не повторять)

- `RequestEventListener` подписывает `onTerminate` и `onTerminateEnd`: без `onTerminateEnd`
  `endAll()` после HTTP не вызывается, и span копятся в воркерах.
- Отклонённые span процессоры не видят (`isRecorded()`): у `NullSpan` нет `startTime`, и
  `getStartTime()` упал бы в `LoggerProcessor`.
- Фабрика без assemblers отдаёт `NullSpan`.
- Обход стека в обратном порядке: `array_reverse` перенумеровывает ключи — `unset` по ним бьёт мимо.
- Вложенные span снимаются со стека только через `end()` — иначе процессоры их не видят.

- Ревью 2026-10-01 (UTC): реентерабельный `end()` предка из процессора обрабатывал span
  дважды и не по порядку; исключение процессора уходило в прикладной код и оставляло стек
  полузакрытым; `endTop()` по счётчику терял span, открытый процессором; обёртка из
  create-процессора не обрабатывалась; длительность считалась в момент обработки
  (`microtime`). Всё — очередь + стек до процессоров + идемпотентный `end()` с `hrtime`.
- Конфиг: `array_all($v, is_string(...))` падает — `array_all` передаёт и ключ, а
  `is_string` принимает ровно один аргумент (ArgumentCountError). Только замыкание.

## Текущие особенности

- Команды-воркеры в `commands` (`messenger:consume`): `kernel.reset` идёт после каждого
  сообщения, поэтому span команды помечается `ProfilingFactoryInterface::keepOpenOnReset()`.
  Декоратор фабрики обязан делегировать этот метод, иначе span команды закроется на первом
  `kernel.reset`.
- `reset()` пропускает помеченный span, если он уже закрыт без фабрики (снят её end
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
- `symfony/service-contracts: ^3` объявлен в `require`, PHPStan — `level: 10`.
- Повторный `end()` у span безвреден: его уже нет в стеке, процессоры второй раз не идут.
- В тестах ядра второй `handle()` сбрасывает `TestHandler` (см. `testing.md`).

- Guard в `services.php` (`class_exists(Worker::class)` → exclude `MessageEventListener`) не
  нужен для загрузки ядра: без него контейнер тоже собирается (`#[AsEventListener]` с
  несуществующим классом события просто регистрирует listener на строковое имя). Guard лишь
  не держит в контейнере listener, чьи события никогда не придут. Проверено вручную в
  минимальной установке (2026-10-01 UTC).

- Ревью удержания span команды на `kernel.reset` (2026-10-02 UTC), отклонено:
  - `#[RequiresMethod(MonologBundle::class, 'build')]` / `(Worker::class, 'run')` оставлены:
    в PHPUnit нет атрибута «нужен класс», атрибут декларативен и срабатывает до `setUp()`;
    пояснение — в docblock `KernelProfilingTest`.
