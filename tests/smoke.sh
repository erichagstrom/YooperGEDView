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
  code=$(curl -s -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" -w '%{http_code}' "$base/$2")
  error=$(grep -a -m1 -oE '(Fatal error|Parse error|Warning|Deprecated|Notice)(</b>)?: .{0,160}' "$work/body" | sed 's#</b>##')
  if [ "$code" = 200 ] && [ -z "$error" ]; then pass=$((pass + 1)); echo "PASS  $1  $2"
  else fail=$((fail + 1)); echo "FAIL  $1  $2 (HTTP $code) $error"; fi
}

visitor_pages="index.php login.php?url=index.php indilist.php famlist.php search.php calendar.php individual.php?pid=I1 family.php?famid=F1"
admin_pages="$visitor_pages
  sourcelist.php source.php?sid=S1 placelist.php medialist.php pedigree.php?rootid=I8 descendancy.php?pid=I1
  ancestry.php?rootid=I8 familybook.php?pid=I1 hourglass.php?pid=I3 timeline.php?pids[]=I1 lifespan.php
  relationship.php?pid1=I8&pid2=I1 statistics.php branches.php?surn=Maki search.php?action=general&query=Maki&srindi=yes
  admin.php useradmin.php editgedcoms.php edituser.php clippings.php faq.php
  individual.php?pid=I8 individual.php?pid=I3 family.php?famid=F3"

for page in $visitor_pages; do check visitor "$page"; done
curl -s -o /dev/null -A "$agent" -b "$work/admin" -c "$work/admin" "$base/login.php"
curl -s -o /dev/null -A "$agent" -b "$work/admin" -c "$work/admin" \
  --data-urlencode "username=admin" --data-urlencode "password=test-admin-password" --data "action=login&url=index.php" "$base/login.php"
for page in $admin_pages; do check admin "$page"; done

# expect <who> <page> has|lacks <text>: what each kind of user may see.
expect() {
  local found=lacks
  curl -s -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" "$base/$2"
  grep -a -q -- "$4" "$work/body" && found=has
  if [ "$found" = "$3" ]; then pass=$((pass + 1)); echo "PASS  $1  $2 $3 '$4'"
  else fail=$((fail + 1)); echo "FAIL  $1  $2 should $3 '$4'"; fi
}
expect admin "individual.php?pid=I8" has "1975"
expect admin "index.php" has "logout"
expect visitor "individual.php?pid=I1" has "1890"
expect visitor "individual.php?pid=I8" lacks "1975"
expect visitor "family.php?famid=F3" lacks "1970"
expect visitor "admin.php" lacks "useradmin.php"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
