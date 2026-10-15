-- Tests run against their own database so they never touch development data.
CREATE DATABASE IF NOT EXISTS ssvs_test;
GRANT ALL PRIVILEGES ON ssvs_test.* TO 'ssvs'@'%';
