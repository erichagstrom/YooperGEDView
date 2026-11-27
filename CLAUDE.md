# YooperGEDView

A fork of PhpGedView 4.3.0 being modernized in place. Read `docs/ROADMAP.md` for the plan and
`docs/CODING-STANDARDS.md` for the rules. The standards are strict and are not optional.

- `app/` is inherited code: smallest possible change, no reformatting, no new features.
- New code goes outside `app/` (`src/`, `tests/`, `bin/`) and must pass every check with no baseline and no ignores.
- Before committing: `composer check`, `composer stan-src` (once `src/` exists), `tests/smoke.sh`.
- All test data is made up. Never add real names, family data, hostnames or secrets: this repository is public.
- Commit messages follow Conventional Commits.
