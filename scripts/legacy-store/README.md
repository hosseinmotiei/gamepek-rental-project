# Legacy GamePek **Store** scripts — DO NOT RUN FOR RENTAL

These scripts were inherited when GamePek Rental was forked from the Store
(`gamepek-backend`). They were never adapted for Rental. They are kept here for
reference only, quarantined out of the project root so they cannot be mistaken
for Rental setup.

## Why they are dangerous here

Every script in this directory does at least one of the following:

| Script | Creates/migrates `gamepek` (Store DB) | `cd`s into the Store project directory |
|---|---|---|
| `setup.sh` | yes | no |
| `install.sh` | yes | no |
| `install.bat` | yes | **yes** |
| `setup-xampp.bat` | yes | **yes** |
| `migrate.sh` | yes | no |
| `migrate.bat` | yes | **yes** |
| `deploy.bat` | no | **yes** |
| `clear-cache.bat` | no | **yes** |

The hardcoded path is `C:\Users\Hossein Mt\Desktop\GamePek\gamepek-backend`.

**On a machine where the Store checkout exists, running any of these operates on
the live Store project and/or its database** — creating it, migrating it, or
seeding it. That is why they are here and not in the project root.

`gamepek` is the **Store** database. `gamepek_rental` is this application's
database. They must never be crossed.

## What to use instead

Rental has no bundled setup script. Follow the documented manual setup in the
project `README.md`. It is short, explicit about the database name, and does not
touch the Store.

The scripts that remain in the project root are path-relative and Rental-safe:

- `deploy.sh` — runs `artisan down / migrate / optimize / up` in its own directory
- `clear-cache.sh` — clears config/cache/view/route caches in its own directory
- `add-hosts.sh` / `add-hosts-admin.bat` — add a `127.0.0.1 gamepek.test` hosts
  entry. Note that `gamepek.test` is the **Store's** local hostname; if you run
  both projects locally, give Rental its own hostname instead of reusing this.

## Do not "fix" these files

They are Store artifacts. Repairing them would create a second, competing setup
path for Rental and invite exactly the confusion this directory exists to
prevent. If Rental ever needs a setup script, write a new one that is
Rental-specific and keep it in the project root.
