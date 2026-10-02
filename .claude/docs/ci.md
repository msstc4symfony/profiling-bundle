# CI

`.github/workflows/checks.yml` → `bundle-standard/.github/workflows/php-bundle.yml@v1.8.0`
(PHP 8.4/8.5 × Symfony 6.4/7.4/8.x + ячейка `--prefer-lowest` на PHP 8.4 / Symfony 6.4,
минимальная установка без опциональных пакетов, PHPStan, CS-Fixer, Rector, deptrac, audit,
блокирующие Roave BC check и Infection). Пороги Infection в `checks.yml`:
`infection-min-msi: 82`, `infection-min-covered-msi: 82` (замер 2026-10-02 UTC — 86.6 до
1.1.0, 87.5 после; держать ~4 пункта запаса и поднимать вместе с MSI). Codecov выключен. Менять гейт — в `bundle-standard`.
