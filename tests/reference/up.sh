#!/usr/bin/env bash
# Builds and starts the reference site (PhpGedView 4.3.0 as released, on PHP 5.6 and MariaDB), loads the sample
# tree into it through its own pages, and saves what a site owner would carry over to this program: a dump of the
# database and the "index" folder. Needs Docker, PHP 8.3 with curl, dom and pdo_mysql, and the whole git history.
# Usage: tests/reference/up.sh <folder to save the old site in>      To stop it: tests/reference/down.sh
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
out=${1:?usage: up.sh <folder to save the old site in>}
# The commit that added the release, untouched. Everything in app/ since is this project's change.
release=41a6f05
port=${REFERENCE_PORT:-8098}
db_port=${REFERENCE_DB_PORT:-3399}

context=$(mktemp -d)
trap 'rm -rf "$context"' EXIT
git -C "$root" archive "$release" app | tar -x -C "$context"
php "$root/docker/build-config.php" "$context/app/config.php" "$here/settings.inc"
cp "$here/Dockerfile" "$here/php.ini" "$context/"
cp "$root/tests/fixtures/sample.ged" "$context/app/index/sample.ged"

export REFERENCE_CONTEXT=$context
compose() { docker compose -f "$here/compose.yaml" "$@"; }
compose up --build --detach --wait --wait-timeout 300

DB_TYPE=mysql DB_HOST=127.0.0.1 DB_PORT=$db_port DB_NAME=pgv DB_USER=pgv DB_PASSWORD=for-tests-only \
  php "$root/tests/provision.php" "http://127.0.0.1:$port" --phpgedview

mkdir -p "$out"
# shellcheck disable=SC2016  # the container's own variables, to be filled in there
compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump -u"$MARIADB_USER" "$MARIADB_DATABASE"' > "$out/database.sql"
compose cp web:/var/www/html/index "$out/index" > /dev/null
echo "The reference site is at http://127.0.0.1:$port/ (admin / test-admin-password). Saved to $out: database.sql, index/"
