
# rusEFI msqur branch strategy

We use master branch only to pull in upstream repository. We do not change master.

We create pull requests into upstream from dev branches. 

"prod" branch is what rusEFI uses in production. Any custom rusEfi code which we do not intend to push upstream
should live in this branch.

## Tests

CI runs on PHP 8.3 and 8.4. Enable XML/DOM, mbstring, bcmath, PDO SQLite, and
fileinfo, and install [PHPUnit 12.5](https://docs.phpunit.de/en/12.5/installation.html)
as `phpunit` on `PATH`. The repository does not bundle a PHPUnit executable.

```bash
cp -n src/config_rusefi.php.sample src/config_rusefi.php
php tests/check_config.php
php tests/check_ini.php
php tests/check_view.php
php tests/check_tune_crc.php
bash tests/run_tests.sh
```

The PHPUnit suite uses an in-memory SQLite database and local fixtures. Its
configuration allows 512 MB for the large log fixture and fails on warnings,
deprecations, and risky tests. Additional PHPUnit options can be passed through
the runner, for example `bash tests/run_tests.sh --filter UploadTest`.

## PHP upgrades

`deploy.sh` excludes `config.php` (see `deploy_excludes.txt`), so deploying the code
does not update the server's configuration. Apply the assertion configuration from
`src/config.php` to the server's `online/config.php` separately, preserving local
settings and credentials. Remove the old `assert_options(...)` calls, the
`msqur_assert_handler` function, and any `set_exception_handler` registration for
it: `ASSERT_QUIET_EVAL` was removed in PHP 8.0, and
`assert_options()` is deprecated in PHP 8.3 and later. PHP handles uncaught
assertions and exceptions directly.

Run `php tests/check_config.php` to check both configuration variants without a
database. The check also covers hosts that set `zend.assertions=-1` in `php.ini`.

