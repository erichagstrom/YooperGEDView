# Decisions log

Choices made while working, newest phase last, so they can be reviewed and reversed. Each entry: what was decided,
why, and what the alternative was.

## Phase 1: run on PHP 8.3

1. **Tests run on SQLite with PHP's built-in server.** Why: no Docker or database server needed, so the same test
   runs on a laptop and in CI in seconds. Alternative: MariaDB in a container, closer to real sites; still wanted
   for the reference site.
2. **The test site is set up through the program's own pages** (`tests/provision.php` fills in the "Add GEDCOM"
   forms). Why: it exercises the real import code. The administrator is inserted straight into the database
   because the web installer is due to be removed.
3. **SQLite and PostgreSQL table creation fixed.** Upstream added MySQL's `ENGINE=MyISAM` to every `CREATE TABLE`
   for these two databases, so neither could install. Alternative: declare both unsupported.
4. **`null` passed to string functions is fixed with a cast at the call** (`strlen((string)$x)`). Why: it keeps
   the PHP 5 behaviour exactly and keeps the change to one line. Only places reached by the tests are fixed so
   far; more will appear as test coverage grows.
5. **Old classes are marked `#[AllowDynamicProperties]`** (20 base classes; subclasses inherit it). Why: the old
   code creates properties on the fly in many places; declaring each one is work that disappears when the class
   is rewritten. Alternative: declare every property now.
6. **Optional parameters before required ones lose their default** (57 signatures, mostly the home-page blocks).
   PHP already treated them as required, so behaviour is unchanged.
7. **Session cookie lifetime is `0`.** Upstream passed a formatted date string, which PHP 5 read as 0 (cookie ends
   with the browser session) and PHP 8 rejects. Proper cookie settings come in Phase 2.
8. **Visitor check in the privacy code compares user ids, not `'' == 0`.** PHP 8 changed that comparison from true
   to false, which sent every signed-out visitor down the "export in progress" path with empty access levels.
   Fixed in the five places it occurs; the smoke test now checks what a visitor can and cannot see.

9. **Dead PHP 5 start-up code removed** (magic quotes, the `register_globals` guard). It sat inside an
   "if PHP is older than 6" block and never ran. The minimum PHP version is now 8.3.
10. **`utf8_encode` / `utf8_decode` replaced by `mb_convert_encoding`** with the same two character sets; they are
    deprecated since PHP 8.2.
11. **SOAP web service, PEAR and the Drupal / Mambo / PostNuke bridges removed** (about 14,000 lines). The SOAP
    library does not parse on PHP 8 and has no maintained successor with the same interface.
12. **Remote-site links removed with it, in two stages.** They fetched records from other PhpGedView sites over
    SOAP. Now: the pages for creating links are gone and `ServiceClient::getInstance()` always answers "no such
    site", which every caller already handled. Later: the callers themselves go when records are rewritten in
    Phase 3. The `pgv_remotelinks` table and existing `RFN` data are left untouched. Alternative: cut out all
    callers now, which touches the core record-loading code before there are tests around it.
13. **"Manage sites" page keeps only IP banning and search-engine lists.**

14. **PclZip replaced by PHP's `ZipArchive`** in the four places that made or read zip files. PclZip fails on
    PHP 8. Zipped exports are checked by the smoke test; the admin backup and zipped GEDCOM upload are converted
    but not yet covered by a test.
15. **TCPDF 6 and PHPMailer 6 come from Composer.** The bundled TCPDF 4.8 does not parse on PHP 8. The program
    loads `vendor/autoload.php` (one folder above `app/`, outside the web root) only where mail or PDF is needed,
    and says so plainly if it is missing. PDF output is tested for the individual report only; sending mail is
    not tested at all.
16. **PHP 4 style constructors renamed to `__construct`** (33 classes, mostly the report engine and feeds). PHP 8
    no longer runs them when an object is created.
17. **The program's error handler honours `@` again.** In PHP 8 a suppressed error no longer shows up as
    `error_reporting() == 0`, so suppressed warnings were being printed into pages and PDFs.
18. **Error reporting is `E_ALL`, and the program no longer forces errors onto the page.** Upstream tried to
    switch reporting off entirely and display on; on PHP 8 the first half silently did nothing. Now the server's
    `display_errors` / `log_errors` settings decide. The container image logs and does not display.
19. **A report condition that is not valid PHP counts as false.** These conditions are run with `eval`; PHP 7+
    throws where PHP 5 returned false. The `eval` itself is removed in Phase 2.
20. **Container image:** official `php:8.3-apache`, libraries installed by a Composer build stage, code owned by
    root, `index/` and `includes/` not served, uploads not executable, web installer and bulk account import
    blocked, database settings from the environment. Not yet pinned by digest.
21. **Test data and tools are public-safe:** an invented family, a throw-away administrator, nothing from any
    real site.

## Phase 2: security core

22. **New code lives in `src/` and is loaded by Composer's autoloader on every request.** `includes/session.php`
    loads `vendor/autoload.php` and stops with a plain message if it is missing, so `composer install` is now
    needed to run the program at all, not only for mail and PDF. Unit tests use PHPUnit (`composer test`).
23. **Passwords are Argon2id hashes; nothing else is accepted** (`YooperGEDView\Security\PasswordHasher`). A hash
    in any other format (PhpGedView's MD5-crypt, bcrypt, plain text) never matches, so every account carried over
    from PhpGedView needs a new password. Why not convert on next sign-in: that keeps the weak hashes usable for
    as long as their owners stay away. Alternative: accept old hashes once and upgrade them.
24. **A sign-in attempt costs the same whether or not the user exists.** A password is hashed even when there is
    no such account, so answer times do not reveal which user names are real.
25. **The salt taken from the session id is gone** (`PGV_SALT`). Each hash has its own random salt. The
    registration code that was derived from that salt, and the password made up for "lost password", now come
    from the system's random source instead of `rand()`.
26. **Session cookie: HttpOnly, SameSite=Lax, and Secure when the site is reached over HTTPS**
    (`YooperGEDView\Security\SessionPolicy`). Lax rather than Strict so that links in mail still arrive signed in.
27. **Only session ids the site issued are accepted, and only from the cookie** (strict mode on, ids in the
    address off). The path that let a caller name its own session id is removed. Search engines keep one fixed
    session id each, as before, so they do not create a session per request; such a session can no longer carry
    a signed-in user. Upstream meant to prevent that but did it before the session was loaded, so it had no
    effect.
28. **Signing in gives a new session id and deletes the old session; signing out empties it.**
29. **A signed-in session unused for the session time (`$PGV_SESSION_TIME`, 2 hours by default) is signed out.**
    Upstream only marked such users as "not logged in" in a list; the session itself carried on.

### Left for later in Phase 2
- "Lost password" still mails a new password. It should mail a one-time link instead.
- Changing a password does not end that user's other sessions.
- Basic HTTP authentication (`?auth=basic`) is still there and has no limit on attempts; it goes with the rate
  limiting work.
- `usermigrate*.php` can still import accounts with old hashes (which no longer work); the pages are due for
  deletion.

### Found on the way, not changed (for Phase 2)
- Calling `module.php` with a bad module name bans the caller's IP address for an hour, administrators included.
- By default, names of living people are shown to visitors (`$SHOW_LIVING_NAMES = $PRIV_PUBLIC`); only their
  details are hidden. A safer default would hide the names too.

### Known risk: comparisons that changed meaning in PHP 8
Item 8 is one case of a wider change: in PHP 8, `0 == ''` and `0 == 'abc'` are false where PHP 5 said true. Such
code raises no error or warning; it just takes the other branch. There is no reliable automatic finder. The
defence is the page comparison against the reference site (the 4.3.0 release on PHP 5.6), which is why that is
the next piece of test infrastructure to build, and tests of the privacy rules in particular.
