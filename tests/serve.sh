#!/usr/bin/env bash
# Run the program on PHP's built-in server with an SQLite database, from a throw-away copy of app/.
# For development and tests only: listens on 127.0.0.1. Usage: tests/serve.sh [port] [work-dir]
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
port=${1:-8099}
work=${2:-$(mktemp -d)}
mkdir -p "$work"
cp -a "$root/app/." "$work/"
php "$root/tests/write-test-config.php" "$work"
echo "serving $work on http://127.0.0.1:$port" >&2
exec php -d display_errors=1 -d error_reporting=E_ALL -S "127.0.0.1:$port" -t "$work"
