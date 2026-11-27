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
log="$work/results.txt"
report() { echo "$1" | tee -a "$log"; }
"$here/serve.sh" "$port" "$work/site" >"$work/server.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null; rm -rf "$work"' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "$base/GPL.txt" && break; sleep 0.2; done

if ! php "$here/provision.php" "$base" "$work/site/app"; then
  report "FAIL  provisioning"
  exit 1
fi

pass=0; fail=0
# check <who> <page>: who is "visitor" or "admin" (its cookie file).
check() {
  local code error
  code=$(curl -s -g -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" -w '%{http_code}' "$base/$2")
  error=$(grep -a -m1 -oE '((Fatal error|Parse error|Warning|Deprecated|Notice)(</b>)?|ERROR [0-9]+): .{0,160}' "$work/body" | sed 's#</b>##')
  if [ "$code" = 200 ] && [ -z "$error" ]; then pass=$((pass + 1)); report "PASS  $1  $2"
  else fail=$((fail + 1)); report "FAIL  $1  $2 (HTTP $code) $error"; fi
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
  if [ "$found" = "$3" ]; then pass=$((pass + 1)); report "PASS  $1  $2 $3 '$4'"
  else fail=$((fail + 1)); report "FAIL  $1  $2 should $3 '$4'"; fi
}
expect admin "individual.php?pid=I8" has "February 1975"
expect admin "index.php" has "logout"
expect visitor "individual.php?pid=I1" has "March 1890"
expect visitor "individual.php?pid=I8" lacks "February 1975"
expect visitor "family.php?famid=F3" lacks "June 1970"
expect visitor "admin.php" lacks "useradmin.php"

# Every report, as a web page.
report_options="output=HTML&vars[pid]=I1&vars[famid]=F1&vars[sources]=on&vars[notes]=on&vars[pageSize]=A4&vars[maxgen]=3&vars[fonts]=dejavusans"
report_options="$report_options&vars[name]=&vars[sortby]=NAME&vars[relatives]=child-family&type[pid]=INDI&type[famid]=FAM"
for name in pid famid sources notes pageSize maxgen fonts name sortby relatives; do report_options="$report_options&varnames[]=$name"; done
for report in "$here"/../app/reports/*.xml; do
  check admin "reportengine.php?action=run&report=reports/$(basename "$report")&$report_options"
done
expect admin "reportengine.php?action=run&report=reports/individual.xml&$report_options" has "Kivela"

# A report as a PDF file. Needs Composer's libraries (composer install).
if [ -f "$here/../vendor/autoload.php" ]; then
  pdf_options=${report_options/output=HTML/output=PDF}
  curl -s -g -m 120 -A "$agent" -b "$work/admin" -c "$work/admin" -o "$work/report.pdf" "$base/reportengine.php?action=run&report=reports/individual.xml&$pdf_options"
  if [ "$(head -c 5 "$work/report.pdf")" = "%PDF-" ]; then pass=$((pass + 1)); report "PASS  admin  individual report as PDF"
  else fail=$((fail + 1)); report "FAIL  admin  individual report as PDF: $(head -c 300 "$work/report.pdf" | tr -d '\n')"; fi
else
  report "SKIP  admin  individual report as PDF (no vendor/ folder: run composer install)"
fi

# Exports: a full GEDCOM for the administrator, and living people's details left out of a "visitor" export.
export="downloadgedcom.php?action=download&ged=sample.ged&filetype=gedcom"
expect admin "$export&privatize_export=none" has "1 FEB 1975"
expect admin "$export&privatize_export=visitor" lacks "FEB 1975"
expect admin "$export&privatize_export=visitor" has "12 MAR 1890"
expect admin "downloadgedcom.php?action=download&ged=sample.ged&filetype=gramps&privatize_export=none" has "<database"
expect visitor "$export&privatize_export=none" lacks "0 @I1@ INDI"

# A zipped export is a real zip file holding the tree.
curl -s -g -L -m 120 -A "$agent" -b "$work/admin" -c "$work/admin" -o "$work/export.zip" "$base/$export&privatize_export=none&zip=yes"
if unzip -Z1 "$work/export.zip" 2>/dev/null | grep -qx "sample.ged"; then
  pass=$((pass + 1)); report "PASS  admin  zipped export"
else
  fail=$((fail + 1)); report "FAIL  admin  zipped export"
fi

# Repeat the failures at the end, where they are easy to find.
grep "^FAIL" "$log" || true
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
