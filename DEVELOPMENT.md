# Developing SSVS

SSVS runs in containers that match the production server: AlmaLinux 10 with PHP 8.3 from AppStream, and the MariaDB version AlmaLinux 10 ships. You only need Docker on your machine. You don't need PHP, Composer or MariaDB installed locally.

## First run

```bash
cp .env.example .env
docker compose up -d --wait
docker compose exec app php artisan migrate
```

The app is at http://localhost:8000. The database isn't published to your machine, on purpose: on a real server it's reachable only from the app.

## Running tests

```bash
docker compose exec app php artisan test
```

Tests use a separate `ssvs_test` database, so they never touch your development data. If tests fail because the `ssvs_test` database doesn't exist, your database volume predates it: run `docker compose down -v` and start again (this deletes local development data).

## Useful commands

- Generate shifts from weekly slots now, instead of waiting for the daily job: `docker compose exec app php artisan ssvs:generate-shifts`
- Run Composer: `docker compose exec app composer <command>`

## Where things live

- Scheduling rules that belong to one record are methods on its model in `app/Models`, for example `WeeklySlot::occursOn()`.
- Work that spans several records is an action class in `app/Actions/Scheduling`, for example `GenerateShifts`.
- Times: the app runs in UTC and stores dated shift times in UTC. Weekly slots store local wall-clock times, converted with the organization's time zone, so a weekly shift doesn't move when daylight saving time changes.

## Upgrades

Laravel releases a major version about once a year. The rules for keeping upgrades small are in section 15 of [docs/spec.md](docs/spec.md). In short: stay close to Laravel's defaults, add packages only when they save real work, and keep the tests passing. Composer is pinned to PHP 8.3 (`config.platform.php` in `composer.json`) so no dependency can require a newer PHP than AlmaLinux 10 ships.
