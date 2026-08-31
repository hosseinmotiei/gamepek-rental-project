---
description: Public frontend / Blade view safety rules for GamePek
globs:
  - "resources/views/**/*.blade.php"
alwaysApply: false
---

# Frontend / Blade Safety

- The public UI is Persian, RTL. Preserve this in every change.
- Preserve responsive behavior on desktop and mobile — do not fix one
  breakpoint by breaking another.
- Do not redesign unrelated areas of a page during a focused change.
- Do not remove a feature to "fix" its UI.
- Do not fabricate user-facing business data (e.g. a placeholder rating,
  fake stock count, or made-up ratings) — omit the element instead if
  real data isn't available.
- Do not show fake ratings or other synthetic "social proof."
- Do not trust `data-price` attributes or any frontend-computed total as
  authoritative — the backend recomputes and validates (see
  `.claude/rules/backend-services.md`).
- Prefer escaped Blade output (`{{ }}`).
- Raw `{!! !!}` output requires explicit safety reasoning (e.g. content was
  already passed through `e()`/sanitized) — state the reasoning in the fix.
- Do not manually call `e()` inside a normal `{{ }}` echo unless
  double-escaping is explicitly intended — this is a known source of bugs
  (e.g. broken `target`/`rel` attributes) in this codebase.
- Blade syntax inside inline `<script>` blocks is not automatically an IDE
  parser false positive — inspect the actual rendered HTML/JS output before
  reporting or "fixing" it.
- Add-to-cart and other AJAX requests must match the actual route
  parameters and HTTP verb defined in `routes/web.php` — verify both sides
  when touching either.
- Preserve CSRF token handling on any AJAX/fetch call.
- User-facing errors and success messages must be clear Persian.
- Image loading: lazy-load below-the-fold images; do not lazy-load critical
  above-the-fold hero/main imagery without a specific reason.

- Design tokens (colours, font, base CSS) live only in
  `resources/views/partials/design-tokens.blade.php`, included by every
  layout. Never redefine the palette inline in a page or a second layout.
- There is exactly one catalog card, `partials/product-card.blade.php`. Do
  not fork it for a new section; add a variant.

## Asset note

Do not assume `public/storage` is a Laravel symlink — it is a real,
independent asset directory, and on the production host it is served from
above the Laravel root (see `STORAGE_PUBLIC_PATH` in `config/filesystems.php`
and the storage section of `README.md`).
