# Tests

Everything here uses made-up data. `fixtures/sample.ged` is an invented family: three generations, with people who
are dead and people who would still be living, so the privacy rules have something to hide, and one family with
only a mother recorded. Its one source has an
author written in a form that PHP would run as code if the text were ever made part of a line of PHP; the smoke
test checks that it comes out as written.

| Script | What it does | Needs |
|---|---|---|
| `composer test` | PHPUnit tests in `Unit/` for the new code in `src/` | `composer install` |
| `composer test-mariadb` | PHPUnit tests in `MariaDb/`: what the upgrade does to tables in another character set. They make and drop tables whose names start with `cst_`, and change the database's default character set | an empty MariaDB database of its own, given as `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`; PHP `pdo_mysql` |
| `serve.sh [port]` | Runs the program from a throw-away copy with an SQLite database, on `127.0.0.1` only | PHP 8.3 with `pdo_sqlite`, `composer install` |
| `provision.php` | Creates the test administrator with `bin/yooper install`'s code and imports the sample tree through the program's own pages. Uses the SQLite database of `serve.sh`, or the one named by `DB_TYPE` and the other `DB_` variables when those are set | PHP `curl` and `dom` |
| `failed-sign-ins.php` | Counts the failed sign-ins recorded for a user name, and can forget them; used by `smoke.sh` | PHP `pdo_sqlite` |
| `proxy-check.sh` | Run inside the web container by the image job in CI: acts as a reverse proxy would | the container image |
| `make-site-old.php` | Makes the test site look as PhpGedView 4.3.0 would have left it, for trying the upgrade; used by `smoke.sh` | PHP `pdo_sqlite` |
| `rename-user.php` | Changes a user name in the test site; used by `smoke.sh` | PHP `pdo_sqlite` |
| `checks.sh` | The check functions that `smoke.sh` and `reference/moved.sh` share | sourced, not run |
| `reference/up.sh <folder>` | Builds and starts the reference site: PhpGedView 4.3.0 exactly as released (taken from this repository's history), on PHP 5.6 and MariaDB with latin1 tables, at `127.0.0.1:8098`. Loads the sample tree through its own pages and saves a database dump and the index folder: the "old site" | Docker, the full git history, PHP `pdo_mysql` |
| `reference/moved.sh <folder>` | Moves that old site to this program on a copy (`upgrade-from-pgv`, a new password) and requests the smoke test's pages from the result | the reference site running |
| `reference/compare.php` | Run by `moved.sh`: asks both sites for the smoke test's pages and compares the words a person would read. A difference must be explained in `reference/differences.php`, and an explanation there must still be needed | both sites running |
| `browser.php` | Requests pages and fills in forms as a browser would; used by `provision.php` and `reference/compare.php` | loaded, not run |
| `reference/down.sh` | Stops the reference site and deletes its database | Docker |
| `smoke.sh` | Starts and provisions a site, requests the main pages as visitor and administrator, fails on any PHP error or warning, and checks what each may see, that every POST form carries its token, and the sign-in, session, forged-request and cross-site link rules, the security headers, the lost-password link, what may be uploaded, pending edits, and moving a site over from PhpGedView | the same, plus `curl` |

The test administrator is `admin` / `test-admin-password`. A second account, `legacy`, has the same password stored
in PhpGedView's old format, to show that it is refused. Both exist only in the throw-away test site.
