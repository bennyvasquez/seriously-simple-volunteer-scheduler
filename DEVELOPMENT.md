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

Run `docker compose exec app php artisan ssvs:setup`. It asks for the organization's name and time zone and the first admin's name and email, then prints a one-time link (and emails it). That link is how the first admin logs in. Opening it asks them to choose a password and connect an authenticator app before anything else. Once you're logged in, go to http://localhost:8000/calendar to reach the coordinator calendar. Anyone with a role can open it; admins and coordinators can change anything on the calendar, area leads can change their own areas, and only admins change settings. Invite more people from the people list at http://localhost:8000/people (the invite form is there too). The invitation email lands in Mailpit.

Signing out of all devices relies on `SESSION_DRIVER=database`, which `.env.example` sets, so keep it on any server.

## Email in development

Every email the app sends lands in Mailpit at http://localhost:8025, not in real inboxes. Login links, invitations and two-factor codes show up there.

## Running tests

```bash
docker compose exec app php artisan test
```

Tests use a separate `ssvs_test` database, so they never touch your development data. If tests fail because the `ssvs_test` database doesn't exist, your database volume predates it: run `docker compose down -v` and start again (this deletes local development data).

### Browser tests

A few screens use JavaScript (for example the login page and the recovery codes Copy button), and PHPUnit can't run it, so those are also tested in a real browser with Laravel Dusk. Every screen still works with JavaScript off, and the feature tests prove that; browser tests add coverage on top. Run them with:

```bash
docker compose --profile browser run --rm dusk
```

Arguments after `dusk` go to `php artisan dusk`, for example `docker compose --profile browser run --rm dusk --filter=LoginPageTest`. This starts two containers that `docker compose up` leaves alone: `browser`, which runs Chromium (the Selenium standalone image), and `dusk`, which serves the app on port 8001 at its own address on the container network and runs the tests. The first run downloads the Selenium image, about 1 GB to download and 2.2 GB on disk, so it takes a few minutes.

The browser tests use their own `ssvs_browser` database, and every test starts from the populated example organization in `database/seeders/ExampleOrganizationSeeder.php`. If your database volume predates `ssvs_browser`, create it without losing your data:

```bash
docker compose exec -T db mariadb -uroot -pssvs-local-root-only < docker/mariadb/init/02-browser-database.sql
```

When a browser test fails, Dusk saves a screenshot to `tests/Browser/screenshots` and, when there were any, the browser console messages to `tests/Browser/console`; both are ignored by git. The app's own output from the test run is in `storage/logs/dusk-server.log`.

### Example data

`docker compose exec app php artisan migrate:fresh --seed` replaces your development data with the same example organization the browser tests use: three areas, weekly and one-time shifts four weeks ahead, a closure, a cancelled shift and about 20 people with made-up names. Everyone who accepted their invitation has the password `example-password-1`, and the admin (admin@example.org), coordinator (coordinator@example.org) and area lead (area-lead@example.org) have two-factor on with the authenticator secret in `ExampleOrganizationSeeder::TWO_FACTOR_SECRET`, which you can add to an authenticator app by hand. Some steps email people, so a few example emails land in Mailpit.

## Styling

There's no asset build and no Node. All styling is one plain file, `public/css/app.css`, and the Atkinson Hyperlegible font is served from `public/fonts` (with its license, `OFL.txt`), so edit the CSS and reload. The font files are the Latin and Latin Extended subsets from the Google Fonts release (version 12, under the SIL Open Font License 1.1), each with the `unicode-range` Google gives it, so the browser only downloads the Latin Extended file for pages with letters such as Ł or Ş. If you update the font, replace all four files from the same release so the subsets match. Pages share the layout in `resources/views/layouts/app.blade.php`.

## Useful commands

- Generate shifts from weekly slots now, instead of waiting for the daily job: `docker compose exec app php artisan ssvs:generate-shifts`
- Run the scheduler locally, so the daily jobs fire on their schedule: `docker compose exec app php artisan schedule:work`. A real server needs cron or a systemd timer that runs `php artisan schedule:run` every minute, or shifts stop being generated; the install guide will cover this.
- Set up a fresh install: `docker compose exec app php artisan ssvs:setup`
- Import the volunteer list from a Hello Club members export (it previews first and asks before saving; people are added as not invited yet and nobody is emailed): `docker compose exec app php artisan ssvs:import-people path/to/export.csv --as=admin@example.org`. To make a Hello Club category mean area lead, add `--lead-tag="Example Lead Tag" --lead-area="Front desk"`. Only name, email and membership ID are read from the file.
- Try the import locally with the example export in the repository (six made-up people, including a blank email and two people sharing one address, so you can see how those are listed). You need an active admin first (`ssvs:setup`), and the area you name with `--lead-area` has to exist (add it on the calendar): `docker compose exec app php artisan ssvs:import-people tests/Fixtures/hello-club-export.csv --as=admin@example.org --lead-tag="Example Lead Tag" --lead-area="Front desk"`. Leave out the two lead options to skip the area lead step. It shows the preview and asks before saving, so answering no leaves the database untouched. After saving, the new people show on the people list at http://localhost:8000/people as not invited yet, and Mailpit stays empty until you invite them.
- Run Composer: `docker compose exec app composer <command>`

## Where things live

- Scheduling rules that belong to one record are methods on its model in `app/Models`, for example `WeeklySlot::occursOn()`.
- Work that spans several records is an action class in `app/Actions/Scheduling`, for example `GenerateShifts`.
- Times: the app runs in UTC and stores dated shift times in UTC. Weekly slots store local wall-clock times, converted with the organization's time zone, so a weekly shift doesn't move when daylight saving time changes.

## Upgrades

Laravel releases a major version about once a year. The rules for keeping upgrades small are in section 15 of [docs/spec.md](docs/spec.md). In short: stay close to Laravel's defaults, add packages only when they save real work, and keep the tests passing. Composer is pinned to PHP 8.3 (`config.platform.php` in `composer.json`) so no dependency can require a newer PHP than AlmaLinux 10 ships.
