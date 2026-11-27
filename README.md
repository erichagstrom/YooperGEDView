# YooperGEDView

A web-based genealogy program for GEDCOM family trees, written in PHP. YooperGEDView is a fork of
[PhpGedView](https://sourceforge.net/projects/phpgedview/) 4.3.0, being brought up to current PHP and current
security practice.

**Status: early work. Do not put this on the internet yet.** The program now runs on PHP 8.3, the unmaintained
bundled libraries are gone, and the security work is under way (passwords, sessions, sign-in limits, forged-request
protection and security headers are done; the old admin pages are deleted; settings stored as PHP files, `eval` and uploads are not done). See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the plan and [`docs/DECISIONS.md`](docs/DECISIONS.md)
for what was changed and why.

## Try it
```bash
composer install
tests/serve.sh 8099        # http://127.0.0.1:8099, SQLite, throw-away copy
tests/smoke.sh             # sets up a site with the sample tree and checks it
```
`Dockerfile` and `compose.yaml` build the same program on Apache with MariaDB. To set up a new site there:
```bash
cp .env.example .env       # then set the two passwords in it
docker compose up --build --detach --wait
curl -s -o /dev/null http://127.0.0.1:8080/index.php    # the first request creates the tables
docker compose exec -T web php bin/yooper install anna --email=anna@example.org < password-file
```
`bin/yooper install` creates the first administrator; the password is the first line of its standard input. Then
sign in at `http://127.0.0.1:8080/login.php` and add a family tree.

## Layout
- `app/`: the program. The first commit that adds it is the untouched upstream release, so
  `git diff <that commit> -- app` shows every change made since.
- `docs/ROADMAP.md`: code review of the starting point, the phases, and the upgrade path from PhpGedView.
- `docs/DECISIONS.md`: each choice made so far, with the reason and the alternative.
- `docs/CODING-STANDARDS.md`: the rules for new and inherited code.
- `src/`: new code, written to the coding standards and unit-tested. `bin/yooper`: the command-line tool.
- `tests/`: sample data, local server and smoke test. See `tests/README.md`.
- `composer.json`, `phpstan.neon`, `.github/workflows/checks.yml`: development checks.

## Development checks
```bash
composer install
composer check    # syntax, formatting, static analysis
composer test     # unit tests of src/
```

## License
GPL-2.0-or-later, as PhpGedView. See `app/GPL.txt`. Copyright remains with the PhpGedView contributors for their
work.
