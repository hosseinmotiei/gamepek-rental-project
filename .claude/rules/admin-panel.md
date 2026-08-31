---
description: Admin panel safety rules for GamePek
globs:
  - "app/Http/Controllers/Admin/**/*.php"
  - "resources/views/admin/**/*.blade.php"
alwaysApply: false
---

# Admin Panel Safety

- Build admin list views from the shared components in
  `resources/views/components/admin/` (see `admin/users/index.blade.php` as
  the reference). Do not copy-paste table, filter, badge or empty-state
  markup between views — that is the Store pattern this clone removed.
- Destructive actions use `data-confirm="…"`; feedback uses `adminToast()`.
  Do not reintroduce native `confirm()`/`alert()`.
- Preserve existing permission and role checks (Spatie roles/permissions,
  `EnsureIsAdmin` middleware).
- Admin actions must respect the same business services as public flows —
  do not duplicate partial business logic in an admin controller (see
  `.claude/rules/backend-services.md`).
- Sensitive admin actions (order status change, user block, role or
  credential change, settings change) must write to the Activity Log. When
  rental verification data (identity documents, videos, contracts) arrives,
  every read of it must be logged too.
- Empty datasets must not cause crashes — every list view needs a clean
  empty state.
- Null relationships must be handled safely (`?->`, `@if`) — do not assume a
  related model always exists (products/categories can be soft-deleted).
- This project enables `Model::shouldBeStrict()` outside production:
  accessing a nonexistent attribute or lazy-loading an un-eager-loaded
  relation throws a real exception in local/staging, not just in theory.
  Verify exact model attribute names (e.g. `full_name` not `name`,
  `main_image` not `image_path`) before using them in Blade.
- Every permission checked with `can()` in a controller must actually be
  created in `UserSeeder`. The Store shipped eight that were not, and they
  only appeared to work because of the super_admin `Gate::before` bypass.
- Prefer eager loading (`with()`/`load()`) for relationships rendered in
  admin views over relying on lazy loading.
- Avoid database queries placed directly inside shared Blade layouts
  (e.g. sidebar badges) without considering they run on every page load.
- Admin settings must actually propagate to the public view/helper that
  consumes them — verify the public side reads the setting, not a hardcoded
  literal, when fixing a settings-related bug.

Database/migration concerns are covered by `.claude/rules/database.md`, not
duplicated here.
