# Repository Guidelines

## Project Structure & Module Organization

`src/` contains PHP request handlers and application classes: `msqur.php` coordinates actions,
`db.php` handles persistence, and `ini.php`, `msq.php`, and `mlg.format.php` parse
files. Templates, JavaScript, CSS, images, and bundled libraries live in
`src/view/`; firmware definitions live in `src/ini/`. SQL schemas and migrations
are in `db/`. Tests and fixtures are in `tests/` and `tests/testcases/`.

Sibling repositories: `../web_backend` contains rusEFI web infrastructure;
`../rusefi` contains rusEFI firmware and tuning tools. Use `../rusefi` as a
reference when investigating firmware definitions, tune-file compatibility, or
parser behavior. Read `../rusefi/AGENTS.md` before working in that repository, and
consult each sibling's contributor guidance when investigating integrations or
changing shared interfaces.

## Build, Test, and Development Commands

There is no application build step or root Composer/npm workflow. Configure a local
MySQL/MariaDB database and enable the relevant PHP extensions, including PDO,
SimpleXML/XML, mbstring, and bcmath.

- `cp -n src/config_rusefi.php.sample src/config_rusefi.php`: create local settings
  without overwriting an existing file; then edit database credentials.
- `(cd src && php -S 127.0.0.1:8000)`: serve the application locally.
- `php tests/check_config.php`: check configuration startup and exception reporting.
- `php tests/check_ini.php`: check parser behavior and exception compatibility.
- `php tests/check_tune_crc.php`: check tune CRCs and upload/duplicate handling
  using local fixtures and an in-memory SQLite database (requires PDO SQLite).
- `(cd tests && bash run_tests.sh)`: run the legacy PHPUnit suite using PHP 7.3
  and its XML, mbstring, bcmath, and SQLite extensions, matching CI. The bundled
  PHPUnit executable is incompatible with PHP 8.
- `php -l src/ini.php`: check syntax; substitute each changed PHP file.

## Coding Style & Naming Conventions

Follow surrounding formatting. Legacy PHP generally uses tabs; standalone checks
use four spaces. Preserve existing brace placement. Use descriptive camelCase
methods, existing class naming patterns, and uppercase configuration constants.
Avoid unrelated reformatting or edits to bundled libraries. Legacy ESLint and
CSSLint configurations exist; no PHP formatter is configured.

## Testing Guidelines

Run relevant checks before submitting changes. Modern checks run on PHP 8.3 and
8.4 in CI. PHPUnit tests use `test_*.php`, `Test*` classes, and `test*` methods;
register new cases in `tests/phpunit.xml`. Add focused regression cases for parser
and compatibility fixes. No coverage percentage is enforced.

## Commit & Pull Request Guidelines

History uses short, descriptive subjects, often referencing issue URLs or PR
numbers; Conventional Commits are not required. Explain the problem, resulting
behavior, and validation in each PR. Link relevant issues and include screenshots
for visible UI changes. Follow `README_rusefi.md`: reserve `master` for upstream
imports, target `prod` for rusEFI changes, and use development branches for upstream
contributions.

## Configuration & Deployment

Keep credentials in ignored local configuration. Preserve production settings:
`deploy.sh` excludes `config.php`, so configuration fixes require a separate server
update. See `README_rusefi.md` for PHP migration instructions.
