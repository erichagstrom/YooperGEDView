# Tests

Everything here uses made-up data. `fixtures/sample.ged` is an invented family: three generations, with people who
are dead and people who would still be living, so the privacy rules have something to hide.

| Script | What it does | Needs |
|---|---|---|
| `serve.sh [port]` | Runs the program from a throw-away copy with an SQLite database, on `127.0.0.1` only | PHP 8.3 with `pdo_sqlite` |
| `smoke.sh` | Starts `serve.sh`, requests the main pages, fails on any PHP error | the same, plus `curl` |

`smoke.sh` is expected to fail until the program runs on PHP 8.3 (Phase 1 of `docs/ROADMAP.md`).
