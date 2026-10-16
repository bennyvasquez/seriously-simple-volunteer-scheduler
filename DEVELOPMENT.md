# Developing SSVS

SSVS runs in containers that match the production server: AlmaLinux 10 with PHP 8.3 from AppStream, and the MariaDB version AlmaLinux 10 ships. You only need Docker on your machine. You don't need PHP, Composer or MariaDB installed locally.

## First run

```bash
cp .env.example .env
docker compose up -d --wait
docker compose exec app php artisan migrate
```

The app is at http://localhost:8000. The database isn't published to your machine, on purpose: on a real server it's reachable only from the app.

## Setting up an organization

Run `docker compose exec app php artisan ssvs:setup`. It asks for the organization's name and time zone and the first admin's name and email, then prints a one-time link (and emails it). That link is how the first admin logs in, but the login and security screens are placeholders for now, so a browser can't finish setup yet. Once they're built, an admin will be asked to choose a password and turn on two-factor authentication before anything else.

Signing out of all devices relies on `SESSION_DRIVER=database`, which `.env.example` sets, so keep it on any server.

## Email in development

Every email the app sends lands in Mailpit at http://localhost:8025, not in real inboxes. Login links, invitations and two-factor codes show up there.

## Running tests

```bash
docker compose exec app php artisan test
```

Tests use a separate `ssvs_test` database, so they never touch your development data. If tests fail because the `ssvs_test` database doesn't exist, your database volume predates it: run `docker compose down -v` and start again (this deletes local development data).

## Useful commands

- Generate shifts from weekly slots now, instead of waiting for the daily job: `docker compose exec app php artisan ssvs:generate-shifts`
- Run the scheduler locally, so the daily jobs fire on their schedule: `docker compose exec app php artisan schedule:work`. A real server needs cron or a systemd timer that runs `php artisan schedule:run` every minute, or shifts stop being generated; the install guide will cover this.
- Set up a fresh install: `docker compose exec app php artisan ssvs:setup`
- Run Composer: `docker compose exec app composer <command>`

## Where things live

- Scheduling rules that belong to one record are methods on its model in `app/Models`, for example `WeeklySlot::occursOn()`.
- Work that spans several records is an action class in `app/Actions/Scheduling`, for example `GenerateShifts`.
- Times: the app runs in UTC and stores dated shift times in UTC. Weekly slots store local wall-clock times, converted with the organization's time zone, so a weekly shift doesn't move when daylight saving time changes.

## Upgrades

Laravel releases a major version about once a year. The rules for keeping upgrades small are in section 15 of [docs/spec.md](docs/spec.md). In short: stay close to Laravel's defaults, add packages only when they save real work, and keep the tests passing. Composer is pinned to PHP 8.3 (`config.platform.php` in `composer.json`) so no dependency can require a newer PHP than AlmaLinux 10 ships.
