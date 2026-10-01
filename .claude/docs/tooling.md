# Инструментарий

- `composer.json` — публикуемый; `composer-ci.json` + `composer-ci.lock` — то же плюс
  MonologBundle и CI-инструменты. Оба ставятся в один `vendor/`; основной профиль — CI.
- `make check` запускать с `COMPOSER=composer-ci.json`.
- PHPStan level 9 на `src/` и `tests/`, baseline пуст — держать пустым.
- Rector: пропущены `FromServicePublicToDefaultsPublicRector` и
  `ServiceSettersToSettersAutodiscoveryRector` — переписывают `services.php`.
- deptrac: `Framework` ни от чего; `EventListener` → `Framework`; корень бандла — от всех.
