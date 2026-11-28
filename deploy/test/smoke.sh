#!/bin/bash
# Smoke test for docs/install.md. Run it from anywhere on a machine with Docker: bash deploy/test/smoke.sh
# It builds a throwaway AlmaLinux 10 image with systemd running, starts it privileged with this repository mounted read-only at /src, and inside it follows the guide's steps 1 (dnf-automatic only), 2, 3, 4, 5 (ownership only), 6 (a self-signed certificate instead of Certbot), 7, 8, 9, 10 and 11. Then it checks the results. It needs network access for dnf, Composer and Packagist, takes several minutes, and isn't part of CI.
# Every command a step runs is copied from the guide, and tests/Unit/DeployFilesTest.php checks that each command in the guide's code blocks appears here, except the few it lists. When a guide command changes, change it here too. The differences, each marked TEST ONLY below:
#   - git clones /src instead of GitHub, then copies the working tree's tracked and new files over the clone, so uncommitted changes are tested too.
#   - dnf is told to answer yes, and the other interactive answers (mariadb-secure-installation, editing .env with nano, gpg's passphrase, typing ssvs at the restore prompt) are piped in, through a pseudo-terminal from script where the program needs one. The CREATE USER line has the password openssl made in place of the placeholder, and the key's fingerprint stands in for FINGERPRINT.
#   - "Your own computer" is a second gpg keyring in /root/own-computer, so only the public key is on the server until the restore imports the private one.
#   - Certbot can't get a certificate for ssvs.example.org, so a self-signed one is written to the path Certbot would use and trusted, and the container gets the guide's example IP address (203.0.113.10) so the "another name" checks run as written.
#   - SELinux and firewalld don't work inside a container, so those steps are skipped. The guide marks them as checked on a real server separately.
# Set SMOKE_KEEP=1 to keep the container afterward for poking at.
set -euo pipefail

if [[ "${1:-}" != "--inside" ]]; then
    repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
    # The same almalinux:10 digest the development image uses, so both move together.
    from="$(grep -m 1 -E '^FROM almalinux:10@sha256:[0-9a-f]{64}' "$repo/docker/app/Dockerfile" | awk '{print $2}')"
    if [[ -z "$from" ]]; then
        echo "smoke: couldn't find the almalinux:10 digest in docker/app/Dockerfile." >&2
        exit 1
    fi
    image=ssvs-smoke
    name="ssvs-smoke-$$"

    cleanup() {
        if [[ "${SMOKE_KEEP:-}" == 1 ]]; then
            echo "smoke: kept the container $name (docker rm -f $name to remove it)."
        else
            docker rm -f "$name" > /dev/null 2>&1 || true
        fi
    }
    trap cleanup EXIT

    echo "smoke: building $image from $from"
    docker build -q -t "$image" - > /dev/null <<EOF
FROM $from
RUN dnf -y install systemd && dnf clean all
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
EOF

    docker run -d --name "$name" --privileged \
        --add-host ssvs.example.org:127.0.0.1 --add-host other.example:127.0.0.1 \
        -v "$repo:/src:ro" "$image" > /dev/null

    for _ in $(seq 1 30); do
        state="$(docker exec "$name" systemctl is-system-running 2> /dev/null || true)"
        [[ "$state" == running || "$state" == degraded ]] && break
        sleep 1
    done
    echo "smoke: systemd is $state in $name"

    docker exec "$name" bash /src/deploy/test/smoke.sh --inside
    exit $?
fi

# Everything below runs inside the container, as root.

failures=0
step() { printf '\n=== %s\n' "$*"; }
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; failures=$((failures + 1)); }
check() {
    local what="$1"
    shift
    if "$@"; then pass "$what"; else fail "$what"; fi
}

export TERM=dumb
# TEST ONLY: the guide's dnf commands ask before installing. Answer yes for them here.
echo assumeyes=1 >> /etc/dnf/dnf.conf

step "1. Updates (dnf-automatic only; firewalld is skipped in a container)"
dnf install dnf-automatic
sed -i -e 's/^upgrade_type = .*/upgrade_type = security/' -e 's/^apply_updates = .*/apply_updates = yes/' /etc/dnf/automatic.conf
systemctl enable --now dnf-automatic.timer
check "dnf-automatic installs security updates" test "$(grep -E '^(upgrade_type|apply_updates)' /etc/dnf/automatic.conf | tr '\n' ' ')" = "upgrade_type = security apply_updates = yes "

step "2. Packages"
dnf config-manager --set-enabled crb
dnf install epel-release
dnf install nginx php-fpm php-cli php-common php-mbstring php-mysqlnd php-pdo php-xml php-intl php-bcmath php-process php-opcache mariadb-server git unzip gnupg2 tar openssl certbot python3-certbot-nginx policycoreutils-python-utils nano

step "3. The database"
cat > /etc/my.cnf.d/ssvs.cnf <<'EOF'
[mysqld]
skip-networking
EOF
systemctl enable --now mariadb
# TEST ONLY: the answers the guide gives, piped in: Enter, n, n, then Y four times.
printf '\nn\nn\nY\nY\nY\nY\n' | mariadb-secure-installation > /tmp/secure-installation.log 2>&1
tr -d '\r' < /tmp/secure-installation.log | grep -E '\?|Enter|\[Y/n\]' || true
check "mariadb-secure-installation finished" grep -q "Thanks for using MariaDB" /tmp/secure-installation.log
db_password="$(openssl rand -base64 24)"
# TEST ONLY: the three lines from the guide, with the password openssl made, fed to the client the guide starts.
mariadb <<EOF
CREATE DATABASE ssvs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ssvs'@'localhost' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON ssvs.* TO 'ssvs'@'localhost';
EOF
check "MariaDB has networking turned off" test "$(mariadb -N -e 'SELECT @@skip_networking')" = 1
check "the test database is gone" test -z "$(mariadb -N -e "SHOW DATABASES LIKE 'test'")"

step "4. The app"
useradd --system --shell /sbin/nologin --home-dir /var/lib/ssvs --create-home ssvs
install -d -o ssvs -g ssvs /var/www/ssvs
# TEST ONLY: /src belongs to another user, so git needs telling it's safe, and the clone comes from /src instead of GitHub.
runuser -u ssvs -- git config --global --add safe.directory '*'
git config --global --add safe.directory '*'
runuser -u ssvs -- git clone /src /var/www/ssvs
# TEST ONLY: copy the working tree's tracked and new files over the clone, so uncommitted changes are what gets tested.
(cd /src && git ls-files -z --cached --others --exclude-standard | tar --null --ignore-failed-read -T - -cf - 2> /dev/null) | runuser -u ssvs -- tar -xf - -C /var/www/ssvs
cd /root
curl -sS -o composer-setup.php https://getcomposer.org/installer
echo "$(curl -sS https://composer.github.io/installer.sig)  composer-setup.php" | sha384sum -c -
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
cd /var/www/ssvs
runuser -u ssvs -- composer install --no-dev --optimize-autoloader
check "Dusk isn't installed" test ! -d /var/www/ssvs/vendor/laravel/dusk
install -m 0600 -o ssvs -g ssvs deploy/env.production.example .env
# TEST ONLY: the guide edits .env with nano. The smoke test fills in only the database password; the mail settings stay as placeholders.
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=\"$db_password\"|" .env
runuser -u ssvs -- php artisan key:generate --force
runuser -u ssvs -- php artisan migrate --force
runuser -u ssvs -- php artisan config:cache
runuser -u ssvs -- php artisan route:cache
runuser -u ssvs -- php artisan view:cache
original_name="Example Makerspace"
runuser -u ssvs -- php artisan ssvs:setup --organization="Example Makerspace" --timezone=America/Detroit --admin-name="Jordan Example" --admin-email=jordan@example.org
check "the organization was set up" test "$(mariadb -N -e 'SELECT name FROM ssvs.organizations')" = "$original_name"

step "5. File permissions (SELinux is skipped in a container)"
chown -R ssvs:ssvs /var/www/ssvs
chmod 0600 /var/www/ssvs/.env
check ".env is readable only by ssvs" test "$(stat -c '%U %a' /var/www/ssvs/.env)" = "ssvs 600"

step "6. nginx, PHP-FPM and HTTPS (a self-signed certificate instead of Certbot)"
cp /var/www/ssvs/deploy/php-fpm/ssvs.conf /etc/php-fpm.d/ssvs.conf
echo '; Turned off: SSVS uses its own pool in ssvs.conf.' > /etc/php-fpm.d/www.conf
systemctl enable --now php-fpm
sed -i '/^    server {/,/^    }/ s/^/#/' /etc/nginx/nginx.conf
check "the stock nginx server block is commented out" test -z "$(grep -E '^[[:space:]]*server_name' /etc/nginx/nginx.conf)"
cp /var/www/ssvs/deploy/nginx/ssvs-http.conf /etc/nginx/conf.d/ssvs-http.conf
check "nginx -t passes with only the HTTP file" nginx -t -q
systemctl enable --now nginx
# TEST ONLY: in place of certbot certonly, a self-signed certificate where Certbot would put it.
install -d /etc/letsencrypt/live/ssvs.example.org
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=ssvs.example.org -addext subjectAltName=DNS:ssvs.example.org \
    -keyout /etc/letsencrypt/live/ssvs.example.org/privkey.pem -out /etc/letsencrypt/live/ssvs.example.org/fullchain.pem 2> /dev/null
# TEST ONLY: trust the self-signed certificate, so the guide's curl commands work as written, and give the container the guide's example IP address (203.0.113.10).
cp /etc/letsencrypt/live/ssvs.example.org/fullchain.pem /etc/pki/ca-trust/source/anchors/ssvs-smoke.pem
update-ca-trust
dnf install iproute
ip addr add 203.0.113.10/32 dev lo
cp /var/www/ssvs/deploy/nginx/ssvs.conf /etc/nginx/conf.d/ssvs.conf
check "nginx -t passes with both files" nginx -t -q
systemctl reload nginx
systemctl enable --now certbot-renew.timer
check "PHP-FPM runs no stock www pool" test -z "$(ps -o user= -C php-fpm | grep -v -x -e root -e ssvs)"

step "7. The scheduler"
cd /root
cp /var/www/ssvs/deploy/systemd/ssvs-scheduler.service /var/www/ssvs/deploy/systemd/ssvs-scheduler.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ssvs-scheduler.timer
systemctl list-timers ssvs-scheduler.timer
cd /var/www/ssvs
heartbeat=no
for _ in $(seq 1 18); do
    out="$(runuser -u ssvs -- php artisan ssvs:check-install || true)"
    if grep -q '^OK  Scheduler ran at' <<< "$out"; then
        heartbeat=yes
        break
    fi
    sleep 5
done
check "the scheduler ran and the heartbeat showed within 90 seconds" test "$heartbeat" = yes

step "8. Backups (the key is made in a stand-in for your own computer)"
# TEST ONLY: /root/own-computer stands in for your own computer's keyring. There's no terminal to type the passphrase into, so it's given on the command line (loopback). The export commands are the guide's.
own=/root/own-computer
install -d -m 0700 "$own"
passphrase=smoke-test-passphrase
GNUPGHOME="$own" gpg --batch --pinentry-mode loopback --passphrase "$passphrase" --quick-generate-key "SSVS backups <backups@example.org>" default default never 2> /dev/null
cd /root
GNUPGHOME="$own" gpg --armor --export backups@example.org > ssvs-backups-public.asc
GNUPGHOME="$own" gpg --batch --pinentry-mode loopback --passphrase "$passphrase" --armor --export-secret-keys backups@example.org > ssvs-backups-private.asc
mv ssvs-backups-private.asc "$own/"
# From here on, the server's own commands, as the guide gives them.
gpg --import /root/ssvs-backups-public.asc
gpg --list-keys backups@example.org
check "the server has no private key" test -z "$(gpg --list-secret-keys 2> /dev/null)"
install -d -m 0700 /etc/ssvs
cp /var/www/ssvs/deploy/backup.conf.example /etc/ssvs/backup.conf
install -m 0755 /var/www/ssvs/deploy/bin/ssvs-backup /var/www/ssvs/deploy/bin/ssvs-restore /usr/local/sbin/
cp /var/www/ssvs/deploy/systemd/ssvs-backup.service /var/www/ssvs/deploy/systemd/ssvs-backup.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ssvs-backup.timer
systemctl start ssvs-backup.service
journalctl -u ssvs-backup.service -n 20
ls -l /var/backups/ssvs
backup="$(find /var/backups/ssvs -maxdepth 1 -name 'ssvs-*.sql.gz.gpg' | head -n 1)"
check "the backup service wrote an encrypted backup" test -n "$backup"
check "the backup folder is private" test "$(stat -c '%a' /var/backups/ssvs)" = 700
check "the backup file is private" test "$(stat -c '%a' "$backup")" = 600

step "9. Restoring"
# TEST ONLY: change the organization's name, so the restore has something to undo.
mariadb -e "UPDATE ssvs.organizations SET name = 'Changed After The Backup'"
# TEST ONLY: copying the private key in from your own computer.
cp "$own/ssvs-backups-private.asc" /root/
# TEST ONLY: gpg reads the passphrase from the terminal, so script gives it a pseudo-terminal and the passphrase arrives on it once gpg is ready for it.
(sleep 3; echo "$passphrase"; sleep 3) | script -qec "gpg --pinentry-mode loopback --import /root/ssvs-backups-private.asc" /dev/null > /tmp/import.log 2>&1
tr -d '\r' < /tmp/import.log
rm /root/ssvs-backups-private.asc
check "the private key was imported" test -n "$(gpg --list-secret-keys backups@example.org 2> /dev/null)"
install -d -m 0700 /root/ssvs-restore
# TEST ONLY: the guide's example file name, so its commands run as written. The backup is copied in, as a backup from elsewhere would be.
cp "$backup" /root/ssvs-restore/ssvs-2026-11-27-023000.sql.gz.gpg
gpgconf --kill gpg-agent
set +e
printf 'ssvs\n' | script -qec "ssvs-restore /root/ssvs-restore/ssvs-2026-11-27-023000.sql.gz.gpg" /dev/null > /tmp/locked.log 2>&1
locked_status=$?
set -e
check "a restore with the key still locked stops before changing anything" grep -q "Nothing was changed" /tmp/locked.log
check "and exits 1" test "$locked_status" = 1
check "and leaves the changed name in place" test "$(mariadb -N -e 'SELECT name FROM ssvs.organizations')" = "Changed After The Backup"
(sleep 3; echo "$passphrase"; sleep 3) | script -qec "gpg --pinentry-mode loopback --decrypt /root/ssvs-restore/ssvs-2026-11-27-023000.sql.gz.gpg > /dev/null" /dev/null > /tmp/unlock.log 2>&1
# TEST ONLY: ssvs-restore needs a terminal and a typed "ssvs". script gives it a pseudo-terminal and the answer comes in on it.
set +e
printf 'ssvs\n' | script -qec "ssvs-restore /root/ssvs-restore/ssvs-2026-11-27-023000.sql.gz.gpg" /dev/null > /tmp/restore.log 2>&1
restore_status=$?
set -e
tr -d '\r' < /tmp/restore.log
check "the restore asked for confirmation" grep -q "type the database name" /tmp/restore.log
check "the restore made a rollback backup" grep -q "rollback backup is /var/backups/ssvs/" /tmp/restore.log
check "the restore brought the site back up" grep -q "the site is back up" /tmp/restore.log
check "the restore ended with the install check's email finding (exit 1)" test "$restore_status" = 1
check "the restore brought the old name back" test "$(mariadb -N -e 'SELECT name FROM ssvs.organizations')" = "$original_name"
check "the site isn't in maintenance mode" test ! -e /var/www/ssvs/storage/framework/down
check "the rollback backup didn't replace the server's own backup" test "$(find /var/backups/ssvs -maxdepth 1 -name 'ssvs-*.sql.gz.gpg' | wc -l)" = 2
fingerprint="$(gpg --with-colons --list-secret-keys backups@example.org | awk -F: '/^fpr/ { print $10; exit }')"
# TEST ONLY: the fingerprint gpg --list-secret-keys shows, in place of FINGERPRINT.
gpg --list-secret-keys backups@example.org
gpg --batch --yes --delete-secret-keys "$fingerprint"
check "the private key is gone from the server again" test -z "$(gpg --list-secret-keys 2> /dev/null)"

step "10. Checking the install"
cd /var/www/ssvs
set +e
runuser -u ssvs -- php artisan ssvs:check-install > /tmp/check.log
check_status=$?
set -e
cat /tmp/check.log
check "ssvs:check-install fails (no SMTP in the test)" test "$check_status" = 1
check "the only FIX lines are about email" test -z "$(grep '^FIX' /tmp/check.log | grep -v '^FIX Email:')"
check "it asks for a real from address" grep -q '^FIX Email: MAIL_FROM_ADDRESS' /tmp/check.log
for line in "Environment is production" "App key is set" "Site address is https://ssvs.example.org" "Sessions are in the database with secure cookies" "Database is reachable and up to date" "Organization is set up" "storage and bootstrap/cache are writable" "Scheduler ran at"; do
    check "check-install: $line" grep -q "^OK  $line" /tmp/check.log
done
cacert=/etc/letsencrypt/live/ssvs.example.org/fullchain.pem
up="$(curl -H 'Host: ssvs.example.org' https://ssvs.example.org/up)"
check "/up answers OK for ssvs.example.org" test "$up" = OK
check "port 80 sends ssvs.example.org to HTTPS" test "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' http://ssvs.example.org/up)" = "301 https://ssvs.example.org/up"
home="$(curl -s -o /dev/null -w '%{http_code}' --cacert "$cacert" https://ssvs.example.org/)"
check "the home page answers (not 5xx)" test "${home:0:1}" != 5
set +e
other_https="$(curl -sS --cacert "$cacert" https://other.example/up 2>&1)"
other_https_status=$?
other_http="$(curl -sS -H 'Host: other.example' http://127.0.0.1/up 2>&1)"
other_http_status=$?
other_ip="$(curl -k https://203.0.113.10/up 2>&1)"
other_ip_status=$?
other_ip_http="$(curl http://203.0.113.10/up 2>&1)"
other_ip_http_status=$?
other_host="$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: other.example' https://ssvs.example.org/up 2>&1)"
other_host_status=$?
set -e
echo "other.example over HTTPS: exit $other_https_status, $other_https"
echo "other.example over HTTP: exit $other_http_status, $other_http"
echo "the bare address over HTTPS: exit $other_ip_status, $other_ip"
echo "the bare address over HTTP: exit $other_ip_http_status, $other_ip_http"
echo "Host: other.example on the ssvs.example.org connection: exit $other_host_status, $other_host"
check "HTTPS for other.example gets no reply (handshake refused)" test "$other_https_status" = 35
check "HTTP for other.example gets an empty reply" test "$other_http_status" = 52
check "HTTPS to the bare address gets no reply (handshake refused)" test "$other_ip_status" = 35
check "HTTP to the bare address gets an empty reply" test "$other_ip_http_status" = 52
check "Host: other.example on a good connection gets 421 Misdirected Request" test "$other_host" = 421

step "11. Updating"
cd /var/www/ssvs
systemctl stop ssvs-scheduler.timer
runuser -u ssvs -- php artisan down
runuser -u ssvs -- git pull
runuser -u ssvs -- composer install --no-dev --optimize-autoloader
runuser -u ssvs -- php artisan migrate --force
runuser -u ssvs -- php artisan config:cache
runuser -u ssvs -- php artisan route:cache
runuser -u ssvs -- php artisan view:cache
systemctl reload php-fpm
runuser -u ssvs -- php artisan up
systemctl start ssvs-scheduler.timer
check "/up answers OK after the update" test "$(curl -H 'Host: ssvs.example.org' https://ssvs.example.org/up)" = OK
check "the scheduler timer is back on" systemctl is-active -q ssvs-scheduler.timer

echo
if (( failures > 0 )); then
    echo "smoke: $failures check(s) failed."
    exit 1
fi
echo "smoke: every check passed."
