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

### Known risk: comparisons that changed meaning in PHP 8
Item 8 is one case of a wider change: in PHP 8, `0 == ''` and `0 == 'abc'` are false where PHP 5 said true. Such
code raises no error or warning; it just takes the other branch. There is no reliable automatic finder. The
defence is the page comparison against the reference site (the 4.3.0 release on PHP 5.6), which is why that is
the next piece of test infrastructure to build, and tests of the privacy rules in particular.
