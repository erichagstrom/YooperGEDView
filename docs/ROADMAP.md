# Roadmap: from PhpGedView 4.3.0 to YooperGEDView

Status: Phase 1 (runs on PHP 8.3) done as far as the tests reach; Phase 2 (security core) under way. Scope: `app/` (PhpGedView 4.3.0). Backend first; pages keep their
current look until Phase 5.

## What the code looks like today

Measured on the untouched 4.3.0 release in `app/` (397 PHP files, about 240,000 lines).

| Area | Finding |
|---|---|
| Structure | 103 separate page scripts in the web root, each its own entry point. No router, no autoloader, no Composer |
| State | 1,358 `global $` statements in 157 files. Settings, user and current tree are all global variables |
| Classes | 27 class files and 18 "controllers", no namespaces. Most logic is 881 loose functions |
| Database | Already PDO with prepared statements (336 `prepare` calls). The best part of the codebase |
| Input | 779 direct `$_GET/$_POST/$_REQUEST/$_COOKIE` reads in 66 files, next to 522 uses of the `safe_GET` regex helpers |
| Passwords | MD5-crypt (`$1$`), with the salt taken from the session id (`includes/session.php:380`) |
| Forms | No CSRF protection anywhere (POST requests fixed in Phase 2) |
| Sessions | Cookie flags not set by the app; a session id can be supplied by the caller in one code path (`session.php:363`) |
| `eval` | 12 uses in PhpGedView's own code: home-page blocks (`index.php`, one with a request value inside the string), the report engine, settings loading (`functions.php:356`) |
| Settings as code | The app writes PHP files and then `require`s them: `config.php`, per-tree `_conf.php` and `_priv.php`, `pgv_changes.php` (pending edits), `lang_settings.php`, `banned.php` |
| `unserialize` | 26 uses in 11 files, on database and session values |
| Risky admin pages | `dir_editor.php`, `editlang*.php` (writes PHP), `pgvinfo.php` (phpinfo), `config_download.php`, `install.php`, `usermigrate*.php`, `client.php` / `genservice.php` (SOAP) |
| Bundled libraries | TCPDF 4.8 (54,800 lines), PHPMailer 5.2.23, PEAR + SOAP (11,700 lines), PclZip, Prototype, script.aculo.us, old jQuery. All unmaintained at these versions |
| Translations | 32,700 lines of PHP arrays in `languages/` |
| PHP 8 | 35 files failed a PHP 8.3 syntax check: 18 of the program's own (now fixed) and 17 in the bundled SOAP, TCPDF and PHPMailer code (removed or replaced in Phase 1). A further 33 calls to removed functions (`each`, `split`, `create_function`, `ereg`) and the magic-quotes code will fail at run time |
| Tests | None |

## The decision

| Path | What it is | For | Against |
|---|---|---|---|
| **A. Move to webtrees 2.x** | Import the data into the maintained fork | Someone else carries security fixes. Weeks, not months | Not a continuation of PhpGedView itself |
| **B. Modernize in place** (chosen) | Keep PhpGedView running, replace it piece by piece behind a new core | Always a working site. Every step is testable against the old behaviour | Long. Old and new code live side by side for a while |
| **C. Rewrite from scratch** | New app on a framework, import the data | Cleanest result | No working site for a long time; privacy rules and GEDCOM edge cases get rediscovered the hard way |

Chosen: **B**, borrowing design ideas (not code blindly) from webtrees, which solved the same problems from
the same starting point. webtrees is GPL-3.0 and PhpGedView is GPL-2.0-or-later, so copying webtrees code makes the
fork GPL-3.0-or-later.

Cost of B: a small project carries the security of an app that holds data on living people.

## Phases

Each phase ends with the test suite passing, and with a site that still works.

### Phase 0. Safety net
- [x] Project name: YooperGEDView (versions start at 5.0.0-dev); keep upstream copyright notices
- [x] Composer project, syntax check and PHPStan at its lowest level (`composer.json`, `phpstan.neon`)
- [ ] PHPStan baseline file, then make that check blocking
- [x] CI on every push: syntax check, static analysis, dependency audit (`.github/workflows/checks.yml`); tests join when they exist
- [x] Sample tree, local server and smoke test (`tests/`)
- [ ] Reference site and page comparison (see "Test infrastructure")
- [ ] Unit tests for the privacy rules (`functions_privacy.php`) before anything touches them

### Phase 1. Run on a supported PHP (8.3)
Done as far as the tests reach. Details and reasons are in `DECISIONS.md`.
- [x] Fix syntax that PHP 8 rejects in the program's own code
- [x] Fix removed and deprecated function calls, PHP 4 style constructors, and the dead PHP 5 start-up code
- [x] Fix what PHP 8 treats differently at run time on every page and report the smoke test visits
- [x] Remove the SOAP web service, PEAR, the CMS bridges and remote-site linking
- [x] TCPDF and PHPMailer from Composer; PclZip replaced by `ZipArchive`
- [x] Full error reporting; the server decides whether errors are shown or logged
- [x] Container image on the official PHP 8.3 image with MariaDB 11.4 (`Dockerfile`, `compose.yaml`)
- [x] English only: the other language files are removed

Left over from Phase 1:
- [ ] Pages and actions the tests do not reach yet: saving edits, accepting changes, uploading media, creating users, sending mail other than the password link, PDF for reports other than the individual report
- [ ] Comparisons that changed meaning in PHP 8 (see "Known risk" in `DECISIONS.md`); needs the reference site
- [ ] PHPStan baseline for `app/`, then make that check blocking
- [x] `bin/yooper install` creates the first administrator, so the container image can set up a new site without the web installer
- [x] Behind a reverse proxy, the container image takes the visitor's address and HTTPS from the proxy, and from nobody else
- [ ] Pin the base images by digest

### Phase 2. Security core
- [x] Passwords: `password_hash` (Argon2id). No old hashes need migrating; every account gets a reset
- [x] Sessions: Secure, HttpOnly, SameSite cookies; new session id at sign-in; no caller-supplied ids; idle timeout
- [x] Password reset by one-time link; changing a password ends the user's other sessions
- [x] Sign-in: limit on failed attempts with a temporary lock, and a record of every sign-in attempt
- [ ] Audit log of administrator actions in the database (today they go to the log files in `index/`), and a page to read both
- [x] CSRF token on every POST request, enforced centrally and added to every form as the page goes out
- [x] Links that change something (`?action=delete`, `?logout=1` and the like): a signed-in visitor sent to one by another site must confirm first
- [ ] Those links become POST requests (with the router in Phase 3); the confirmation step then goes
- [ ] One request object for all input; remove the 779 direct superglobal reads file by file
- [ ] Remove every `eval`: blocks and reports become classes looked up from a fixed list
- [ ] Settings out of PHP files: secrets from the environment, the rest in the database. Pending edits (`pgv_changes.php`) into a table. `unserialize` replaced by JSON
- [x] `bin/yooper install` replaces the web installer for creating the first administrator
- [x] Delete `dir_editor.php`, `editlang*.php`, `pgvinfo.php`, `config_download.php`, `install.php`, `usermigrate*.php` (and `changelanguage.php`, which depended on them)
- [ ] Uploads: type check by content, random stored names, media kept outside the web root and served only through the access check
- [x] Response headers: Content-Security-Policy, HSTS, and the three already set in Apache, sent by the program
- [x] Nothing in a page is fetched from another site: the Google chart images are removed
- [ ] Tighten the policy: no scripts or styles written into the HTML, no code run from text (needs the templates of Phase 4)

### Phase 3. Architecture
- [ ] Single front controller and router; the 103 page scripts become routes, old URLs redirect
- [ ] PSR-4 namespaces and autoloading; a small service container replaces `global`
- [ ] Middleware for sign-in, CSRF, tree selection and privacy, so no page can forget a check
- [ ] Domain classes (`Person`, `Family`, `Source`, `Media`) with no database or HTML inside; repositories own the SQL
- [ ] Privacy as one tested service that every read goes through
- [ ] Schema changes as versioned migrations (the existing `db_schema_N_M.php` chain is the starting point)
- [ ] Raise the PHPStan level as globals disappear

### Phase 4. Output layer (still the same look)
- [ ] Templates with escaping on by default; HTML moves out of the logic functions (`functions_print*.php`, 692 hand-escaped spots today)
- [ ] Translations to a standard format (gettext or equivalent), English only; adding a language is one file
- [ ] Two-factor sign-in (TOTP or passkeys)

### Phase 5. Later
- Statistics charts drawn on the site itself (they were Google chart images, removed in Phase 2).
- Visual redesign, replacement of Prototype / script.aculo.us, JSON API, GEDCOM 7 import and export.

## Order and size

Phases 0 to 2 remove the known weaknesses and are the minimum before the site faces the internet, and even then
behind a reverse proxy with TLS. Phase 3 is the largest piece of work by far. Rough proportions: 0 = 5%, 1 = 15%,
2 = 25%, 3 = 40%, 4 = 15%.

## Decisions
- **Name:** YooperGEDView. Public project, GPL-2.0-or-later, upstream copyright notices kept.
- **Path:** B, modernize in place.
- **Languages:** English only. Translation support stays in the design so others can add a language; the other 55 bundled language files are removed in Phase 1.
- **Audience:** public software in the end, so upgrading from PhpGedView is a designed feature (next section).

## Upgrade path from PhpGedView 4.3.0
Goal: anyone with a PhpGedView 4.3.0 site gets to YooperGEDView with one command and no hand editing.

- One command-line tool, `bin/yooper`, with `install` (new site) and `upgrade-from-pgv` (existing site). No web installer: it was one of the weak points.
- `upgrade-from-pgv` takes the old database and the old `index/` folder and:
  - checks first and changes nothing if anything is unreadable (dry run by default, with a report)
  - keeps the old `pgv_` tables as they are and writes new ones, so going back is possible
  - reads `config.php`, `*_conf.php`, `*_priv.php`, `pgv_changes.php` as text (never runs them) and stores their values in the database
  - carries accounts over without passwords; each user sets a new one by a one-time link
  - leaves media where it is and records each file's checksum
- Every later schema change is a numbered migration that runs on start-up, so upgrades between YooperGEDView versions use the same mechanism.
- The upgrade is tested in CI against a made-up PhpGedView 4.3.0 database (no real family data).
- GEDCOM export and import stays as the fallback route in and out.

## Test infrastructure
All of it uses made-up data (`tests/fixtures/sample.ged`), so it can live in this public repository.

| Layer | Purpose | Status |
|---|---|---|
| Syntax check, static analysis | Catch breakage without running anything | In CI |
| Unit tests (`composer test`) | PHPUnit tests of the new code in `src/` | In CI, blocking |
| Local server (`tests/serve.sh`) | Run the program on PHP 8.3 with SQLite; no Docker or database server needed | Works |
| Container image | Builds, starts with MariaDB, creates its tables, refuses the settings folder, sets up an administrator with `bin/yooper install`, limits sign-ins | In CI, blocking |
| Smoke test (`tests/smoke.sh`) | 195 checks on a freshly set-up site: about 115 pages and all 19 reports as visitor and administrator, no PHP error or warning, every POST form carrying its token, nothing loaded from another site; what each may see; exports; sign-in, session and forged-request rules | In CI, blocking |
| Reference site | The untouched 4.3.0 release on PHP 5.6 + MariaDB in CI, loaded with the sample tree; its database dump is the "old site" every upgrade test starts from | To build |
| Page comparison | Same requests against the reference site and the current code; differences must be explained | To build |
| Privacy tests | Who can see which person in the sample tree, as visitor, member and administrator | To build |
| Upgrade test | `upgrade-from-pgv` on the reference dump, then the smoke and privacy tests on the result | To build with the tool |

## Who uses what
- **This repository** is the product: what gets released and deployed.
- **A site running PhpGedView 4.3.0** is the client: it changes nothing until a release exists, then runs
  `upgrade-from-pgv`. Client sites never carry patches of their own; anything they need goes into a release here.

## Still open
- Which features can go? Candidates: SOAP service, remote-site links, Gramps export, RSS, iCal, hit counter, multi-tree support. Current default: SOAP, remote-site links and the hit counter go; the rest stay.
