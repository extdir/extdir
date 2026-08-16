-- Runs once, when the database volume is first created.
--
-- The test environment appends a _test suffix to the database name (see the
-- when@test block in doctrine.yaml), so the application user needs rights on
-- extdir_test as well as extdir. Without this, `doctrine:database:create --env=test`
-- fails with "access denied" on a fresh checkout and the functional tests cannot run.
GRANT ALL PRIVILEGES ON `extdir\_%`.* TO 'extdir'@'%';
FLUSH PRIVILEGES;
