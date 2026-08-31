# GamePek Rental

Foundation for the GamePek rental platform (console and accessory rental),
cloned and pruned from the GamePek Store (`gamepek-backend`).

This repository currently contains **only the reusable foundation**: the
GamePek design language, the admin panel shell, OTP auth, the catalog/cart/
checkout spine, and the payment scaffolding. No rental business logic is
implemented yet — see `CLAUDE.md` for what is deliberately absent and which
seams the rental phase will extend.

## Stack

- Laravel 11, PHP 8.2, MySQL/MariaDB
- Four production dependencies: `laravel/framework`, `laravel/tinker`,
  `spatie/laravel-permission`, plus PHP itself
- Persian, RTL. No build step — Tailwind (CDN), Vazirmatn (Google Fonts) and
  Font Awesome load from CDN; all JS is inline in Blade

## Local setup

```bash
composer install                 # or: php composer.phar install
cp .env.example .env             # then set DB_DATABASE=gamepek_rental
php artisan key:generate         # APP_KEY also keys the OTP HMAC — keep it unique
php artisan migrate
php artisan db:seed
php artisan serve
```

Create the database first:

```sql
CREATE DATABASE gamepek_rental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Seeded accounts (local/testing only)

| Account | Credentials |
|---|---|
| Dev super-admin | `admin@gamepek-rental.local` / `password` → `/admin/login` |
| Production admin | mobile `09100000001`, role `super_admin`, no password |
| Test customer | mobile `09120000002` |

Customers sign in with mobile + OTP at `/auth/login`; admins sign in with
email + password at `/admin/login`. Both use the same `users` table and `web`
guard — admin access is role-based via `config('rental.admin.roles')`.

Catalog taxonomy, banners and quick-category tiles are **not** seeded: they
are content, and the rental taxonomy has not been designed. Create them from
the admin panel.

## What is in scope right now

Homepage · catalog listing · item detail · cart · pre-payment checkout ·
post-payment result · Contact · Terms · admin panel.

There is no About Us page — the Store never had one, so there was nothing to
clone. It is a later phase.

## Configuration

`config/rental.php` is the single app config (OTP, payment, cart, pagination,
admin roles, catalog facets). `config/services.php` holds the MeliPayamak and
gateway credentials.

Both integrations are **deliberately inert**:

- `PAYMENT_GATEWAY=mock` — no live gateway is connected.
- `MELIPAYAMAK_API_KEY` is empty — OTP send fails closed with a Persian
  message rather than silently pretending to work.

## Storage

`config/filesystems.php` reads `STORAGE_PUBLIC_PATH`. On the production host
the document root sits *above* the Laravel root, so uploads must be written
to the served `storage/` directory or Apache will never see them. Locally it
falls back to `public/storage`. `ImageUploadService::storeAndVerify()`
re-checks the file exists on disk after writing, because on that host a write
can report success without being immediately visible.

## Known gaps carried over from the Store

These were inherited rather than introduced, and are worth scheduling:

- **CDN dependency** — Tailwind's play CDN compiles in the browser and
  Vazirmatn loads from Google Fonts. Both are a latency and availability risk
  for Iranian hosting, and the Tailwind play CDN is not intended for
  production.
- **No Jalali/Shamsi dates** — every date renders Gregorian
  (`format('Y/m/d')`). A Persian rental platform will want a Jalali library.
- **No automated tests** — `tests/` is an empty scaffold.
- **No general SMS seam** — only OTP delivery exists.
- **No loading/skeleton states** — filtering and sorting are full page loads.
- **Terms content is hardcoded in Blade** rather than DB-driven.

## Relationship to the Store

`gamepek-backend` is a separate application with a separate database and is
never modified by work here. Shared code was copied, not linked, so the two
will drift; that was an explicit trade-off for keeping the live Store safe.
