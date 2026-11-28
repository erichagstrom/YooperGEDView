#!/usr/bin/env bash
# Moves the reference site to this program, the way its owner would: a copy of the database and of the index
# folder, "bin/yooper upgrade-from-pgv", a new password for the administrator. Then requests the pages of the
# smoke test from the moved site. The reference site must be running (tests/reference/up.sh); it is not changed.
# Usage: tests/reference/moved.sh <folder that up.sh saved the old site in>
set -uo pipefail
here=$(cd "$(dirname "$0")/.." && pwd)
root=$(cd "$here/.." && pwd)
saved=${1:?usage: moved.sh <folder that up.sh saved the old site in>}
port=${PORT:-8097}
base="http://127.0.0.1:$port"
agent="Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/100.0"
work=$(mktemp -d)
log="$work/results.txt"
report() { echo "$1" | tee -a "$log"; }
pass=0; fail=0
# shellcheck source-path=SCRIPTDIR/.. source=checks.sh
source "$here/checks.sh"
compose() { REFERENCE_CONTEXT=$here docker compose -f "$here/reference/compose.yaml" "$@"; }
yooper() { php "$root/bin/yooper" "$@"; }
# said <text> <what was checked>: the last report must hold the text.
said() { if grep -q -F -- "$1" "$work/report"; then result ok "$2"; else result other "$2: the report lacks '$1'"; fi; }
# no_warnings <what was checked>: no line of the last report may start with "!".
no_warnings() { if grep -q '^ *!' "$work/report"; then result other "$1: $(grep -m1 '^ *!' "$work/report")"; else result ok "$1"; fi; }

# The copy: a database of its own beside the old site's, and the index folder in a new place.
# shellcheck disable=SC2016  # the container's own variables, to be filled in there
compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -e "DROP DATABASE IF EXISTS moved; CREATE DATABASE moved; GRANT ALL ON moved.* TO \`$MARIADB_USER\`@\`%\`"' || exit 1
# shellcheck disable=SC2016
compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u"$MARIADB_USER" moved' < "$saved/database.sql" || exit 1
mkdir -p "$work/site/app/index"
cp -R "$saved/index/." "$work/site/app/index/"
export DB_TYPE=mysql DB_HOST=127.0.0.1 DB_PORT=${REFERENCE_DB_PORT:-3399} DB_NAME=moved DB_USER=pgv DB_PASSWORD=for-tests-only

yooper upgrade-from-pgv --index="$work/site/app/index" > "$work/report" 2>&1
cat "$work/report"
said "The tables are at version 14." "the dry run finds the tables PhpGedView 4.3.0 made"
said "2 have a password stored the way PhpGedView stored it" "the dry run finds the old passwords"
said "1 user chose a language other than English" "the dry run finds the user with another language"
said "tables hold text in a character set other than utf8mb4" "the dry run finds the latin1 tables"
said "sample.ged_conf.php: settings only." "the settings file PhpGedView wrote is taken for what it is"
said "Nothing has been changed." "the dry run changes nothing"
no_warnings "the dry run has no warnings"

yooper upgrade-from-pgv --index="$work/site/app/index" --apply > "$work/report" 2>&1
cat "$work/report"
said "have been converted to utf8mb4_swedish_ci." "the tables are converted"
said "The changes above have been made." "the changes are made"
no_warnings "making the changes has no warnings"

"$here/serve.sh" "$port" "$work/site" >"$work/server.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null; rm -rf "$work"' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "$base/GPL.txt" && break; sleep 0.2; done

# The first page brings the tables up to date. The password from the old site no longer signs anyone in.
check visitor "index.php"
sign_in "$work/admin" admin "test-admin-password"
if signed_in "$work/admin"; then result other "the old site's password is refused"; else result ok "the old site's password is refused"; fi
if printf 'moved-admin-password\n' | yooper set-password admin > "$work/report" 2>&1; then result ok "the administrator is given a new password"
else result other "the administrator is given a new password: $(head -n 1 "$work/report")"; fi
sign_in "$work/admin" admin "moved-admin-password"
if signed_in "$work/admin"; then result ok "the administrator signs in with the new password"; else result other "the administrator signs in with the new password"; fi

for page in $(pages pages-visitor.txt); do check visitor "$page"; done
for page in $(pages pages-visitor.txt) $(pages pages-admin.txt); do check admin "$page"; done
expect admin "individual.php?pid=I8" has "February 1975"
expect visitor "individual.php?pid=I1" has "March 1890"
expect visitor "individual.php?pid=I8" lacks "February 1975"
expect visitor "family.php?famid=F3" lacks "June 1970"
expect visitor "admin.php" lacks "useradmin.php"

# Every table, the ones this program added on its first page included, under the same rules.
# shellcheck disable=SC2016
collations=$(compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -N -u"$MARIADB_USER" -e "SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = \"moved\""' | tr '\n' ' ')
if [ "$collations" = "utf8mb4_swedish_ci " ]; then result ok "every table is utf8mb4_swedish_ci"; else result other "every table is utf8mb4_swedish_ci: $collations"; fi

# What a person reads on each page, here and on the reference site.
if php "$here/reference/compare.php" "http://127.0.0.1:${REFERENCE_PORT:-8098}" "$base" "moved-admin-password"; then result ok "the pages read as they do on PhpGedView, but for the explained differences"
else result other "the pages read as they do on PhpGedView, but for the explained differences"; fi

echo "$pass passed, $fail failed"
if [ "$fail" != 0 ]; then echo "--- the moved site's server log"; tail -n 40 "$work/server.log"; exit 1; fi
