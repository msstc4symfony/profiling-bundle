# CI

`.github/workflows/checks.yml` → `bundle-standard/.github/workflows/php-bundle.yml@v1.0.0`
(PHP 8.4/8.5 × Symfony 7.4/8.x + ячейка `--prefer-lowest` на PHP 8.4 / Symfony 7.4,
минимальная установка без опциональных пакетов, PHPStan, CS-Fixer, Rector, deptrac, audit
и Infection; Roave BC check включён — дефолт стандарта). Пороги Infection в `checks.yml`:
`infection-min-msi: 82`, `infection-min-covered-msi: 82` (замер 2026-10-02 UTC — 87.5; держать ~4 пункта запаса и поднимать вместе с MSI). Codecov выключен. Менять гейт — в `bundle-standard`.
