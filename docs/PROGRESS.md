# MobiShop — Progress log

One line per completed roadmap task. Newest at the bottom. Format:

```
YYYY-MM-DD (jYYYY-MM-DD) · <phase.section> · what shipped · notable decision (if any)
```

Dates are Gregorian with the Jalali equivalent in parentheses, because the roadmap
is written in English but the business calendar is Jalali.

---

- 2026-08-06 (j1405-05-15) · 0.1 · Laravel 12 skeleton scaffolded around the pre-seeded files; `.gitignore` merged, not replaced.
- 2026-08-06 (j1405-05-15) · 0.3 · Dev stack up: php-fpm 8.4 + nginx + postgres:16 + redis:7 + minio + mailpit, driven by `Makefile`. Decision: Postgres provisions a **non-owner app role** so RLS can never be bypassed by the connecting user (owners and superusers bypass RLS by default).
- 2026-08-06 (j1405-05-15) · 0.2 · Documentation baseline written: ROADMAP (all 12 phases as checkboxes with the 5 decision gates preserved), architecture, testing, deploy, design-system, ADRs 0001–0003, and one spec file per module.
