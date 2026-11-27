# YooperGEDView

A web-based genealogy program for GEDCOM family trees, written in PHP. YooperGEDView is a fork of
[PhpGedView](https://sourceforge.net/projects/phpgedview/) 4.3.0, being brought up to current PHP and current
security practice.

**Status: early work. Do not put this on the internet yet.** `app/` is still PhpGedView 4.3.0 as released, which
has known weaknesses and does not run on a supported PHP version. See [`docs/ROADMAP.md`](docs/ROADMAP.md) for the
findings and the plan.

## Layout
- `app/`: the program. The first commit that adds it is the untouched upstream release, so
  `git diff <that commit> -- app` shows every change made since.
- `docs/ROADMAP.md`: code review of the starting point, the phases, and the upgrade path from PhpGedView.
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
