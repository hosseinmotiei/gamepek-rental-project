# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with
code in this repository.

Keep it short — details live in the path-scoped rules under `.claude/rules/`
and in `README.md`.

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

## Commands

```bash
composer install
php artisan migrate            # never migrate:fresh / migrate:refresh / db:wipe
php artisan db:seed            # users, settings, shipping, home sections, menu — no catalog
php artisan serve
vendor/bin/pint                # only formatter/linter configured
php artisan db:seed --class=RentalDemoProductSeeder   # local demo rental consoles
```

There is **no test suite** — `tests/` does not exist, only the phpunit dev
dependency. Verification is manual: exercise the affected page and the admin
screen that touches the same service. If you add tests, create the directory
and the `Tests\` autoload target already declared in `composer.json`.

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

## Rental domain — read-only slice only

A **display and pricing** slice exists. Everything that mutates state is
still unimplemented: reservations, owner accounts, deposits held or refunded,
verification (video / AI / admin), contracts, cheques and promissory notes,
pickup/return, damage handling, owner settlement, cancellation policy and SMS
lifecycle notifications. Do not invent rules for any of them. The catalog
entity is still named `Product`/`products` because renaming it is a domain
decision that has not been made.

What is built (ported from the `Grok-show` prototype, all pure/read-only):

- `products.attributes['_rental']` (JSON) holds every rental fact. There are
  no rental tables and no rental columns. `Support\Rental\RentalItem` is the
  **single** reader of that blob — `supports()` answers "is this rentable",
  typed accessors expose the rest. Never index into `attributes['_rental']`
  anywhere else.
- `RentalPricingService::quote()` is the authoritative cost breakdown,
  returning an immutable `RentalQuote`. Duration discount tiers live in
  `config('rental.pricing.duration_discounts')` and are a **placeholder** —
  not owner-approved pricing. `deposit` is never part of `payableNow`.
  `partials/rental-panel.blade.php` mirrors this arithmetic in JS for live
  preview only; the charged figure must come from the service.
- `Support\Rental\Availability` / `RentalCalendar` do interval-overlap day
  classification against `BlockedRange` values passed in. They never query —
  where blocked ranges come from is an undecided domain question.
- `Support\Rental\Jalali` is a self-contained Jalali/Gregorian converter used
  by the rental calendar. The rest of the app still renders Gregorian.
- `CartService::addItem()` **rejects** rentable products with a Persian
  error: a rental cannot go through the buy flow. Guarded once in the service,
  not per view.
- `RentalDemoProductSeeder` is local fixtures with invented prices, health
  scores and reviews. Not in `DatabaseSeeder`; refuses to run in production.

Key seams for the later work:

- `Product::isInStock()` — the single availability check, still scalar.
  Rental replaces its body with a date-range query; no call site changes.
- `InventoryService` — scalar stock locking; becomes interval-overlap.
- `CatalogService::availableFacets()` — listing filters are declared in
  `config('rental.catalog.facets')` (currently empty) and per-category
  `categories.filters`, never hardcoded in a view.
- `ActivityLog` / `UserActivityLog` — the seams for consent, retention and
  admin access logging when verification data arrives.
- `products.attributes` (JSON) + `product_option_*` — per-item fields with no
  migration.

## Deferred integrations

- **No live payment gateway.** `PAYMENT_GATEWAY=mock`. Pardakht Novin config
  exists (carried from the Store) but no rental merchant credentials are set.
  Do not wire a real gateway until the owner confirms credentials.
- **No live SMS provider.** `MELIPAYAMAK_API_KEY` is empty; `NullOtpProvider`
  delivers OTP locally so login works in development. SMS exists only as OTP
  delivery (`OtpProviderInterface`) — there is no general "send a message"
  seam yet; rental event notifications will need one.
- **Wallet is a frontend-only prototype.** The profile wallet tab (balance,
  top-up, withdraw, history) is localStorage in Blade; there is no wallet
  column, table or service, and `Admin\WalletController` is a nav placeholder.
  Do not treat any wallet figure as real money.

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
