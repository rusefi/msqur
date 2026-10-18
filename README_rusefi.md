
# rusEFI msqur branch strategy

We use master branch only to pull in upstream repository. We do not change master.

We create pull requests into upstream from dev branches. 

"prod" branch is what rusEFI uses in production. Any custom rusEfi code which we do not intend to push upstream
should live in this branch.

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

