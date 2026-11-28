# Installing SSVS on AlmaLinux 10

This guide walks you through installing the Seriously Simple Volunteer Scheduler (SSVS) on your own AlmaLinux 10 server, one install for one organization. It's written for the volunteer who looks after the server, not for a developer. Each step says what to type and, briefly, why.

You type every command as root. Log in to the server over SSH (Secure Shell) and run `sudo -i` first, so the commands below run as root with root's own settings.

This guide uses **ssvs.example.org** as the site's name. Wherever you see ssvs.example.org, in a command or in a file, type your own site's name instead. The same goes for the example email addresses, volunteers@example.org and backups@example.org.

A full installer script that does these steps for you is planned for after launch ([#38](https://github.com/bennyvasquez/seriously-simple-volunteer-scheduler/issues/38)).

Some steps can't be tested in a container, so each of those says when it was last checked on a real AlmaLinux 10 server.

## Before you start

You need:

- **A server with at least 2 GB of memory** running AlmaLinux 10, from any hosting company. One small virtual server is plenty for one organization.
- **A domain name pointed at the server.** Make a DNS (Domain Name System) record for your site's name (an A record for the server's IPv4 address, and an AAAA record if it has an IPv6 address) and wait until `ping ssvs.example.org` from your own computer answers from the server's address. The free HTTPS certificate in step 6 can't be issued until it does.
- **An SMTP (Simple Mail Transfer Protocol) account** with an email provider, so SSVS can send login links, cover requests and reminders. You need its server name, port, username and password, and an address to send from on a domain your organization controls. Follow your provider's instructions for SPF (Sender Policy Framework), DKIM (DomainKeys Identified Mail) and DMARC (Domain-based Message Authentication, Reporting and Conformance) on that domain, or a lot of the mail will land in spam folders.
- **Your hosting company's snapshot backups turned on.** Most hosts offer a nightly copy of the whole server for a small fee. Until SSVS can send its own backups somewhere else (step 8), that snapshot is the copy that survives if the server is lost.
- **A computer of your own with GnuPG (the GNU Privacy Guard) on it**, for the backup key in step 8. On a Mac that's GPG Suite or `brew install gnupg`, on Windows it's Gpg4win, and on Linux it's usually installed already.

## 1. Updates and the firewall

Security updates should install themselves, and the firewall should let in only web traffic and SSH.

1. Install dnf-automatic, which installs updates on a timer:

   ```
   dnf install dnf-automatic
   ```

2. Tell it to install security updates, not just download them:

   ```
   sed -i -e 's/^upgrade_type = .*/upgrade_type = security/' -e 's/^apply_updates = .*/apply_updates = yes/' /etc/dnf/automatic.conf
   ```

   Check the result with `grep -E '^(upgrade_type|apply_updates)' /etc/dnf/automatic.conf`, which should print `upgrade_type = security` and `apply_updates = yes`.

3. Turn on its timer:

   ```
   systemctl enable --now dnf-automatic.timer
   ```

   dnf-automatic never restarts the server. A kernel update only takes effect after a restart, so once a month or so run `dnf needs-restarting -r`, and if it says a reboot is needed, run `reboot` at a quiet time.

4. Install firewalld (some hosting companies' images leave it out; where it's already there, this does nothing), make sure it's running, then allow HTTPS (the secure web), HTTP (plain web, which only sends visitors on to HTTPS and lets Let's Encrypt check your site when it issues certificates) and SSH:

   ```
   dnf install -y firewalld
   systemctl enable --now firewalld
   firewall-cmd --permanent --add-service=https --add-service=http --add-service=ssh
   ```

5. AlmaLinux also opens the port for Cockpit, a web console. If you don't use Cockpit, close it:

   ```
   firewall-cmd --permanent --remove-service=cockpit
   ```

6. Load the new rules and check them:

   ```
   firewall-cmd --reload
   firewall-cmd --list-services
   ```

   You should see `dhcpv6-client http https ssh` (dhcpv6-client lets the server get its own IPv6 address).

Checked on a real AlmaLinux 10 server (the firewalld steps): not yet.

## 2. Packages

1. Turn on the CRB (CodeReady Builder) repository and EPEL (Extra Packages for Enterprise Linux), where Certbot, the Let's Encrypt client, comes from. EPEL packages sometimes need CRB.

   ```
   dnf config-manager --set-enabled crb
   dnf install epel-release
   ```

2. Install the web server (nginx), PHP and PHP-FPM (the FastCGI Process Manager, which runs the app's PHP for nginx), the MariaDB database, Certbot, and the tools the later steps use:

   ```
   dnf install nginx php-fpm php-cli php-common php-mbstring php-mysqlnd php-pdo php-xml php-intl php-bcmath php-process php-opcache mariadb-server git unzip gnupg2 tar openssl certbot python3-certbot-nginx policycoreutils-python-utils nano
   ```

## 3. The database

MariaDB runs on the same server and only the app talks to it, through a socket file. Nothing outside the server can reach it.

1. Turn off MariaDB's network port, so it listens only on the socket:

   ```
   cat > /etc/my.cnf.d/ssvs.cnf <<'EOF'
   [mysqld]
   skip-networking
   EOF
   ```

2. Start MariaDB, and have it start when the server does:

   ```
   systemctl enable --now mariadb
   ```

3. Run MariaDB's own security script:

   ```
   mariadb-secure-installation
   ```

   Press Enter when it asks for the current root password (there isn't one: root logs in through the socket as the server's root user). Answer `n` to switching to unix_socket authentication (it's already on) and `n` to changing the root password, then `Y` to everything else: removing anonymous users, disallowing remote root login, removing the test database, and reloading the privilege tables.

4. Choose a long random password for the app's database user. This makes one:

   ```
   openssl rand -base64 24
   ```

   Keep it handy for the next step and for step 4.

5. Start the database client with `mariadb`, paste these three lines with your password in place of `CHANGE-ME-database-password`, then type `exit`:

   ```
   CREATE DATABASE ssvs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'ssvs'@'localhost' IDENTIFIED BY 'CHANGE-ME-database-password';
   GRANT ALL PRIVILEGES ON ssvs.* TO 'ssvs'@'localhost';
   ```

## 4. The app

The app runs as its own user, `ssvs`, and lives in `/var/www/ssvs`.

1. Make the `ssvs` user. It can't log in; it only runs the app.

   ```
   useradd --system --shell /sbin/nologin --home-dir /var/lib/ssvs --create-home ssvs
   ```

2. Download SSVS into `/var/www/ssvs`, as the `ssvs` user:

   ```
   install -d -o ssvs -g ssvs /var/www/ssvs
   runuser -u ssvs -- git clone https://github.com/bennyvasquez/seriously-simple-volunteer-scheduler.git /var/www/ssvs
   ```

3. Install Composer, PHP's package manager, from getcomposer.org. These commands check the installer against the checksum Composer publishes before running it, and stop if they don't match:

   ```
   cd /root
   curl -sS -o composer-setup.php https://getcomposer.org/installer
   echo "$(curl -sS https://composer.github.io/installer.sig)  composer-setup.php" | sha384sum -c -
   php composer-setup.php --install-dir=/usr/local/bin --filename=composer
   rm composer-setup.php
   ```

   The `sha384sum` line has to print `composer-setup.php: OK`. If it prints anything else, stop and try again later.

4. Install the app's PHP packages. `--no-dev` leaves out the development tools, including Laravel Dusk, whose test helpers would let anyone log in as anyone, so they must never be on a real server.

   ```
   cd /var/www/ssvs
   runuser -u ssvs -- composer install --no-dev --optimize-autoloader
   ```

5. Copy the production settings template to `.env`, readable only by the app:

   ```
   install -m 0600 -o ssvs -g ssvs deploy/env.production.example .env
   ```

6. Open it with `nano .env` and change:
   - `APP_URL` to your site's own address, like `https://ssvs.example.org` but with your name. Every link SSVS emails is built from it. The install check refuses example.org and example.com addresses, so it reminds you if you forget.
   - `DB_PASSWORD` to the database password from step 3.
   - The `MAIL_` lines to your SMTP provider's details: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS` (an address your organization reads, not volunteers@example.org).
   - `APP_NAME`, if you'd like emails to come from your organization's name instead of "SSVS".

   Press Ctrl+O and Enter to save, then Ctrl+X to leave.

7. Make the app's secret key. Production asks "Are you sure?" without `--force`.

   ```
   runuser -u ssvs -- php artisan key:generate --force
   ```

   This key (`APP_KEY` in `.env`) encrypts two-factor secrets and recovery codes. If it's lost or changed, every coordinator and admin has to set up their authenticator again. Step 8 says where to keep a copy.

8. Create the database tables:

   ```
   runuser -u ssvs -- php artisan migrate --force
   ```

9. Cache the settings, routes and screens, which makes the app faster:

   ```
   runuser -u ssvs -- php artisan config:cache
   runuser -u ssvs -- php artisan route:cache
   runuser -u ssvs -- php artisan view:cache
   ```

   The app reads `.env` only through this cache, so whenever you change `.env` later, run `cd /var/www/ssvs` and then `runuser -u ssvs -- php artisan config:cache` again.

10. Set up the organization and its first admin (you). It asks for the organization's name, its time zone (like America/Detroit), and your name and email address:

    ```
    runuser -u ssvs -- php artisan ssvs:setup
    ```

    You can also give the answers on the command line: `runuser -u ssvs -- php artisan ssvs:setup --organization="Example Makerspace" --timezone=America/Detroit --admin-name="Jordan Example" --admin-email=jordan@example.org`. It prints an invitation link for your account as well as emailing it, so you can sign in once step 6 is done even if email isn't working yet. The link works once, for 7 days.

Always run `php artisan` from `/var/www/ssvs` (it says `Could not open input file: artisan` anywhere else), and as the `ssvs` user, as above. Run as root, it can leave files in `storage` that the app can't write to afterward.

## 5. File permissions and SELinux

SELinux (Security-Enhanced Linux) is on in enforcing mode on AlmaLinux, and it stays on. It limits what the web server and PHP can do even if something goes wrong in the app. SSVS needs three small changes to its rules.

1. Make sure the app's files belong to `ssvs`, and `.env` is readable only by it:

   ```
   chown -R ssvs:ssvs /var/www/ssvs
   chmod 0600 /var/www/ssvs/.env
   ```

2. Check that SELinux is enforcing. `getenforce` should print `Enforcing`.

3. Let PHP write to the two folders the app writes to (`storage` for logs and its working files, `bootstrap/cache` for the caches from step 4), and nowhere else under `/var/www/ssvs`:

   ```
   semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/ssvs/(storage|bootstrap/cache)(/.*)?'
   restorecon -R /var/www/ssvs
   ```

   The rest of the app stays read-only to PHP, even though `ssvs` owns it.

4. Let PHP send email. If your provider's SMTP port is 25, 465 or 587, this is all you need:

   ```
   setsebool -P httpd_can_sendmail 1
   ```

   If your provider uses any other port (2525 is common), SELinux blocks that one too, and you also need `setsebool -P httpd_can_network_connect 1`, which lets PHP connect out to any port. Only turn it on if your port isn't 25, 465 or 587.

5. If something doesn't work later and you suspect SELinux, this shows what it blocked in the last ten minutes:

   ```
   ausearch -m avc -ts recent
   ```

   Each `denied` line names the program (`comm=`), what it tried, and the file or port. Search for the line, or ask on the SSVS issue tracker, before turning SELinux off: turning it off hides the problem and removes the protection.

Checked on a real AlmaLinux 10 server (the SELinux steps): not yet.

## 6. nginx, PHP-FPM and HTTPS

nginx answers web requests and hands them to PHP-FPM. HTTPS uses TLS (Transport Layer Security) with a free certificate from Let's Encrypt.

1. Add the SSVS pool to PHP-FPM, so the app's PHP runs as the `ssvs` user:

   ```
   cp /var/www/ssvs/deploy/php-fpm/ssvs.conf /etc/php-fpm.d/ssvs.conf
   ```

2. Turn off PHP-FPM's stock `www` pool. SSVS doesn't use it, and it would otherwise run spare PHP processes as another user for nothing. Emptying the file, rather than deleting it, keeps a package update from putting it back (a changed file is left alone on updates; a deleted one is replaced).

   ```
   echo '; Turned off: SSVS uses its own pool in ssvs.conf.' > /etc/php-fpm.d/www.conf
   ```

3. Start PHP-FPM:

   ```
   systemctl enable --now php-fpm
   ```

4. Turn off nginx's own welcome site. The stock `/etc/nginx/nginx.conf` has a `server` block for port 80 that would clash with SSVS's. This puts a `#` in front of each of its lines:

   ```
   sed -i '/^    server {/,/^    }/ s/^/#/' /etc/nginx/nginx.conf
   ```

5. Copy the first of SSVS's two nginx files, which serves plain HTTP only (sending visitors on to HTTPS) and needs no certificate yet:

   ```
   cp /var/www/ssvs/deploy/nginx/ssvs-http.conf /etc/nginx/conf.d/ssvs-http.conf
   ```

   Open it with `nano /etc/nginx/conf.d/ssvs-http.conf` and change `ssvs.example.org` to your site's name.

6. Check the files and start nginx:

   ```
   nginx -t
   systemctl enable --now nginx
   ```

   `nginx -t` has to say `syntax is ok` and `test is successful`.

7. Get the certificate:

   ```
   certbot certonly --nginx -d ssvs.example.org --deploy-hook "systemctl reload nginx"
   ```

   Certbot asks for an email address (for expiry warnings) and for you to agree to Let's Encrypt's terms. `certonly` means it gets the certificate without editing the nginx files. `--deploy-hook` is remembered, so nginx reloads with the new certificate every time Certbot renews it.

8. Copy the second nginx file, the HTTPS site:

   ```
   cp /var/www/ssvs/deploy/nginx/ssvs.conf /etc/nginx/conf.d/ssvs.conf
   ```

   Open it with `nano /etc/nginx/conf.d/ssvs.conf` and change `ssvs.example.org` to your site's name everywhere it appears, including the two certificate paths.

9. Check and reload:

   ```
   nginx -t
   systemctl reload nginx
   ```

10. Make sure the certificate will renew by itself. Certificates last 90 days, and Certbot's timer renews them.

    ```
    systemctl enable --now certbot-renew.timer
    certbot renew --dry-run
    ```

    The dry run should end with `Congratulations, all simulated renewals succeeded`.

Now open your site's address in a browser, and the invitation link from step 4 to finish setting up your account.

Requests for any other name, such as someone typing the server's IP (Internet Protocol) address, get no answer at all. That's on purpose: only your site's own name reaches the app.

Checked on a real AlmaLinux 10 server (the Certbot steps): not yet.

## 7. The scheduler

SSVS has jobs that run on their own: making new shifts each night, the cover request emails as an open shift gets closer, change heads-ups and shift reminders. A systemd timer runs them every minute.

1. Copy the timer and its service, and turn the timer on:

   ```
   cp /var/www/ssvs/deploy/systemd/ssvs-scheduler.service /var/www/ssvs/deploy/systemd/ssvs-scheduler.timer /etc/systemd/system/
   systemctl daemon-reload
   systemctl enable --now ssvs-scheduler.timer
   ```

2. Check that it's waiting for its next run:

   ```
   systemctl list-timers ssvs-scheduler.timer
   ```

3. After a minute, the install check should show when it last ran:

   ```
   cd /var/www/ssvs
   runuser -u ssvs -- php artisan ssvs:check-install
   ```

   Look for `OK  Scheduler ran at` and a time in UTC (Coordinated Universal Time). If it says the scheduler has never run, the timer isn't on.

SSVS sends each email as it goes and queues nothing, so there's no queue worker to run.

## 8. Backups

A backup runs every night at about 02:30 (server time). It saves the whole database, compressed and encrypted, to `/var/backups/ssvs`, and keeps the last 14 days.

The backups are encrypted with a GnuPG key whose private half stays on your own computer, not the server. Someone who gets into the server can't read old backups, and you can still read them if the server is lost.

### Make the backup key, on your own computer

1. On your own computer (not the server), make the key. It asks for a passphrase: choose a strong one and store it in your password manager.

   ```
   gpg --quick-generate-key "SSVS backups <backups@example.org>" default default never
   ```

2. Save both halves:

   ```
   gpg --armor --export backups@example.org > ssvs-backups-public.asc
   gpg --armor --export-secret-keys backups@example.org > ssvs-backups-private.asc
   ```

   Keep `ssvs-backups-private.asc` somewhere safe that isn't the server, such as your password manager or an encrypted drive, and make sure a second person in your organization can reach it. Without it, nobody can read the backups.

3. Copy only the public half to the server. You log in as your own user, not root, so copy it to your home folder first, for example with `scp ssvs-backups-public.asc jordan@ssvs.example.org:` (with your own login name in place of jordan). Then, on the server as root, move it into root's folder:

   ```
   mv /home/jordan/ssvs-backups-public.asc /root/
   ```

   Copy any other file to the server the same way: to your home folder with `scp`, then `mv` as root.

### Set up the backups, on the server

1. Import the public key into root's keyring:

   ```
   gpg --import /root/ssvs-backups-public.asc
   gpg --list-keys backups@example.org
   ```

   The fingerprint `gpg --list-keys` shows has to match the one `gpg --fingerprint backups@example.org` shows on your own computer.

2. Copy the backup settings, and change `SSVS_BACKUP_RECIPIENT` if your key uses an address other than backups@example.org:

   ```
   install -d -m 0700 /etc/ssvs
   cp /var/www/ssvs/deploy/backup.conf.example /etc/ssvs/backup.conf
   nano /etc/ssvs/backup.conf
   ```

3. Install the backup and restore scripts:

   ```
   install -m 0755 /var/www/ssvs/deploy/bin/ssvs-backup /var/www/ssvs/deploy/bin/ssvs-restore /usr/local/sbin/
   ```

4. Copy the nightly timer and its service, and turn the timer on:

   ```
   cp /var/www/ssvs/deploy/systemd/ssvs-backup.service /var/www/ssvs/deploy/systemd/ssvs-backup.timer /etc/systemd/system/
   systemctl daemon-reload
   systemctl enable --now ssvs-backup.timer
   ```

5. Make the first backup now, and check it worked:

   ```
   systemctl start ssvs-backup.service
   journalctl -u ssvs-backup.service -n 20
   ls -l /var/backups/ssvs
   ```

   The log should say `wrote /var/backups/ssvs/ssvs-` followed by the date, and the folder should hold that file. The backup refuses to keep a dump that's too small or doesn't end the way a complete dump does, and says so in the log.

### Keep a copy of `.env` off the server

Copy `/var/www/ssvs/.env` to the same safe place as the private key. It holds `APP_KEY`, which encrypts every coordinator's and admin's two-factor secrets and recovery codes. A backup restored under a different key loads fine, but every two-factor login in it stops working. Keep this copy up to date whenever you change `.env`.

### Off the server

The backups stay on the server for now. A copy off the server is recommended, not required: until SSVS can send its backups somewhere else ([#37](https://github.com/bennyvasquez/seriously-simple-volunteer-scheduler/issues/37) decides how, and how SSVS reminds admins), your hosting company's snapshot backups are the copy that survives if the server is lost. If you'd like one now, copy the newest file from `/var/backups/ssvs` to your own computer every so often. It's encrypted, so it's safe to keep anywhere.

## 9. Restoring

`ssvs-restore` replaces the whole database with a backup. It checks the backup can be read, asks you to type `ssvs` to confirm, and checks the backup again in case the key locked while it waited. Then it stops the scheduler timer, puts the site in maintenance mode, makes a rollback backup of the database as it is, restores, runs any new migrations, clears the scheduler's old locks that came back with the backup, brings the site back, starts the scheduler again (running it once right away), and runs the install check. If a step fails before the database is dropped, it says nothing in the database was changed and brings the site back up.

The backup has to be decrypted on the server, so the private key has to be there while you restore, and only then.

1. Copy `ssvs-backups-private.asc` to the server's `/root` (through your home folder, as in step 8), and import it. GnuPG asks for the key's passphrase:

   ```
   gpg --pinentry-mode loopback --import /root/ssvs-backups-private.asc
   rm /root/ssvs-backups-private.asc
   ```

2. Choose the backup. To restore one of the server's own recent backups, use its path in `/var/backups/ssvs` (`ls -l /var/backups/ssvs` lists them). To restore a file you copied in from somewhere else, put it in its own folder outside `/var/backups/ssvs`, such as `/root/ssvs-restore`. The rollback backup lands in `/var/backups/ssvs` and clears out files there older than 14 days, which could include an old file you copied in.

   ```
   install -d -m 0700 /root/ssvs-restore
   ```

3. Unlock the key for the next few minutes, by decrypting the backup once and throwing the result away. GnuPG asks for the passphrase on the terminal, and the restore then uses the unlocked key:

   ```
   gpg --pinentry-mode loopback --decrypt /root/ssvs-restore/ssvs-2026-11-27-023000.sql.gz.gpg > /dev/null
   ```

4. Restore it, with your own file's name:

   ```
   ssvs-restore /root/ssvs-restore/ssvs-2026-11-27-023000.sql.gz.gpg
   ```

   If the backup can't be read (most often because the key isn't unlocked), it stops before changing anything. If something fails after the site goes into maintenance mode, it says what failed and where the rollback backup is.

5. Remove the private key from the server again. `gpg --list-secret-keys backups@example.org` shows its fingerprint, the long line of letters and numbers; use it in place of `FINGERPRINT`:

   ```
   gpg --batch --yes --delete-secret-keys FINGERPRINT
   ```

The `.env` on the server has to have the same `APP_KEY` the backup was made under, or two-factor logins stop working (step 8).

### Test a restore before launch

Do a restore once on a spare server before launch, as the spec asks (section 13), so you know the steps and the key work before you need them. Your test copy (step 13) is a good spare server, since you need one for it anyway: make a backup there with `systemctl start ssvs-backup.service`, change something you'll notice (the organization's name in Settings, say), restore that backup, and check the change is gone.

If you ever practice on a fresh spare server instead, follow steps 1 to 6 and 8 there, skipping step 7 so its scheduler never runs, use the `APP_KEY` from your off-server copy of `.env`, and leave the mail settings unfilled, so it sends nobody any email. Afterward, shut it down and delete it, so its copy of the data and the key don't linger.

## 10. Checking the install

1. Run the install check. It says what's right and how to fix anything that isn't, and it's safe to run any time:

   ```
   cd /var/www/ssvs
   runuser -u ssvs -- php artisan ssvs:check-install
   ```

   Every line should start with `OK`. A line starting with `FIX` says what to change.

2. Check that the site answers. This is the address a monitoring service should watch:

   ```
   curl -H 'Host: ssvs.example.org' https://ssvs.example.org/up
   ```

   It should print `OK`.

3. Check that a request for another name gets no answer, using your server's IP address in place of 203.0.113.10:

   ```
   curl -k https://203.0.113.10/up
   curl http://203.0.113.10/up
   ```

   The first should fail with a TLS error that says `unrecognized name`, and the second with `Empty reply from server`. Neither should print `OK`.

## 11. Updating

Read the release notes first. They call out security fixes and anything about the update that needs extra steps.

```
cd /var/www/ssvs
systemctl stop ssvs-scheduler.timer
runuser -u ssvs -- php artisan down
systemctl start ssvs-backup.service
runuser -u ssvs -- git pull
runuser -u ssvs -- composer install --no-dev --optimize-autoloader
runuser -u ssvs -- php artisan config:cache
runuser -u ssvs -- php artisan migrate --force
runuser -u ssvs -- php artisan route:cache
runuser -u ssvs -- php artisan view:cache
systemctl reload php-fpm
runuser -u ssvs -- php artisan up
systemctl start ssvs-scheduler.timer
```

The order matters. The backup right after maintenance mode starts is your way back if the update goes wrong. The settings are cached before the migrations so they run with the new code's settings. The migrations have to run before anything else uses the new code, because every email reads its wording from the database and fails if the code is newer than the tables. Stopping the scheduler timer first and starting it last keeps a scheduled job from sending email in between. Reloading PHP-FPM makes it pick up the new code.

If the release notes say a file in `deploy/` changed, copy it again the way its step did, and change ssvs.example.org to your name again where it appears.

## 12. Behind a proxy

`TRUSTED_PROXIES` left empty for the standard AlmaLinux layout, nginx talking to PHP-FPM over FastCGI. That layout has no proxy: PHP already gets the visitor's address and whether the connection is HTTPS from the parameters nginx passes. The setting is only for an HTTP proxy or load balancer in front of the app (nginx using `proxy_pass` to a separate PHP app server, Caddy, HAProxy, a cloud load balancer). In that case set it to the proxy's address (a comma-separated list, for example 127.0.0.1 when the proxy runs on the same server) and make the proxy send the `X-Forwarded-For` and `X-Forwarded-Proto` headers, so the app believes the visitor address and scheme they report. `*` trusts every sender, must be the whole value, and is only for an app that can be reached only through the proxy. Left empty, those headers are ignored.

If you set it, run `cd /var/www/ssvs` and then `runuser -u ssvs -- php artisan config:cache` afterward.

## 13. Moving from a test copy

The spec's launch plan (section 17) has coordinators enter the real schedule in a test copy first. When you move to the live install, the whole database comes along, and so does the test copy's `APP_KEY`, because it protects the two-factor secrets in that database.

1. Install the live server with steps 1 to 8, but skip `ssvs:setup` in step 4, because the test copy's database already has the organization and its admins.
2. In the live server's `.env`, replace the `APP_KEY` line with the one from the test copy's `.env`, then run `cd /var/www/ssvs` and `runuser -u ssvs -- php artisan config:cache`.
3. On the test copy, stop the scheduler and put the site in maintenance mode, so nobody edits anything and no email goes out after the backup, then make the final backup:

   ```
   cd /var/www/ssvs
   systemctl stop ssvs-scheduler.timer
   runuser -u ssvs -- php artisan down
   systemctl start ssvs-backup.service
   ```

4. On the live server, make the folder for it:

   ```
   install -d -m 0700 /root/ssvs-restore
   ```

   Then copy the new file from the test copy's `/var/backups/ssvs` into `/root/ssvs-restore` on the live server (through your home folder, as in step 8).
5. Restore it on the live server, as in step 9.
6. Sign in on the live site and check the schedule and people are there.
7. Copy the live server's `.env` to your safe place off the server, replacing your old copy (step 8), and make a first live backup with `systemctl start ssvs-backup.service` on the live server. Do this before the next step, because the next step deletes the test copy's `.env`.
8. Then shut the test copy down and delete its database and `.env`, so the key that protects two-factor secrets lives only on the live install and in your off-server copy of its `.env`.
