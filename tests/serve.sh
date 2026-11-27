#!/usr/bin/env bash
# Run the program on PHP's built-in server with an SQLite database, from a throw-away copy of app/.
# For development and tests only: listens on 127.0.0.1. Usage: tests/serve.sh [port] [work-dir]
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
port=${1:-8099}
work=${2:-$(mktemp -d)}
if [ ! -f "$root/vendor/autoload.php" ]; then echo "no vendor/ folder: run composer install" >&2; exit 1; fi
# Same layout as an installed site: the program in app/, Composer's libraries beside it in vendor/ (which also
# loads src/). Sessions are kept in the work folder so that a test can look at them.
mkdir -p "$work/app" "$work/sessions"
cp -a "$root/app/." "$work/app/"
if [ ! -e "$work/vendor" ]; then ln -s "$root/vendor" "$work/vendor"; fi
php "$root/tests/write-test-config.php" "$work/app"
echo "serving $work/app on http://127.0.0.1:$port" >&2
exec php -d display_errors=1 -d error_reporting=E_ALL -d session.save_path="$work/sessions" -S "127.0.0.1:$port" -t "$work/app"
