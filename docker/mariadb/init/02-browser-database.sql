-- Browser tests (Laravel Dusk) run against their own database, so they never touch development data or the PHPUnit database.
CREATE DATABASE IF NOT EXISTS ssvs_browser;
GRANT ALL PRIVILEGES ON ssvs_browser.* TO 'ssvs'@'%';
