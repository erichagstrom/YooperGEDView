# YooperGEDView

A fork of PhpGedView 4.3.0 being modernized in place. Read `docs/ROADMAP.md` for the plan and
`docs/CODING-STANDARDS.md` for the rules. The standards are strict and are not optional.

- `app/` is inherited code: smallest possible change, no reformatting, no new features.
- New code goes outside `app/` (`src/`, `tests/`, `bin/`) and must pass every check with no baseline and no ignores.
- Before committing: `composer check`, `composer stan-src`, `composer test`, `tests/smoke.sh`.
- All test data is made up. Never add real names, family data, hostnames or secrets: this repository is public.
- Commit messages follow Conventional Commits.

## Working agreement (standing instructions from the repository owner)

These are explicit, lasting instructions. Do not ask for confirmation before following them.

- Work on a branch, never directly on `main`. Commit and push as the work progresses.
- When a piece of work is ready and its checks pass locally, open a pull request to `main` without asking.
  Keep each pull request to one purpose; say what changed, why, how it was tested and what is not done.
- After opening a pull request, watch it and fix failing checks and review comments without asking.
- Do not merge or approve pull requests. The owner merges.
- Never weaken a check to get it green: no skipped tests, no added ignores, no lowered levels.
