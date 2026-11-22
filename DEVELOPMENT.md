# Developing SSVS

SSVS runs in containers that match the production server: AlmaLinux 10 with PHP 8.3 from AppStream, and the MariaDB version AlmaLinux 10 ships. You only need Docker on your machine. You don't need PHP, Composer or MariaDB installed locally.

## First run

```bash
cp .env.example .env
docker compose up -d --wait
docker compose exec app php artisan migrate
```

The app is at http://localhost:8000. The database isn't published to your machine, on purpose: on a real server it's reachable only from the app.

`docker compose up` builds the app image only when it doesn't exist yet, so after pulling a change to `docker/app/Dockerfile`, rebuild it with `docker compose up -d --build` (or `docker compose build app`). Otherwise you keep the old image, and `composer install` can fail because a PHP extension a package needs is missing. The `app` and `dusk` services share one image, `ssvs-app`, so one build serves both.

## Setting up an organization

Run `docker compose exec app php artisan ssvs:setup`. It asks for the organization's name and time zone and the first admin's name and email, then prints a one-time link (and emails it). That link is how the first admin logs in. Opening it asks them to choose a password and connect an authenticator app before anything else. Once you're logged in, go to http://localhost:8000/calendar to reach the coordinator calendar. Anyone with a role can open it; admins and coordinators can change anything on the calendar, area leads can change their own areas, and only admins change settings. Volunteers see Home at http://localhost:8000/ (their next shifts and the open shifts they can take), "All my shifts" at /my-shifts, "Can't make it" and the take page from each shift, and an Emails page at /account/emails. Invite more people from the people list at http://localhost:8000/people (the invite form is there too). The invitation email lands in Mailpit.

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

Dusk adds routes under `/_dusk` that log anyone in as any user with no password, which is how `loginAs()` works. They exist only when APP_ENV=testing, which the `dusk` container sets: composer.json keeps package discovery from loading Dusk, and `AppServiceProvider` registers it only for testing, so the development server on port 8000 (APP_ENV=local) answers 404 there. Dusk's artisan commands, such as `dusk:make`, are only there under testing too, so run them in the `dusk` container: `docker compose --profile browser run --rm --no-deps --entrypoint php dusk artisan dusk:make ExampleTest`. A real server must install with `composer install --no-dev` and APP_ENV=production, so Dusk isn't installed at all; the install guide will cover this.

When a browser test fails, Dusk saves a screenshot to `tests/Browser/screenshots` and the page's HTML to `tests/Browser/source`. It also saves the browser console messages to `tests/Browser/console` for every test that logged any, passing or failing. All three are ignored by git. The app's own output from the test run is in `storage/logs/dusk-server.log`.

On GitHub, the browser tests run as their own `browser` job in `.github/workflows/tests.yml`, in parallel with the PHPUnit `test` job. The job pulls the Selenium image (retrying up to three times, because Docker Hub sometimes times out), runs the same command as above, and on failure uploads the screenshots, page HTML, console messages and `dusk-server.log` as a `browser-test-failures` artifact on the workflow run. Dependabot reads `compose.yaml` and opens weekly pull requests for the Selenium and Mailpit image tags, and for MariaDB only for patch versions, so it stays on the version AlmaLinux 10 ships. When the Selenium tag changes, check that the Dusk tests still pass before merging.

### Accessibility checks

`tests/Browser/SignInAccessibilityTest.php` runs axe-core, an open source accessibility checker, against each sign-in screen (log in, check your email, a password login error, forgot password, the code screen, account security setup, recovery codes, and login and security) and fails on any violation of the WCAG (Web Content Accessibility Guidelines) 2.0 and 2.1 level A and AA rules. A failure lists each rule, what it means and the elements it hit. To check another screen, call `$this->assertAccessible($browser)` from `Tests\Browser\Concerns\ChecksAccessibility` once the browser is on it. An automated check finds what a tool can find, such as missing labels and low contrast, so it doesn't replace trying the screens with a keyboard and a screen reader.

The copy of axe is committed in `tests/Browser/axe/` (`axe.min.js`, its `LICENSE` and a `VERSION` file) so the tests need no Node and no network. A monthly GitHub Actions workflow (`.github/workflows/axe-update.yml`, which runs `.github/scripts/axe-update-check.sh`) compares `VERSION` with the latest axe-core on npm and opens an issue titled "Update axe-core to X" when there's a newer one. You can try it without opening an issue with `.github/scripts/axe-update-check.sh --dry-run`.

#### Updating axe-core

1. Download the tarball for the new version, here `X`, into an empty directory, and get its published hash: `curl -fsSLO https://registry.npmjs.org/axe-core/-/axe-core-X.tgz` and `curl -fsS https://registry.npmjs.org/axe-core/X | jq -r .dist.integrity`.
2. Check that the file matches the hash: `echo "sha512-$(openssl dgst -sha512 -binary axe-core-X.tgz | base64 | tr -d '\n')"` must print the same value.
3. Extract `package/axe.min.js` and `package/LICENSE` (`tar -xzf axe-core-X.tgz package/axe.min.js package/LICENSE`) and copy them over the files in `tests/Browser/axe/`.
4. Put the new version in `tests/Browser/axe/VERSION`.
5. Run the whole browser suite. A new axe version can add rules, so a screen that passed before may now fail; fix the screen rather than turning the rule off.

### Example data

`docker compose exec app php artisan migrate:fresh --seed` replaces your development data with the same example organization the browser tests use: three areas, weekly and one-time shifts four weeks ahead, a closure, a cancelled shift and about 20 people with made-up names. Everyone who accepted their invitation shares one password, made at random on each seed and printed at the end of the command, so copy it from there (seeding again makes a new one). The admin (admin@example.org), coordinator (coordinator@example.org) and area lead (area-lead@example.org) have two-factor on with the authenticator secret in `ExampleOrganizationSeeder::TWO_FACTOR_SECRET`, which you can add to an authenticator app by hand. The secret stays the same from one seed to the next, so you only add it once. Some steps email people, so a few example emails land in Mailpit.

The seeder runs only when APP_ENV is `local` or `testing`. Anywhere else, including with `--force`, it stops before writing anything and points to `php artisan ssvs:setup`, because the example accounts are described in this public code.

## Styling

There's no asset build and no Node. All styling is one plain file, `public/css/app.css`, and the Atkinson Hyperlegible font is served from `public/fonts` (with its license, `OFL.txt`), so edit the CSS and reload. The font files are the Latin and Latin Extended subsets from the Google Fonts release (version 12, under the SIL Open Font License 1.1), each with the `unicode-range` Google gives it, so the browser only downloads the Latin Extended file for pages with letters such as Ł or Ş. If you update the font, replace all four files from the same release so the subsets match. Pages share the layout in `resources/views/layouts/app.blade.php`.

## Useful commands

- Generate shifts from weekly slots now, instead of waiting for the daily job: `docker compose exec app php artisan ssvs:generate-shifts`
- Run the cover request ladder now, instead of waiting for its 10-minute schedule (sends "Still open" to shifts 72 hours out and the urgent email to shifts 24 hours out): `docker compose exec app php artisan ssvs:cover-requests`
- Run the scheduler locally, so the scheduled jobs fire on their schedule (shift generation daily, `ssvs:cover-requests` every 10 minutes): `docker compose exec app php artisan schedule:work`. A real server needs cron or a systemd timer that runs `php artisan schedule:run` every minute, or shifts stop being generated and open shifts never get their "Still open" or urgent emails; the install guide will cover this.
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
