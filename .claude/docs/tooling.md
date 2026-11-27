# Инструментарий

- `symfony/service-contracts: ^3` объявлен явно (`ResetInterface`, `#[Required]`):
  contracts версионируются своей мажорной линией.
- `composer.json` — публикуемый; `composer-ci.json` + `composer-ci.lock` — то же плюс
  MonologBundle и CI-инструменты. Оба ставятся в один `vendor/`; основной профиль — CI.
- `make check` запускать с `COMPOSER=composer-ci.json`.
- PHPStan level 10 на `src/` и `tests/`, baseline пуст — держать пустым.
- Rector: пропущены `FromServicePublicToDefaultsPublicRector` и
  `ServiceSettersToSettersAutodiscoveryRector` — переписывают `services.php`.
- deptrac: шаблон bundle-standard v3, BundleRoot `^Msstc4Symfony\\ProfilingBundle\\ProfilingBundle$`;
  `Application` ни от чего; `Presentation`, `Infrastructure` → `Application`; `DependencyInjection` → `Application`,
  `Infrastructure`; корень бандла → `DependencyInjection` (2.2; только канонические зависимости).
- PHPStan: `checkMissingOverrideMethodAttribute` из шаблона — `#[Override]` на всех переопределениях.
- `msstc4symfony/sql-labeller` — `suggest`; vcs-репозиторий и dev-зависимость только в `composer-ci.json`, который
  ставит для тестов ещё `doctrine/dbal`, `doctrine/doctrine-bundle`, `symfony/http-client`, `guzzlehttp/guzzle`.
  Нижние границы опциональных библиотек — `conflict` в обоих манифестах (dbal <3.7, doctrine-bundle <2.13,
  guzzle <7.8, http-client <7.4).
  `checks.yml` добавляет `pdo_sqlite` в `extensions` (DBAL kernel-тест). Локально против `../sql-labeller` —
  `COMPOSER=composer-local.json` (git-ignored).
- Infection в песочнице: нужен запись в `/tmp` (`env -u TMPDIR`, вне sandbox).
