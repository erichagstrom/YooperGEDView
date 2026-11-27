#!/usr/bin/env bash
# Black-box check on PHP 8.3 with the sample tree: every listed page answers 200 with no PHP error or warning,
# as a visitor and as the administrator. Usage: tests/smoke.sh
# Carries on after a failed check so that all results are reported.
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
port=${PORT:-8099}
base="http://127.0.0.1:$port"
agent="Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/100.0"
work=$(mktemp -d)
"$here/serve.sh" "$port" "$work/site" >"$work/server.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null; rm -rf "$work"' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "$base/GPL.txt" && break; sleep 0.2; done

if ! php "$here/provision.php" "$base" "$work/site"; then
  echo "FAIL  provisioning"
  exit 1
fi

pass=0; fail=0
# check <who> <page>: who is "visitor" or "admin" (its cookie file).
check() {
  local code error
  code=$(curl -s -g -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" -w '%{http_code}' "$base/$2")
  error=$(grep -a -m1 -oE '(Fatal error|Parse error|Warning|Deprecated|Notice)(</b>)?: .{0,160}' "$work/body" | sed 's#</b>##')
  if [ "$code" = 200 ] && [ -z "$error" ]; then pass=$((pass + 1)); echo "PASS  $1  $2"
  else fail=$((fail + 1)); echo "FAIL  $1  $2 (HTTP $code) $error"; fi
}

pages() { grep -v '^#' "$here/$1" | grep -v '^$'; }

for page in $(pages pages-visitor.txt); do check visitor "$page"; done
curl -s -o /dev/null -A "$agent" -b "$work/admin" -c "$work/admin" "$base/login.php"
curl -s -o /dev/null -A "$agent" -b "$work/admin" -c "$work/admin" \
  --data-urlencode "username=admin" --data-urlencode "password=test-admin-password" --data "action=login&url=index.php" "$base/login.php"
for page in $(pages pages-visitor.txt) $(pages pages-admin.txt); do check admin "$page"; done

# expect <who> <page> has|lacks <text>: what each kind of user may see.
expect() {
  local found=lacks
  curl -s -g -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" "$base/$2"
  grep -a -q -- "$4" "$work/body" && found=has
  if [ "$found" = "$3" ]; then pass=$((pass + 1)); echo "PASS  $1  $2 $3 '$4'"
  else fail=$((fail + 1)); echo "FAIL  $1  $2 should $3 '$4'"; fi
}
expect admin "individual.php?pid=I8" has "February 1975"
expect admin "index.php" has "logout"
expect visitor "individual.php?pid=I1" has "March 1890"
expect visitor "individual.php?pid=I8" lacks "February 1975"
expect visitor "family.php?famid=F3" lacks "June 1970"
expect visitor "admin.php" lacks "useradmin.php"

# Exports: a full GEDCOM for the administrator, and living people's details left out of a "visitor" export.
export="downloadgedcom.php?action=download&ged=sample.ged&filetype=gedcom"
expect admin "$export&privatize_export=none" has "1 FEB 1975"
expect admin "$export&privatize_export=visitor" lacks "FEB 1975"
expect admin "$export&privatize_export=visitor" has "12 MAR 1890"
expect admin "downloadgedcom.php?action=download&ged=sample.ged&filetype=gramps&privatize_export=none" has "<database"
expect visitor "$export&privatize_export=none" lacks "0 @I1@ INDI"

# A zipped export is a real zip file holding the tree.
curl -s -g -L -m 120 -A "$agent" -b "$work/admin" -c "$work/admin" -o "$work/export.zip" "$base/$export&privatize_export=none&zip=yes"
if php -r '$z = new ZipArchive(); exit($z->open($argv[1]) === true && $z->locateName("sample.ged") !== false ? 0 : 1);' "$work/export.zip"; then
  pass=$((pass + 1)); echo "PASS  admin  zipped export"
else
  fail=$((fail + 1)); echo "FAIL  admin  zipped export"
fi

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
