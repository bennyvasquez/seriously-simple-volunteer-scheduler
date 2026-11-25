# Developing SSVS

SSVS runs in containers that match the production server: AlmaLinux 10 with PHP 8.3 from AppStream, and the MariaDB version AlmaLinux 10 ships. You only need Docker on your machine. You don't need PHP, Composer or MariaDB installed locally.

## First run

```bash
cp .env.example .env
docker compose up -d --wait
docker compose exec app php artisan migrate
```

The app is at http://localhost:8000. `APP_URL` in `.env` is the address the app uses for every link it builds, emails included, whatever address a request was sent to, so keep it matching the address you open the app at (`.env.example` sets http://localhost:8000). On a real server it must be the public `https://` address. `TRUSTED_PROXIES` (empty in development) is only for an HTTP proxy or load balancer in front of the app, whose `X-Forwarded-For` and `X-Forwarded-Proto` headers the app then believes. Leave it empty otherwise, including on the standard server layout (nginx with PHP-FPM), and see spec section 13. The database isn't published to your machine, on purpose: on a real server it's reachable only from the app. The app and Mailpit ports are bound to 127.0.0.1 in `compose.yaml`, so only this computer can reach them; other devices on your network (a café or makerspace Wi-Fi) can't. To test from a phone, change the port lines in `compose.yaml` on purpose and remember it exposes the debug page and every email the app sent.

`docker compose up` builds the app image only when it doesn't exist yet, so after pulling a change to `docker/app/Dockerfile`, rebuild it with `docker compose up -d --build` (or `docker compose build app`). Otherwise you keep the old image, and `composer install` can fail because a PHP extension a package needs is missing. The `app` and `dusk` services share one image, `ssvs-app`, so one build serves both.

## Setting up an organization

Run `docker compose exec app php artisan ssvs:setup`. It asks for the organization's name and time zone and the first admin's name and email, then prints a one-time link (and emails it). That link is how the first admin logs in. Opening it asks them to choose a password and connect an authenticator app before anything else. Once you're logged in, go to http://localhost:8000/calendar to reach the coordinator calendar. Everyone who can sign in can open it (it's the Schedule tab in the Home, Schedule and Me menu), and volunteers get it read-only with no editing controls; admins and coordinators can change anything on the calendar, area leads can change their own areas, coordinators and admins change the Shadowing setting, and only admins change the other settings. Volunteers see Home at http://localhost:8000/ (their next shifts and the open shifts they can take, or, for someone not cleared for regular shifts yet, their shadow progress and the open shadow spots), "All my shifts" at /my-shifts, "Can't make it" and the take page from each shift, and an Emails page at /account/emails. Invite more people from the people list at http://localhost:8000/people (the invite form is there too). The invitation email lands in Mailpit.

Signing out of all devices relies on `SESSION_DRIVER=database`, which `.env.example` sets, so keep it on any server.

## What production refuses

When `APP_ENV=production`, the app turns `APP_DEBUG` off at start-up (and logs one critical line), because the debug page shows request bodies, headers and SQL, and it refuses to send email through the `log` mailer, because that would write login links and codes to the log file. Neither applies to local development or the tests, which use Mailpit and the array mailer. A production `.env` needs the values listed at the top of `.env.example`.

## Email in development

Every email the app sends lands in Mailpit at http://localhost:8025, not in real inboxes. Login links, invitations and two-factor codes show up there.

## Changing an email's wording

The way to change wording is the email editor: sign in as a coordinator or admin and open "Emails we send" (http://localhost:8000/emails, linked from the calendar toolbar and Home). Pick an email, change its lines, preview it, and save. Edits are stored in the database (`email_wordings`) and every one is in the change log, so a release never overwrites them. "Back to the default" on a line puts it back. An edit is plain text and can only use the `{variables}` the default line has; the editor refuses anything else (spec section 10 lists the rules).

`lang/en/emails.php` holds the defaults, one array per email. Change it only to change what an email says before anyone edits it, or when you add an email or a line. Keep the `{variable_name}` placeholders the line already has (the comment above each line says what they hold), and don't add a placeholder the code doesn't fill in. A missing key or a placeholder left unfilled throws an error, so run `docker compose exec app php artisan test` afterwards: one test renders every email and fails on anything left over. Existing tests that check email text will fail if the new wording differs, so update their expected text in the same change. Wording with a fixed duration (10, 15 or 60 minutes, 7 days, 24, 48 and 72 hours) is plain text, so changing those words doesn't change the real limit.

The catalog (`app/Emails/EmailCatalog.php`) lists every email and line the editor shows, with when each shows, the variables it can use with examples, and the versions to preview. Every new line in `lang/en/emails.php` must be listed there, and the tests fail if one isn't. Links and bold text in a default line come in through a variable built in code (`EmailText::link()`), never as Markdown in the wording, so an edit can't change where a link points.

Four lines aren't in `lang/en/emails.php` by origin: Laravel's mail view adds "Hello!" (the heading of every email that sets no greeting), "Regards,", the "If you're having trouble clicking" line under every button, and "All rights reserved." in the footer. Their defaults are in the `every_email` array of `lang/en/emails.php`, and the editor's "Every email" page changes them. They're applied at send time by `App\Notifications\LaravelMailLines`. The name in the header, under "Regards," and in the footer, and the sender's name in the inbox, is the organization's name from Settings (set just before each email goes out, in `AppServiceProvider`); `APP_NAME` is used only before an organization exists. The footer year is the current year. A few small pieces are still built in code and can't be edited: "to" in time ranges (`App\Calendar\TimeFormat`), "Someone" for a removed person (`User::releaserName()` and `CoverRequest`), the role names passed into emails ("a coordinator", "a shift lead for Front desk"), "every other " before a weekday (`TakeShift`), and plural weekdays such as "Thursdays".

## Running tests

```bash
docker compose exec app php artisan test
```

Tests use a separate `ssvs_test` database, so they never touch your development data. If tests fail because the `ssvs_test` database doesn't exist, your database volume predates it: run `docker compose down -v` and start again (this deletes local development data).

### Static analysis and dependency audit

```bash
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app composer audit --locked
```

PHPStan (through Larastan) checks `app/` at level 5 against `phpstan-baseline.neon`, a list of the errors that existed when it was added, so only new errors fail. Fix a new error rather than adding it to the baseline. After you fix a baselined error, PHPStan reports the entry as unmatched; regenerate with `./vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G` so the baseline only shrinks. If PHPStan reports a file that no longer exists or misses a new one, run `./vendor/bin/phpstan clear-result-cache`. `composer audit --locked` fails if any locked package has a known security advisory. CI runs both after the tests.

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

`tests/Browser/ScheduleTest.php` also saves phone-width (390px) screenshots of the volunteer's schedule, release page and take page there on every run, so you can check by eye that the fixed bottom menu hides nothing. When a browser test fails, Dusk saves a screenshot to `tests/Browser/screenshots` and the page's HTML to `tests/Browser/source`. It also saves the browser console messages to `tests/Browser/console` for every test that logged any, passing or failing. All three are ignored by git. The app's own output from the test run is in `storage/logs/dusk-server.log`.

On GitHub, the browser tests run as their own `browser` job in `.github/workflows/tests.yml`, in parallel with the PHPUnit `test` job. The job pulls the Selenium image (retrying up to three times, because Docker Hub sometimes times out), runs the same command as above, and on failure uploads the screenshots, page HTML, console messages and `dusk-server.log` as a `browser-test-failures` artifact on the workflow run. Dependabot reads `compose.yaml` and opens weekly pull requests for the Selenium and Mailpit image tags, and for MariaDB only for patch versions, so it stays on the version AlmaLinux 10 ships. It also reads `docker/app/Dockerfile` and opens pull requests when the AlmaLinux 10 or Composer base image digests change (they are pinned by digest, so a moved tag can't change a build unnoticed). When the Selenium tag changes, check that the Dusk tests still pass before merging.

### Accessibility checks

`tests/Browser/SignInAccessibilityTest.php` runs axe-core, an open source accessibility checker, against each sign-in screen (log in, check your email, a password login error, forgot password, the code screen, account security setup, recovery codes, and login and security) and fails on any violation of the WCAG (Web Content Accessibility Guidelines) 2.0 and 2.1 level A and AA rules. A failure lists each rule, what it means and the elements it hit. A whole-page check also fails unless the page has exactly one `h1`, because screen reader users jump to it first and axe only treats that as a best practice. To check another screen, call `$this->assertAccessible($browser)` from `Tests\Browser\Concerns\ChecksAccessibility` once the browser is on it. To check only part of a page, pass a CSS selector as the second argument. An automated check finds what a tool can find, such as missing labels and low contrast, so it doesn't replace trying the screens with a keyboard and a screen reader.

The copy of axe is committed in `tests/Browser/axe/` (`axe.min.js`, its `LICENSE` and a `VERSION` file) so the tests need no Node and no network. A monthly GitHub Actions workflow (`.github/workflows/axe-update.yml`, which runs `.github/scripts/axe-update-check.sh`) compares `VERSION` with the latest axe-core on npm and opens an issue titled "Update axe-core to X" when there's a newer one. You can try it without opening an issue with `.github/scripts/axe-update-check.sh --dry-run`.

#### Updating axe-core

1. Download the tarball for the new version, here `X`, into an empty directory, and get its published hash: `curl -fsSLO https://registry.npmjs.org/axe-core/-/axe-core-X.tgz` and `curl -fsS https://registry.npmjs.org/axe-core/X | jq -r .dist.integrity`.
2. Check that the file matches the hash: `echo "sha512-$(openssl dgst -sha512 -binary axe-core-X.tgz | base64 | tr -d '\n')"` must print the same value.
3. Extract `package/axe.min.js` and `package/LICENSE` (`tar -xzf axe-core-X.tgz package/axe.min.js package/LICENSE`) and copy them over the files in `tests/Browser/axe/`.
4. Put the new version in `tests/Browser/axe/VERSION`.
5. Run the whole browser suite. A new axe version can add rules, so a screen that passed before may now fail; fix the screen rather than turning the rule off.

### Example data

`docker compose exec app php artisan migrate:fresh --seed` replaces your development data with the same example organization the browser tests use: three areas, weekly and one-time shifts four weeks ahead, a closure, a cancelled shift and about 20 people with made-up names. Everyone who accepted their invitation shares one password, made at random on each seed and printed at the end of the command, so copy it from there (seeding again makes a new one). The admin (admin@example.org), coordinator (coordinator@example.org) and area lead (area-lead@example.org) have two-factor on with the authenticator secret in `ExampleOrganizationSeeder::TWO_FACTOR_SECRET`, which you can add to an authenticator app by hand. The secret stays the same from one seed to the next, so you only add it once. Everyone with a role or a regular slot is cleared for regular shifts, including the volunteer (volunteer@example.org, Jamie Lee). The trainee (trainee@example.org, Riley Brooks) isn't cleared and has no shifts, so logging in as them shows the shadow progress on Home and the open shadow spots to sign up for. Rosa Delgado (rosa@example.org) isn't cleared either and is shadowing a Tuesday front desk shift. Owen Tran (owen@example.org) and Kai Nakamura (kai@example.org) are on one-time shifts but aren't regulars, so they aren't cleared and logging in as them also shows the shadow Home, with their one-time shift under "Your other shifts". Some steps email people, so a few example emails land in Mailpit.

`migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` delete every table, so they refuse to run unless `APP_ENV` is `local` or `testing`, even with `--force`. On a real server they always refuse.

The seeder runs only when APP_ENV is `local` or `testing`. Anywhere else, including with `--force`, it stops before writing anything and points to `php artisan ssvs:setup`, because the example accounts are described in this public code.

## Styling

There's no asset build and no Node. All styling is one plain file, `public/css/app.css`, and the Atkinson Hyperlegible font is served from `public/fonts` (with its license, `OFL.txt`), so edit the CSS and reload. The font files are the Latin and Latin Extended subsets from the Google Fonts release (version 12, under the SIL Open Font License 1.1), each with the `unicode-range` Google gives it, so the browser only downloads the Latin Extended file for pages with letters such as Ł or Ş. If you update the font, replace all four files from the same release so the subsets match. Pages share the layout in `resources/views/layouts/app.blade.php`.

## Useful commands

- Generate shifts from weekly slots now, instead of waiting for the daily job: `docker compose exec app php artisan ssvs:generate-shifts`
- Run the cover request ladder now, instead of waiting for its 10-minute schedule (sends "Still open" to shifts 72 hours out and the urgent email to shifts 24 hours out): `docker compose exec app php artisan ssvs:cover-requests`
- Run the 48-hour heads-up to leads about unanswered time changes now, instead of waiting for its 10-minute schedule: `docker compose exec app php artisan ssvs:change-heads-ups`
- Send the shift reminders that are due now, instead of waiting for the 10-minute schedule: `docker compose exec app php artisan ssvs:shift-reminders`
- Run the scheduler locally, so the scheduled jobs fire on their schedule (shift generation daily, `ssvs:cover-requests`, `ssvs:change-heads-ups` and `ssvs:shift-reminders` every 10 minutes): `docker compose exec app php artisan schedule:work`. A real server needs cron or a systemd timer that runs `php artisan schedule:run` every minute, or shifts stop being generated and open shifts never get their "Still open" or urgent emails; the install guide will cover this.
- On a real server, update in this order: pull the new code, run `php artisan migrate --force`, then let the scheduler and any queue worker run again. Every email send reads the `email_wordings` table, so new code running before its migrations makes the next emails fail. Stop (or pause) the scheduler and queue workers before the update if they could fire in between.
- The health check for monitors is `/up`. It answers `OK` as plain text, sets no cookie, and keeps answering during maintenance mode.
- Restoring a backup on a real server never uses the blocked commands above: drop and recreate the database with the `mariadb` client, then import the dump. The install guide (#5) will give the exact steps.
- Set up a fresh install: `docker compose exec app php artisan ssvs:setup`
- Import the volunteer list from a Hello Club members export (it previews first and asks before saving; people are added as not invited yet and nobody is emailed): `docker compose exec app php artisan ssvs:import-people path/to/export.csv --as=admin@example.org`. To make a Hello Club category mean area lead, add `--lead-tag="Example Lead Tag" --lead-area="Front desk"`. To clear everyone with a category for regular shifts, add `--cleared-tag="Example Cleared Tag"`; running the import again with it clears people who were imported earlier, except anyone a coordinator moved back to shadowing or turned off. Only name, email and membership ID are read from the file.
- Try the import locally with the example export in the repository (six made-up people, including a blank email and two people sharing one address, so you can see how those are listed). You need an active admin first (`ssvs:setup`), and the area you name with `--lead-area` has to exist (add it on the calendar): `docker compose exec app php artisan ssvs:import-people tests/Fixtures/hello-club-export.csv --as=admin@example.org --lead-tag="Example Lead Tag" --lead-area="Front desk"`. Leave out the two lead options to skip the area lead step. It shows the preview and asks before saving, so answering no leaves the database untouched. After saving, the new people show on the people list at http://localhost:8000/people as not invited yet, and Mailpit stays empty until you invite them.
- Run Composer: `docker compose exec app composer <command>`

## Where things live

- Scheduling rules that belong to one record are methods on its model in `app/Models`, for example `WeeklySlot::occursOn()`.
- Work that spans several records is an action class in `app/Actions/Scheduling`, for example `GenerateShifts`.
- Times: the app runs in UTC and stores dated shift times in UTC. Weekly slots store local wall-clock times, converted with the organization's time zone, so a weekly shift doesn't move when daylight saving time changes.

## Upgrades

Laravel releases a major version about once a year. The rules for keeping upgrades small are in section 15 of [docs/spec.md](docs/spec.md). In short: stay close to Laravel's defaults, add packages only when they save real work, and keep the tests passing. Composer is pinned to PHP 8.3 (`config.platform.php` in `composer.json`) so no dependency can require a newer PHP than AlmaLinux 10 ships.
