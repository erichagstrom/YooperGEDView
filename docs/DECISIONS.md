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

30. **Failed sign-ins are limited per user name and per address** (`YooperGEDView\Security\SignInThrottle`): 10
    failures for one user name, or 50 from one address, within 15 minutes, and further attempts are refused
    without looking at the password. A successful sign-in clears that user name's count. Why per user name as
    well as per address: an attacker with many addresses is otherwise unlimited. The cost: anyone can lock a
    known user name out by guessing. So the lock is short, and refused attempts are not counted, which means
    it ends 15 minutes after the tenth failure however long the guessing goes on. Alternative: lock until an
    administrator steps in, which turns guessing into a way to shut people out for good.
31. **Every sign-in attempt is recorded in a new table, `pgv_sign_in_attempt`** (schema version 15): the user
    name as typed, the address, the time and the result. It is the audit log of sign-ins and what the limit
    counts. Records older than 90 days are deleted. The user name is stored even when no such user exists, so
    the table can hold whatever people type into that field, including a password typed there by mistake.
32. **The address is the one the web server reports.** Behind a reverse proxy that is the proxy's address for
    everyone, and the per-address limit would then lock all users out together. A deployment behind a proxy
    must have the web server restore the real client address (Apache `mod_remoteip`). The container image does so since
    decision 56.
33. **`PGV_DB::getPDO()` added** so that new code can be handed the database connection. New classes take what
    they need as arguments and do not reach for globals.

34. **Every request other than GET, HEAD and OPTIONS must send back the session's token**
    (`YooperGEDView\Security\CsrfProtection`), checked in `includes/session.php` before any page code runs.
    A request without it gets "403" and a short explanation. The token is 32 random bytes, kept in the
    session, sent as the form field `csrf` or the header `X-CSRF-Token`, and replaced at sign-in.
35. **The token is added to forms by an output filter, not by editing each form.** The whole page passes
    through a handler that puts the hidden field after every `<form method="post">` tag. Why: 77 forms in
    inherited code, printed in a dozen different ways; a form added later, or one in a module, is covered
    without anyone remembering. The smoke test fails if any page has a POST form without the field.
    Alternative: a call in each form, as webtrees does. That becomes the way when templates arrive in Phase 4,
    and the filter goes.
36. **This is the one place new code builds HTML by joining strings**, against the coding standards, because
    there is no template layer yet. The field is made only from a token checked to be hexadecimal.
37. **Pages are held in memory until they finish, so that the filter sees them whole.** The four pages that show
    progress while they work (GEDCOM import, check, export, language check) now push their output through the
    filter at each step. File downloads (media, backups, GEDCOM download) bypass the filter. One loss: the
    relationship calculator no longer sends a space now and then to notice that the visitor has gone away.

38. **A signed-in visitor sent by another site to an address with parameters must confirm with a click**
    (`YooperGEDView\Security\CrossSiteRequestPolicy`, applied in `includes/session.php`). Many inherited pages
    change things on a plain link: delete, accept or reject, sign out. The token does not cover links, and
    SameSite=Lax cookies do not either, since a link clicked on another site is an ordinary navigation. Rather
    than find and convert every such link now, one rule covers them all: the page is not run; a short page shows
    the address and a "continue" link. The click is made on this site, so the next request goes through.
    Alternative: SameSite=Strict cookies, which make every arrival from another site look signed out, with no
    way to carry on; or converting each link to a POST, which belongs with the router in Phase 3.
39. **How "sent by another site" is told.** From the browser's `Sec-Fetch-Site` header (anything but
    `same-origin` or `none`); from `Referer` when that header is missing (older browsers). A request with
    neither is let through: an address typed in, a bookmark, or a program that is not a browser. The gap: an old
    browser without `Sec-Fetch-Site`, on a link made to send no `Referer`. Browsers released since early 2023
    all send `Sec-Fetch-Site`.
40. **What the confirmation step costs.** A signed-in user who follows a link from a mail message or another
    site to anything but a bare page address sees the extra page once. Visitors who are not signed in never see
    it. The host name compared with `Referer` is the one in the request, so a reverse proxy must pass the
    public host name on.

41. **The program sends its own security headers** (`YooperGEDView\Security\SecurityHeaders`), so they apply
    under any web server: Content-Security-Policy, X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
    and Strict-Transport-Security when the site is reached over HTTPS (one year, this host only). Apache still
    sets three of them for the files it serves itself, and drops the program's copies so none goes out twice.
42. **The content security policy keeps everything on this site but cannot yet forbid inline code.** Scripts,
    styles, images, frames, plug-ins and form targets must come from the site itself. `'unsafe-inline'` and
    `'unsafe-eval'` stay for scripts, because the inherited pages write scripts and event handlers into the HTML
    and Prototype runs code from text; so the policy does not stop a script injected into a page. What it does
    stop: loading scripts from elsewhere, forms posting elsewhere, a changed base address, framing by other
    sites. The policy was tried in a real browser on the 113 pages of the smoke test.
43. **Found by that trial: the statistics charts sent data from the tree to Google.** Six kinds of page
    (statistics, individual and family lists, source pages) asked `chart.apis.google.com` for chart images, with
    surnames, given names, couples' names and counts in the address. Google closed that service in 2019, so
    the charts were already broken pictures; the requests were still being made. The policy now stops the
    browser from making them. The code that builds those addresses is still there and should be removed or
    replaced with charts drawn on the site.
44. **No other site is allowed, so the two features that load from elsewhere stop working:** the ClustrMaps
    visitor map in the page footer (off unless configured) and the Google translation helper in the language
    editor. Add-on modules that load from other sites, such as a maps module, would be stopped too. A site
    that wants one back must add its host to the policy. Ordinary links to other sites are not affected.

45. **The Google chart images are removed, and with them every chart in the program.** 22 places built an
    address at `chart.apis.google.com` holding names and counts from the tree (see 43). Each now produces
    nothing. What is lost on screen: nothing that worked, since the service closed in 2019. Affected: the
    charts in the statistics page and its blocks, the charts under individual and family lists, and the
    "own charts" plot page, which now shows only its heading. Charts drawn on the site itself are listed for
    Phase 5. Alternative: write a chart drawer now, which is a new feature and not security work.
46. **Chart functions in the statistics class still do their counting before returning nothing.** Only the line
    that made the picture is replaced, to keep the change small. The wasted work goes when the class is
    rewritten.
47. **The smoke test fails if any page fetches anything from another site.** It found one more: the language
    editor loaded a script from Google for a translation helper. That is no longer loaded; the page itself is
    due for deletion.

48. **`bin/yooper install` creates the first administrator** (`YooperGEDView\Console\Yooper`). It is the part of
    the web installer that mattered for security: whoever reached `install.php` first on a new site became its
    administrator. The command needs a shell on the server, which a passer-by does not have. It refuses when
    the site already has an administrator.
49. **The password comes from standard input, never from the command line**, where it would show in the list of
    running programs and in the shell's history. At least 12 characters. Typed at a keyboard it is echoed;
    hiding it needs a terminal library and is left for later.
50. **The command does not create the tables or write settings.** The program still creates its tables on the
    first page request, and the database settings come from the environment (as in the container image) or
    are written into `config.php` by hand. So setting up is: start the site, ask for one page, run the
    command. Moving table creation into the command comes with the migrations of Phase 3.
51. **The database is found through environment variables** (`DB_TYPE`, `DB_HOST`, `DB_PORT`, `DB_NAME`,
    `DB_USER`, `DB_PASSWORD`, `DB_PREFIX`), not by reading `config.php`, which is PHP code and would have to be
    run. A site set up by editing `config.php` must give the same values to the command.
52. **The tests set up their site with the command.** `tests/provision.php` and the image job in CI both create
    their administrator with it, so every smoke run signs in with an account it made, on SQLite and MariaDB.

53. **The old administration pages are deleted** (13 files, about 5,200 lines):
    - `install.php`: the web installer. Whoever reached it first on a new site became administrator; later it
      was the page for editing `config.php` from the browser.
    - `dir_editor.php`: deleted files in the settings folder from the browser.
    - `editlang.php`, `editlang_edit.php`, `editlang_edit_settings.php`, `changelanguage.php` and their helpers:
      edited the language files, which are PHP code, from the browser. `changelanguage.php` was not on the
      roadmap's list but only worked through the others, and the program is English only.
    - `pgvinfo.php`: showed `phpinfo()`, which lists the server's settings and environment.
    - `config_download.php`: handed out settings files as downloads.
    - `usermigrate.php`, `usermigrate_cli.php` and their controller: exported and imported accounts with
      their password hashes.
54. **What an administrator can no longer do from the browser:** change the site's settings in `config.php`
    (edit the file, or set the environment in the container image), tidy the settings folder, back up
    accounts to a file, edit translations. The admin page's links to these are removed. Settings move to the
    database later in Phase 2, with a page of their own.
55. **A site that is not ready says so in one line of plain text, with status 503**, instead of sending the
    visitor to the installer: not configured, cannot reach its database, or has no administrator yet (with
    the command to run). Alternative: a styled page, which would need the theme code to run on a site that may
    have no database.

56. **The container image believes one address about who the visitor is: `TRUSTED_PROXY`.** A reverse proxy in
    front of the site passes the visitor's address in `X-Forwarded-For` and whether they used HTTPS in
    `X-Forwarded-Proto`. Apache (`mod_remoteip`) takes these only from requests that arrive from that address
    or range, and ignores them from anyone else, because a visitor can write the same headers. The default is
    the container itself, so a site with no proxy believes nobody. This closes the gap in 32: without it, all
    visitors behind a proxy shared one address and one sign-in limit.
57. **HTTPS at the proxy counts as HTTPS.** With the proxy's word, the session cookie is marked Secure, the
    browser is told to keep to HTTPS, and the program builds `https://` links. Without this a site behind a
    proxy that ends TLS would send its cookie unprotected and redirect to `http://` addresses.
58. **This is Apache configuration in the image, not program code.** A site on another web server must set up
    the same thing there. The program reads only what the web server tells it.

59. **A lost password is replaced through a one-time link, not by mailing a new password**
    (`YooperGEDView\Security\PasswordReset`, table `pgv_password_reset`, schema version 16). Before, anyone
    could type a user name and the program at once replaced that user's password with a new 8-character one
    and mailed it in plain text: a way to lock any user out, as often as wanted, and a password sitting in a
    mailbox. Now asking changes nothing. The message holds a link with a random token that works once, for an
    hour, and leads to a form for choosing a password. Only a hash of the token is stored.
60. **Asking for a link gives the same answer for every user name**, real or not, with or without a mail
    address, so the page cannot be used to find out which names exist. Before, it said "could not verify" for
    unknown names. If the message cannot be sent, the reason goes to the log, not to the page: the mail
    function prints addresses in its errors.
61. **One link per user every five minutes.** Asking again sooner sends nothing, so the page cannot be used
    to flood someone's mailbox. A new link replaces the user's earlier one.
62. **Changing a password ends that user's other sessions** (`endOtherSessions()`): by the link, on the user's
    own account page, or by an administrator. Each user has a random mark that is replaced when the password
    changes; a session carries the mark it signed in under, and one carrying an older mark is signed out at
    its next request. A first version compared times instead, and let a session survive when it had signed in
    during the same second as the change; the test caught that in CI. The session that made the change carries
    on. Why: someone who has got into an account should not stay in after its owner changes the password.
63. **The mail address check no longer asks DNS whether the domain takes mail.** That lookup made sending
    depend on the network at the moment of the request, refused addresses whenever DNS was slow or missing,
    and made mail impossible to test. An address that cannot take mail now fails when the message is sent.
64. **The test site writes its mail to a file** instead of sending it, so the smoke test can follow the link
    in the message. This is the first test of anything the program mails.

65. **Every `eval` is gone** (13 calls). They fell into five kinds, 66 to 70.
66. **Report conditions are worked out by an interpreter of their own**
    (`YooperGEDView\Report\ConditionEvaluator`). The inherited code took a condition from a report file, such
    as `@AUTH!=''`, pasted the record's values into it as quoted text, and ran the result as PHP. A value in
    the tree written with PHP's `{${...}}` form ran as code: anyone who could edit one record, or get a GEDCOM
    file imported, could run anything on the server by having a report printed. Confirmed on the old code
    with the sample tree: the family report printed the result of a sum written into a source's author. The
    interpreter reads the condition once, and asks for each `$variable` and `@TAG` as it goes; a value is
    only ever a value. It understands what the 820 conditions in the bundled reports use (comparisons,
    `and`/`or`/`!`, brackets, `stristr()`) and nothing else. A condition it cannot read counts as false, as
    in 19.
67. **Comparisons in conditions follow PHP 8's rules for `==`**, written out in the interpreter: `"1"`
    equals `1`, a missing value equals `''`, text that is not a number never equals a number.
68. **`@fact` in a condition now means what it was meant to.** The old code replaced it by the fact's name as
    bare text, which PHP 5 happened to read as a word and PHP 8 rejects; so `@fact=='MARR'` had been false
    since Phase 1, and `@fact:TEMP` never worked. Now `@fact` is the current fact's name and `@fact:TEMP` is
    that fact's TEMP value. Three conditions in the bundled reports use this.
69. **A text can name only listed values** (`YooperGEDView\Text\NamedValues`). Language texts hold
    placeholders such as `#pgv_lang[edit]#` and `#GLOBALS[MEDIA_DIRECTORY]#`, and the same expansion is
    applied to news items, FAQ entries and HTML blocks, which users write. The old code ran the placeholder
    as a PHP variable reference, so `GLOBALS[...]` reached every variable in the program, the database
    password included. Now the language lists, the eleven values the function was given, and the 32
    `GLOBALS` names that the English language files actually use can be named; anything else is left as it
    stands. Report files get the same treatment for `pgv_lang[...]` and `factarray[...]`.
70. **Home-page blocks are called by name, after the name is checked against the list of installed blocks.**
    The old code built a line of PHP from the block's name, the side and the block number from the request.
    The checks around it happened to make that safe; it no longer depends on them.
71. **`update_site_config()` and `update_config()` are deleted.** They rewrote `config.php` and tested the
    result by running it. Nothing called them once the installer was gone.
72. **Reports were compared before and after**, since no test knows what a report should look like: all 19
    reports for three people and two families, with sources and notes on, plus 84 home-page blocks and 11
    pages with help texts, from the old and the new code. All 209 outputs are the same, apart from a
    timestamp in the pages.
73. **The smoke test now runs reports with sources and notes switched on.** It passed `on` where the reports
    expect `1`, so those sections had never been printed in a test. That turned up one more PHP 8 deprecation
    in the report engine, fixed here.

74. **Every uploaded file is checked before any page code runs** (`YooperGEDView\Upload\UploadPolicy`, applied
    in `includes/session.php`). The five places that take uploads saved the file under the name its sender
    gave it, in the media folder, which the web server serves. None looked at that name. An editor could
    upload `x.php` and have it run as a page of the site; the quick upload on the edit page also took a
    folder from the request, so `..` put the file in the program's own folder. Confirmed on the old code:
    all four of the new upload tests fail there. A refused upload gets status 400 and one sentence saying
    why; nothing is saved.
75. **The rules.** The name must end in a type from a list: the media types the inherited code names, plus
    GEDCOM, zip and a few current media types. No part of the name between dots may be a type that servers
    run or browsers show as a page (`php`, `phtml`, `html`, `svg`, `htaccess` and others), since some servers
    run `x.php.jpg`. The content, as PHP's `fileinfo` reads it, must not be a program or a web page; and for
    pictures, PDF and zip it must be what the name says.
76. **`htm`, `html`, `swf` and `scr` are no longer media types for upload.** The inherited list had them. A
    web page in the media folder is a page of the site written by whoever uploaded it. Files of those types
    already in a media folder are untouched.
77. **A picture that also holds PHP is kept, under a picture name.** Such files are harmless unless something
    runs them, and the name rules are what prevent that. Looking for PHP inside pictures would refuse real
    photographs whose data happens to hold `<?`.
78. **The quick upload's folder must be a plain path inside the media folder.** Anything with `..` or other
    characters is replaced by the media folder itself.

79. **Pending edits are kept as data, in `index/pgv_changes.json`, and no longer as a PHP file that is run.**
    The old code wrote each pending change into `pgv_changes.php` as lines of PHP and loaded that file on
    every page an editor opened. The editor's user name went between quotes with nothing escaped, and user
    names may hold quotes; users can change their own. Confirmed on the old code: an editor named
    `x'.print(...).'` had that code run on every page load after making one edit. The file is now written
    with `json_encode` and read with `json_decode`; nothing in it is run.
80. **A `pgv_changes.php` left by PhpGedView is not read.** It is PHP code and may hold exactly what 79
    describes. Pending edits in it are not carried over; the upgrade tool will read that file as text. A
    site should accept or reject its pending changes before moving to this version.
81. **JSON in a file for now, a table later.** The roadmap puts pending edits in a database table. That
    touches every place that reads `$pgv_changes`, and belongs with the rest of the settings work. This
    change closes the hole with the smallest step: the same list, the same file name but for its ending,
    written and read as data.
82. **The record's own text was not a way in.** The old code's escaping of the record held, because
    backslashes are stripped from what is sent before it gets there. Only the user name, and the other
    fields written without escaping, were.

83. **One rule for a new password: 12 to 1000 characters** (`YooperGEDView\Security\PasswordPolicy`), on the
    account page, in user administration, at registration, behind the reset link and in `bin/yooper`. The
    inherited pages asked for six. Nothing is asked about kinds of characters: length is what makes a
    password hard to guess. The upper limit keeps hashing from being used to tie the server up. Signing in
    takes whatever is typed, so the rule never locks out a password that already exists.
84. **`bin/yooper set-password <user-name>`** sets a password from the server and ends that user's sessions.
    It is for the accounts the reset link cannot reach: a site with no mail set up, a user with no address,
    and above all the administrator of a site just moved from PhpGedView, whose old password no longer
    works. Like `install`, it reads the password from standard input.

## Upgrade path

85. **`bin/yooper upgrade-from-pgv --index=<folder> [--apply]`** (`YooperGEDView\Upgrade\UpgradeFromPgv`) looks
    over a copy of a PhpGedView 4.3.0 site and reports; with `--apply` it makes the changes that must be made
    before this program is started on that copy. It came before the rest of the hardening because three ways
    to run code on the server were found in 4.3.0 (65, 74, 79), and a fix helps a site only once the site
    runs it.
86. **It works in place, on a copy.** The roadmap had it keep the old tables and write new ones beside them.
    This program still uses the same tables as PhpGedView, so there is nothing to write beside: the copy is
    what makes going back possible. The command says "use a copy" and cannot check that it is one.
87. **Old password hashes are removed, not just left unused.** They no longer sign anyone in (23), but an
    MD5-crypt hash can be turned back into its password with modest effort, and people reuse passwords. A
    copy of the database should not carry them. The stored value becomes `*`.
88. **No file from the old site is run.** `pgv_changes.php` is taken apart into PHP's own tokens and must
    consist of the handful of statements PhpGedView wrote there; anything else and the file is refused and
    left alone, with a note that it may show the old site was tampered with (79). A good file is written
    out as `pgv_changes.json` and renamed.
89. **Each tree's settings files are read and anything beyond settings is pointed out, with its line.** The
    program still loads `*_conf.php` and `*_priv.php` as PHP (until settings move to the database), so code
    in them would run. The check knows the five functions PhpGedView's own templates call and reports any
    other call, `include`, `eval`, backticks and the like. It reports; it does not block or edit. It is a
    reading aid: a determined author could hide code from it, so a file from an untrusted site should be
    read by a person.
90. **Languages.** A user set to a removed language sees English with no error, as tested; the command sets
    such users to English so that their settings say what they get.
91. **How it is tested, and what that does not cover.** Unit tests run the command on a made-up site built in
    the test. The smoke test makes its own site look old (MD5-crypt passwords, German, pending edits as PHP),
    upgrades it, and uses it. Neither is a database written by PhpGedView 4.3.0 itself, on MariaDB, with a
    real site's years of oddities. That needs the reference site, and before the first real site is moved,
    a trial on a copy of it.

92. **First run on a copy of a real site, on MariaDB:** the command ran clean and changed
    nothing, and the copy matched the original. Two things in its report were wrong and are corrected:
    - It called every password that is not in this program's form "stored the way PhpGedView stored it" and
      "weak". One was bcrypt, set by another tool, and eight were placeholders. The report now tells apart
      weak hashes (MD5-crypt and older), other forms of hash, and no password at all. Only weak ones are
      removed. 87 stands for those.
    - It reported the guard at the top of the trees' settings files as "more than settings". Versions of
      PhpGedView before 4.3 wrote a different guard from the one in 4.3.0's templates, and a site that
      began on one keeps it. The inspector now knows both, and no longer reports the printing of one
      fixed text.
    The lesson for 91 held: a real site showed what made-up ones did not.

93. **The first trial on a copy of a real site found what the sample tree could not: a family with only one
    parent recorded.** Every such family's page showed a PHP warning, because the name of the missing parent
    was asked of a record that is not there; PHP 5 let that pass in silence. The sample tree now has such a
    family, which turned up six more places of the same kind (lists, statistics, privacy, events), all
    fixed with a cast as in 4. Two addresses for a person's tabs that a browser does not ask for, but
    anyone can type, ended in a fatal error: a tab with no number, and a tab type whose code was never
    there. Both now answer "No tab found".
94. **The sample tree grows when a real site shows a shape it lacks.** That keeps the finding as a test
    without putting anything from the real site in this repository.

95. **A sweep of every page of the real copy** (all people, families, lists, charts, some reports) found four
    more things, all fixed and all now in the smoke test: the list of families with an unknown surname failed
    because its query was given a value it had no place for, which PHP 8 refuses; the media list failed on a
    page size that was not a number; descendancy charts warned for people with no parents recorded; and the
    upgrade said "the changes above have been made" when there were none to make. A fifth, lists by surname
    letter failing on a database whose tables are in another character set than the connection, is not fixed
    here: see the next entry.
96. **Tables in another character set are converted to utf8mb4 by the upgrade.** PhpGedView made its tables
    in the database's default character set, on most old servers latin1, and sent them UTF-8 over a
    connection that said latin1. So the columns hold UTF-8 bytes under the latin1 label. Asking such a column
    for a letter latin1 does not have fails in the database ("illegal mix of collations"), which is what the
    lists by surname letter do. `upgrade-from-pgv` now tells, column by column and from the data, which of
    two things it holds: UTF-8 under the wrong label (the bytes are kept and the label put right, by way of a
    binary type) or real latin1 (converted in the ordinary way). A column with both in it cannot be put right
    by rule: its table is left alone and reported. If the server refuses a table, it is put back as it was.
    The new comparison rules mirror the old (Swedish stays Swedish), so that two values a unique index held
    apart stay apart. The database's default is changed too, so that tables added later match. The program's
    own connection now always says utf8mb4, instead of taking the server's default.
    *Alternatives:* connect as latin1 and leave the tables (keeps every sort and search wrong for non-ASCII
    letters, and breaks the day a table is added in another character set); convert everything the ordinary
    way (turns each "ö" stored as UTF-8 into two wrong letters, for good). *Side effects, both the database's
    doing:* a TEXT column becomes MEDIUMTEXT, so that it holds as many letters as before, and on MyISAM an
    ordinary (not unique) index on a long column covers its first 250 letters only. *Tested* on a MariaDB
    server (`composer test-mariadb`, run in CI), and by hand on the sample tree imported into latin1 tables:
    the list by surname letter failed before and worked after.
97. **The first conversion of a real copy left two tables behind**, both from the research module and both
    empty: their primary key holds a 255-letter column, which at four bytes a letter is more than the 1000
    bytes MyISAM allows an index. The sample tree has no such table. A table refused for that reason is now
    moved to InnoDB, which allows three times as much, and converted there; if that fails too it goes back,
    engine included. *Alternative:* shorten the column or the index (changes what the table can hold, or
    what it keeps unique). The same run showed a database whose own default was already utf8mb4 under other
    comparison rules than the converted tables got: the default is now set to the converted tables' rules,
    so that tables added later can be compared with them.

### Left for later in Phase 2
- Uploaded files keep the names their senders gave them and stay in a folder the web server serves, unless
  the media firewall is switched on for the tree. The container image does not run PHP in that folder; another
  web server needs the same setting.
- The GEDCOM upload on the tree settings page takes its folder from the request. Only administrators reach it.
- An add-on's language file that refers to other `GLOBALS` names in its texts will find them not replaced.
- The old language strings for the mailed password (`mail04_line03` to `05`, `pwreqinfo`) are unused.
- Help texts and language strings for the deleted pages are still in the language files.
- Administrator actions are still logged only to the files in `index/`, and there is no page for reading the
  sign-in record.
- Basic HTTP authentication (`?auth=basic`) is still there. It now shares the limit on failed attempts.
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
