# GamePek Rental — Persistent Project Context

This file is loaded every session. Keep it short — details live in the
path-scoped rules under `.claude/rules/` and in `README.md`.

## Identity

- GamePek Rental is the second business area of GamePek: renting game
  consoles and accessories. The Store (`gamepek.com`) is a separate app.
- Persian, RTL. The public user experience is Persian; user-facing errors and
  success messages must be clear Persian.
- Laravel 11 / PHP 8.2. No build step: Tailwind and Vazirmatn load from CDN
  and all JavaScript is inline in Blade. There is no `resources/js`, no
  `package.json`, no Vite.
- Separate application and separate database (`gamepek_rental`) from the
  Store. Never read or write the Store's `gamepek` database from here.

## Relationship to GamePek Store

This codebase was cloned from `gamepek-backend` and pruned to a reusable
foundation. It intentionally shares GamePek's design language, admin shell,
auth stack and commerce spine — but it is a **fork, not a dependency**. There
is no shared package, so a change made here does not reach the Store and
vice-versa.

**The `users`, `addresses` and `otp_codes` schemas are deliberately identical
to the Store's** because the owner will import existing user data before
launch. Do not "improve" those three migrations.

## What is deliberately NOT here

Digital codes, blog, reviews, product questions, wishlist and the GTA VI /
PSN gift-card campaigns are Store features and were not cloned. Do not
reintroduce them by copying from `gamepek-backend`.

## Rental domain — not yet designed

Rental items, owner accounts, availability calendars, reservations, deposits,
verification (video / AI / admin), contracts, cheques and promissory notes,
pickup/return, damage handling, owner settlement, cancellation policy and SMS
lifecycle notifications are **all still unimplemented**. Do not invent rules
for any of them. The catalog entity is still named `Product`/`products`
because renaming it is a domain decision that has not been made.

Key seams built for that later work:

- `Product::isInStock()` — the single availability check. Rental replaces its
  body with a date-range query; no call site changes.
- `InventoryService` — scalar stock locking; becomes interval-overlap.
- `CatalogService::availableFacets()` — listing filters are declared in
  `config('rental.catalog.facets')` and per-category `categories.filters`,
  never hardcoded in a view.
- `ActivityLog` / `UserActivityLog` — the seams for consent, retention and
  admin access logging when verification data arrives.
- `products.attributes` (JSON) + `product_option_*` — per-item fields with no
  migration.

## Deferred integrations

- **No live payment gateway.** `PAYMENT_GATEWAY=mock`. Pardakht Novin config
  exists (carried from the Store) but no rental merchant credentials are set.
  Do not wire a real gateway until the owner confirms credentials.
- **No live SMS provider.** `MELIPAYAMAK_API_KEY` is empty and OTP send fails
  closed with a Persian message. SMS exists only as OTP delivery
  (`OtpProviderInterface`) — there is no general "send a message" seam yet;
  rental event notifications will need one.

## Safety

- The backend is the trusted source for price and totals. Never trust a
  frontend-submitted price, discount or total.
- `Model::shouldBeStrict()` is on outside production: a nonexistent attribute
  or a lazy-loaded un-eager-loaded relation throws for real in local.
- `markAsPaid()` is idempotent. Keep it that way.
- Preserve Persian/RTL and responsive behaviour in every change.
- Never run `migrate:fresh`, `migrate:refresh` or `db:wipe`.
- Do not add hooks, global settings or dependencies without being asked.

## Conventions worth following

- Admin list pages are built from the shared components in
  `resources/views/components/admin/` — see `admin/users/index.blade.php` as
  the reference implementation. Do not copy-paste table/filter/badge markup.
- Destructive admin actions use `data-confirm="…"` (intercepted by
  `adminConfirm()`), not the native `confirm()`. Feedback uses
  `adminToast()`, not `alert()`.
- Design tokens live only in `resources/views/partials/design-tokens.blade.php`.
- The catalog card is `resources/views/partials/product-card.blade.php`.
  There is exactly one; do not fork it.
