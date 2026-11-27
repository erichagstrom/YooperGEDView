# Tests

Everything here uses made-up data. `fixtures/sample.ged` is an invented family: three generations, with people who
are dead and people who would still be living, so the privacy rules have something to hide. Its one source has an
author written in a form that PHP would run as code if the text were ever made part of a line of PHP; the smoke
test checks that it comes out as written.

| Script | What it does | Needs |
|---|---|---|
| `composer test` | PHPUnit tests in `Unit/` for the new code in `src/` | `composer install` |
| `serve.sh [port]` | Runs the program from a throw-away copy with an SQLite database, on `127.0.0.1` only | PHP 8.3 with `pdo_sqlite`, `composer install` |
| `provision.php` | Creates the test administrator with `bin/yooper install`'s code and imports the sample tree through the program's own pages | PHP `curl` and `dom` |
| `failed-sign-ins.php` | Counts the failed sign-ins recorded for a user name, and can forget them; used by `smoke.sh` | PHP `pdo_sqlite` |
| `proxy-check.sh` | Run inside the web container by the image job in CI: acts as a reverse proxy would | the container image |
| `make-site-old.php` | Makes the test site look as PhpGedView 4.3.0 would have left it, for trying the upgrade; used by `smoke.sh` | PHP `pdo_sqlite` |
| `rename-user.php` | Changes a user name in the test site; used by `smoke.sh` | PHP `pdo_sqlite` |
| `smoke.sh` | Starts and provisions a site, requests the main pages as visitor and administrator, fails on any PHP error or warning, and checks what each may see, that every POST form carries its token, and the sign-in, session, forged-request and cross-site link rules, the security headers, the lost-password link, what may be uploaded, pending edits, and moving a site over from PhpGedView | the same, plus `curl` |

The test administrator is `admin` / `test-admin-password`. A second account, `legacy`, has the same password stored
in PhpGedView's old format, to show that it is refused. Both exist only in the throw-away test site.
