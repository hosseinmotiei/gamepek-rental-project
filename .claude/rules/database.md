---
description: Database, migration, and Eloquent model safety rules for GamePek
globs:
  - "database/migrations/**/*.php"
  - "database/seeders/**/*.php"
  - "app/Models/**/*.php"
alwaysApply: false
---

# Database & Model Safety

- Never edit a historical (already-existing) migration once this database
  holds real data. Create a new forward-only migration instead.
- The `users`, `addresses` and `otp_codes` migrations must stay schema-
  compatible with the GamePek Store's: the owner will import existing user
  data into them before launch.
- Report every schema change with its `down()` behaviour and data-loss risk.
- Inspect `cascadeOnDelete`, `nullOnDelete`, `restrictOnDelete`, and
  `SoftDeletes` interactions carefully — a soft-deleted parent can still
  trigger a hard cascade at the database layer.
- Treat order history and historical order data as retention-sensitive.
  Prefer `nullOnDelete()`/`restrictOnDelete()` plus snapshot columns over
  `cascadeOnDelete()` for anything referenced by `order_items`/`orders`.
- Do not use `forceDelete()` as a shortcut to work around a foreign key or
  soft-delete constraint.
- Do not run destructive Artisan DB commands (`migrate:fresh`,
  `migrate:refresh`, `db:wipe`, `db:seed` against non-local data).
- Check for unique constraints and race-condition backstops (e.g. duplicate
  coupon usage, duplicate order numbers) — application-level checks alone are
  not sufficient.
- Do not assume application-level checks replace database integrity
  constraints; add the constraint when the fix calls for it.
- Do not delete or truncate data merely to make a failing check pass.
