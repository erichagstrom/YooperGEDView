#!/usr/bin/env bash
# Run the program on PHP's built-in server with an SQLite database, from a throw-away copy of app/.
# For development and tests only: listens on 127.0.0.1. Usage: tests/serve.sh [port] [work-dir]
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
port=${1:-8099}
work=${2:-$(mktemp -d)}
# Same layout as an installed site: the program in app/, Composer's libraries beside it in vendor/.
mkdir -p "$work/app"
cp -a "$root/app/." "$work/app/"
if [ -f "$root/vendor/autoload.php" ] && [ ! -e "$work/vendor" ]; then ln -s "$root/vendor" "$work/vendor"; fi
php "$root/tests/write-test-config.php" "$work/app"
echo "serving $work/app on http://127.0.0.1:$port" >&2
exec php -d display_errors=1 -d error_reporting=E_ALL -S "127.0.0.1:$port" -t "$work/app"
