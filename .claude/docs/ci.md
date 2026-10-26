# CI

`.github/workflows/checks.yml` → `bundle-standard/.github/workflows/php-bundle.yml@v3.0.0`
(PHP 8.2–8.5 × Symfony 7.4/8.x, Symfony 8 — с PHP 8.4+; ячейка `--prefer-lowest` на PHP 8.2 / Symfony 7.4,
минимальная установка без опциональных пакетов, PHPStan, CS-Fixer, Rector, deptrac, audit
и Infection; Roave BC check включён — дефолт стандарта). Пороги Infection в `checks.yml`:
`infection-min-msi: 82`, `infection-min-covered-msi: 82` (замер 2026-10-19 UTC — covered MSI 86; держать ~4 пункта запаса и поднимать вместе с MSI). Менять гейт — в `bundle-standard`.
