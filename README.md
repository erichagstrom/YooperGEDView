# YooperGEDView

A web-based genealogy program for GEDCOM family trees, written in PHP. YooperGEDView is a fork of
[PhpGedView](https://sourceforge.net/projects/phpgedview/) 4.3.0, being brought up to current PHP and current
security practice.

**Status: early work. Do not put this on the internet yet.** The program now runs on PHP 8.3 and the unmaintained
bundled libraries are gone, but the security work (passwords, sessions, CSRF, settings stored as PHP files) has
not started. See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the plan and [`docs/DECISIONS.md`](docs/DECISIONS.md)
for what was changed and why.

## Try it
```bash
composer install
tests/serve.sh 8099        # http://127.0.0.1:8099, SQLite, throw-away copy
tests/smoke.sh             # sets up a site with the sample tree and checks it
```
`Dockerfile` and `compose.yaml` build the same program on Apache with MariaDB. They cannot set up a new site yet:
the web installer is blocked and its command-line replacement is not written.

## Layout
- `app/`: the program. The first commit that adds it is the untouched upstream release, so
  `git diff <that commit> -- app` shows every change made since.
- `docs/ROADMAP.md`: code review of the starting point, the phases, and the upgrade path from PhpGedView.
- `docs/DECISIONS.md`: each choice made so far, with the reason and the alternative.
- `docs/CODING-STANDARDS.md`: the rules for new and inherited code.
- `tests/`: sample data, local server and smoke test. See `tests/README.md`.
- `composer.json`, `phpstan.neon`, `.github/workflows/checks.yml`: development checks.

## Development checks
```bash
composer install
composer check    # syntax, formatting, static analysis
```

## License
GPL-2.0-or-later, as PhpGedView. See `app/GPL.txt`. Copyright remains with the PhpGedView contributors for their
work.
