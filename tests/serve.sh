#!/usr/bin/env bash
# Run the program on PHP's built-in server with an SQLite database, from a throw-away copy of app/.
# For development and tests only: listens on 127.0.0.1. Usage: tests/serve.sh [port] [work-dir]
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
port=${1:-8099}
work=${2:-$(mktemp -d)}
mkdir -p "$work"
cp -a "$root/app/." "$work/"
php -r '
$file = $argv[1] . "/config.php";
$marker = "require_once \x27./includes/session.php\x27;";
$text = file_get_contents($file);
if (substr_count($text, $marker) !== 1) { fwrite(STDERR, "unexpected config.php\n"); exit(1); }
$local = <<<PHP
\$DBTYPE = "sqlite";
\$DBNAME = __DIR__ . "/index/test.sqlite";
\$USE_REGISTRATION_MODULE = false;
\$MAX_VIEWS = "100000";
\$PGV_MEMORY_LIMIT = "256M";
\$CONFIGURED = true;

PHP;
file_put_contents($file, str_replace($marker, $local . $marker, $text));
' "$work"
echo "serving $work on http://127.0.0.1:$port" >&2
exec php -d display_errors=1 -d error_reporting=E_ALL -S "127.0.0.1:$port" -t "$work"
