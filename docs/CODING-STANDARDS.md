# Coding standards

Two kinds of code live here, with different rules.

| | New code (everything outside `app/`) | Inherited code (`app/`) |
|---|---|---|
| What it is | Written for YooperGEDView | PhpGedView 4.3.0, being replaced piece by piece |
| Rule | Everything below, enforced in CI, no exceptions | Smallest change that does the job |

## New code

### Language
- PHP 8.3 or later. Every file starts with `declare(strict_types=1);`.
- Formatting is [PER Coding Style](https://www.php-fig.org/per/coding-style/). `composer cs-fix` applies it.
- Namespace `YooperGEDView\`, one class per file, PSR-4 under `src/`.
- Every parameter, return value and property has a type. No `mixed` unless the value really is unknown, and then it is narrowed before use.
- Classes are `final` unless designed to be extended. Properties are `private` and `readonly` where they can be.
- Comparisons are strict (`===`). No `@` error suppression. No variable variables.
- Failures throw exceptions. Functions do not return `false` or `null` to mean "it went wrong".

### Security
- Input is read only through the request object. No `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, `$_SESSION` or `global`.
- SQL is written only in repository classes, always as prepared statements with bound values. Identifiers are never built from input.
- Output is escaped by the template layer. Code that builds HTML by joining strings is not accepted.
- Never: `eval`, `unserialize`, `extract`, `exec` and its relatives, `md5` / `sha1` / `crypt` for passwords or tokens, `rand` / `mt_rand` / `uniqid` for anything secret, `include` or `require` of a computed path.
- Passwords use `password_hash` and `password_verify`. Random values use `random_bytes` or `random_int`.
- The program never writes a file it later runs.
- No secrets, real names or real family data in the repository, in tests or in fixtures.
- Every route states who may use it. Every change of state checks a CSRF token. Every read of a person, family or media item goes through the privacy service.

### Tests
- New code comes with tests. A bug fix comes with the test that would have caught it.
- Tests use the made-up data in `tests/fixtures/`.

### Static analysis
- PHPStan at its highest level with the strict rules (`phpstan-src.neon`). There is no baseline for new code: a finding is fixed, not recorded.
- `@phpstan-ignore` needs a reason on the same line.

## Inherited code (`app/`)
- Do not reformat. The diff against the upstream release must stay readable.
- Do not add features. Fix, remove, or move.
- When a file's logic is rewritten, it moves to `src/` and meets every rule above. A half-converted file does not stay in `app/`.
- PHPStan runs at its lowest level with a baseline (`phpstan.neon`). The baseline only shrinks.

## Shell scripts
- `#!/usr/bin/env bash` and `set -euo pipefail`. A script that must carry on after a failed check to report all results may leave out `-e`.
- Clean under `shellcheck`.
- Secrets never appear as arguments or in output.

## Dependencies
- Added only through Composer, pinned by `composer.lock`. No copied-in libraries.
- `composer audit` must be clean.

## Commits
- [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`.
- One purpose per commit. The message says what changed and why.

## Checks
```bash
composer check      # syntax, formatting, static analysis of app/
composer stan-src   # static analysis of src/
composer test       # unit tests of src/
tests/smoke.sh
```
CI runs the same commands on every push.
