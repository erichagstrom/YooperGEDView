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

pass=0; fail=0
# A site with no administrator yet says how to make one, without PHP errors. There is no web installer.
first=$(curl -s -m 120 -A "$agent" -D "$work/first-headers" "$base/index.php")
if grep -q '^HTTP/[0-9.]* 503' "$work/first-headers" && grep -q 'bin/yooper install' <<<"$first" && ! grep -qE 'Fatal error|Warning|Deprecated|ERROR [0-9]+:' <<<"$first"; then
  pass=$((pass + 1)); report "PASS  visitor  empty site says it needs an administrator"
else
  fail=$((fail + 1)); report "FAIL  visitor  empty site says it needs an administrator"
fi

if ! php "$here/provision.php" "$base" "$work/site/app"; then
  report "FAIL  provisioning"
  exit 1
fi

# check <who> <page>: who is "visitor" or "admin" (its cookie file).
check() {
  local code error forms fields outside
  code=$(curl -s -g -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" -w '%{http_code}' "$base/$2")
  error=$(grep -a -m1 -oE '((Fatal error|Parse error|Warning|Deprecated|Notice)(</b>)?|ERROR [0-9]+): .{0,160}' "$work/body" | sed 's#</b>##')
  # Every form sent by POST must carry the token against forged requests.
  forms=$(grep -a -o -i -E "<form[^>]*method *= *[\"']?post" "$work/body" | wc -l)
  fields=$(grep -a -o 'name="csrf" value="[0-9a-f]\{64\}"' "$work/body" | wc -l)
  if [ "$forms" != "$fields" ]; then error="$forms forms sent by POST but $fields token fields. $error"; fi
  # Nothing in a page may be fetched from another site: that tells the other site who is looking at what.
  outside=$(grep -a -o -i -E "src=[\"']?(https?:)?//[^\"' >]+" "$work/body" | grep -v -F "//127.0.0.1:$port/" | head -n 1)
  if [ -n "$outside" ]; then error="loads from another site: ${outside:0:100}. $error"; fi
  if [ "$code" = 200 ] && [ -z "$error" ]; then pass=$((pass + 1)); report "PASS  $1  $2"
  else fail=$((fail + 1)); report "FAIL  $1  $2 (HTTP $code) $error"; fi
}

# session_id <cookie file>: the session id held in a curl cookie file.
session_id() { awk '$6 == "PHPSESSID" { print $7 }' "$1"; }
# token <cookie file>: the token that a form sent by POST must carry, taken from the sign-in page.
token() {
  curl -s -A "$agent" -b "$1" -c "$1" -o "$work/token-page" "$base/login.php?url=index.php"
  grep -a -o 'name="csrf" value="[0-9a-f]*"' "$work/token-page" | head -n 1 | cut -d'"' -f4
}
# sign_in <cookie file> <user> <password>: load the sign-in page, then send its form; the answer is in $work/body.
sign_in() {
  local csrf
  csrf=$(token "$1")
  curl -s -o "$work/body" -A "$agent" -b "$1" -c "$1" --data-urlencode "username=$2" --data-urlencode "password=$3" \
    --data "action=login&url=index.php&csrf=$csrf" "$base/login.php"
}
signed_in() {
  curl -s -A "$agent" -b "$1" -c "$1" -o "$work/body" "$base/index.php"
  grep -a -q "logout=1" "$work/body"
}

pages() { grep -v '^#' "$here/$1" | grep -v '^$'; }

for page in $(pages pages-visitor.txt); do check visitor "$page"; done
sign_in "$work/admin" admin "test-admin-password"
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
report_options="output=HTML&vars[pid]=I1&vars[famid]=F1&vars[sources]=1&vars[notes]=1&vars[pageSize]=A4&vars[maxgen]=3&vars[fonts]=dejavusans"
report_options="$report_options&vars[name]=&vars[sortby]=NAME&vars[relatives]=child-family&type[pid]=INDI&type[famid]=FAM"
for name in pid famid sources notes pageSize maxgen fonts name sortby relatives; do report_options="$report_options&varnames[]=$name"; done
for report in "$here"/../app/reports/*.xml; do
  check admin "reportengine.php?action=run&report=reports/$(basename "$report")&$report_options"
done
expect admin "reportengine.php?action=run&report=reports/individual.xml&$report_options" has "Kivela"
# The sample tree's source has an author written to run as PHP if a report ever makes it part of a line of code.
# The family report tests that author in a condition. The text must come out as it was written, and not as
# 982007569, the number it would print if it ran.
expect admin "reportengine.php?action=run&report=reports/familygroup.xml&$report_options" has "The clerk"
expect admin "reportengine.php?action=run&report=reports/familygroup.xml&$report_options" lacks "982007569"

# A report as a PDF file.
pdf_options=${report_options/output=HTML/output=PDF}
curl -s -g -m 120 -A "$agent" -b "$work/admin" -c "$work/admin" -o "$work/report.pdf" "$base/reportengine.php?action=run&report=reports/individual.xml&$pdf_options"
if [ "$(head -c 5 "$work/report.pdf")" = "%PDF-" ]; then pass=$((pass + 1)); report "PASS  admin  individual report as PDF"
else fail=$((fail + 1)); report "FAIL  admin  individual report as PDF: $(head -c 300 "$work/report.pdf" | tr -d '\n')"; fi

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

# The old pages for running the installer, editing files and showing the server's settings are gone.
for page in install.php dir_editor.php editlang.php editlang_edit.php editlang_edit_settings.php pgvinfo.php config_download.php usermigrate.php usermigrate_cli.php changelanguage.php; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -A "$agent" -b "$work/admin" -c "$work/admin" "$base/$page")
  if [ "$code" = 404 ]; then pass=$((pass + 1)); report "PASS  admin  $page is gone"; else fail=$((fail + 1)); report "FAIL  admin  $page is gone (HTTP $code)"; fi
done

# Sign-in and sessions. These come last: the final check signs the administrator out.
# result <ok|other> <what>: record a check made by hand.
result() {
  if [ "$1" = ok ]; then pass=$((pass + 1)); report "PASS  $2"; else fail=$((fail + 1)); report "FAIL  $2"; fi
}

curl -s -o /dev/null -A "$agent" -D "$work/cookie-headers" "$base/index.php"
cookie=$(grep -i '^set-cookie: PHPSESSID=' "$work/cookie-headers" | tail -n 1)
if grep -qi 'httponly' <<<"$cookie" && grep -qi 'samesite=lax' <<<"$cookie"; then result ok "session cookie is HttpOnly and SameSite=Lax"
else result other "session cookie is HttpOnly and SameSite=Lax: $cookie"; fi

curl -s -o /dev/null -A "$agent" -b "PHPSESSID=chosenbythecaller0000000000" -D "$work/cookie-headers" "$base/index.php"
if grep -i '^set-cookie: PHPSESSID=' "$work/cookie-headers" | grep -qv 'chosenbythecaller'; then result ok "a session id chosen by the caller is replaced"
else result other "a session id chosen by the caller is replaced"; fi
curl -s -o /dev/null -A "$agent" -D "$work/cookie-headers" "$base/index.php?PHPSESSID=chosenbythecaller0000000000"
if grep -i '^set-cookie: PHPSESSID=' "$work/cookie-headers" | grep -qv 'chosenbythecaller'; then result ok "a session id in the address is ignored"
else result other "a session id in the address is ignored"; fi

sign_in "$work/wrong" admin "not-the-password"
if signed_in "$work/wrong"; then result other "a wrong password is refused"; else result ok "a wrong password is refused"; fi
sign_in "$work/legacy" legacy "test-admin-password"
if signed_in "$work/legacy"; then result other "a password stored in the old MD5 format is refused"; else result ok "a password stored in the old MD5 format is refused"; fi

curl -s -o /dev/null -A "$agent" -b "$work/fresh" -c "$work/fresh" "$base/login.php?url=index.php"
before=$(session_id "$work/fresh")
sign_in "$work/fresh" admin "test-admin-password"
after=$(session_id "$work/fresh")
if signed_in "$work/fresh" && [ -n "$before" ] && [ "$before" != "$after" ]; then result ok "signing in gives a new session id"
else result other "signing in gives a new session id (before '$before', after '$after')"; fi
if [ -e "$work/site/sessions/sess_$before" ]; then result other "the session from before sign-in is gone"; else result ok "the session from before sign-in is gone"; fi

curl -s -o /dev/null -A "$agent" -b "$work/fresh" -c "$work/fresh" "$base/index.php?logout=1"
if signed_in "$work/fresh"; then result other "signing out ends the session"; else result ok "signing out ends the session"; fi

# Forged requests: a POST is refused unless it carries the token of the session it is sent in.
# post_status <cookie file> <token>: the HTTP status of a POST to the sign-in page.
post_status() {
  curl -s -o /dev/null -w '%{http_code}' -A "$agent" -b "$1" -c "$1" --data "action=login&url=index.php&username=nobody&password=whatever&csrf=$2" "$base/login.php"
}
own=$(token "$work/csrf-a")
others=$(token "$work/csrf-b")
if [ "$(post_status "$work/csrf-a" "")" = 403 ]; then result ok "a POST without the token is refused"; else result other "a POST without the token is refused"; fi
if [ "$(post_status "$work/csrf-a" "$others")" = 403 ]; then result ok "a POST with another session's token is refused"; else result other "a POST with another session's token is refused"; fi
if [ ${#own} = 64 ] && [ "$(post_status "$work/csrf-a" "$own")" = 200 ]; then result ok "a POST with the session's own token is accepted"; else result other "a POST with the session's own token is accepted"; fi
before=$(token "$work/csrf-a")
sign_in "$work/csrf-a" admin "test-admin-password"
if signed_in "$work/csrf-a" && [ "$(post_status "$work/csrf-a" "$before")" = 403 ]; then result ok "the token from before sign-in is refused afterwards"
else result other "the token from before sign-in is refused afterwards"; fi

# What the browser is told it may do with a page.
curl -s -o /dev/null -A "$agent" -D "$work/page-headers" "$base/index.php"
# sends_header <name> <text>: the page's response has this header, once, with this text in it.
sends_header() { [ "$(grep -i -c "^$1:" "$work/page-headers")" = 1 ] && grep -i "^$1:" "$work/page-headers" | grep -q -- "$2"; }
if sends_header Content-Security-Policy "default-src 'self'" && sends_header Content-Security-Policy "form-action 'self'"; then result ok "pages carry a content security policy"
else result other "pages carry a content security policy"; fi
if sends_header X-Content-Type-Options nosniff && sends_header X-Frame-Options SAMEORIGIN && sends_header Referrer-Policy same-origin; then result ok "pages carry the type, frame and referrer headers"
else result other "pages carry the type, frame and referrer headers"; fi
if grep -q -i '^Strict-Transport-Security:' "$work/page-headers"; then result other "a site reached without HTTPS does not ask browsers to insist on it"
else result ok "a site reached without HTTPS does not ask browsers to insist on it"; fi

# Links from other sites: a signed-in visitor sent to an address with parameters must confirm first.
# from_elsewhere <cookie file> <header> <page>: fetch a page as a browser coming from another site would.
from_elsewhere() { curl -s -g -A "$agent" -b "$1" -c "$1" -H "$2" -o "$work/body" "$base/$3"; }
from_elsewhere "$work/csrf-a" "Sec-Fetch-Site: cross-site" "index.php?logout=1"
if grep -a -q "another site has sent you" "$work/body" && signed_in "$work/csrf-a"; then result ok "a sign-out link on another site does nothing until confirmed"
else result other "a sign-out link on another site does nothing until confirmed"; fi
from_elsewhere "$work/csrf-a" "Referer: https://elsewhere.example/page" "individual.php?pid=I1"
if grep -a -q 'href="/individual.php?pid=I1">continue' "$work/body"; then result ok "an older browser's Referer header is used the same way"
else result other "an older browser's Referer header is used the same way"; fi
from_elsewhere "$work/csrf-a" "Sec-Fetch-Site: same-origin" "individual.php?pid=I8"
if grep -a -q "February 1975" "$work/body"; then result ok "a link followed on this site is not held up"
else result other "a link followed on this site is not held up"; fi
from_elsewhere "$work/visitor" "Sec-Fetch-Site: cross-site" "individual.php?pid=I1"
if grep -a -q "March 1890" "$work/body"; then result ok "a visitor who is not signed in is not held up"
else result other "a visitor who is not signed in is not held up"; fi

# Limit on failed attempts: after ten wrong passwords the right one is refused too, and the page says why.
for _ in $(seq 10); do sign_in "$work/locked" admin "not-the-password"; done
sign_in "$work/locked" admin "test-admin-password"
if grep -a -q "Too many failed attempts" "$work/body" && ! signed_in "$work/locked"; then result ok "ten failed sign-ins lock the user name"
else result other "ten failed sign-ins lock the user name"; fi
sign_in "$work/other" legacy "x-wrong"
if grep -a -q "Unable to authenticate" "$work/body"; then result ok "another user name is not locked with it"
else result other "another user name is not locked with it"; fi
attempts=$(php "$here/failed-sign-ins.php" "$work/site/app/index/test.sqlite" admin)
if [ "$attempts" = 11 ]; then result ok "failed sign-ins are recorded, refused ones are not counted"
else result other "failed sign-ins are recorded, refused ones are not counted (found $attempts, expected 11)"; fi

# Idle time-out: wind the administrator's last activity back to long ago; the next request is a visitor's.
sed -i -E 's/last_activity\|i:[0-9]+;/last_activity|i:1;/' "$work/site/sessions/sess_$(session_id "$work/admin")"
if signed_in "$work/admin"; then result other "an idle session is signed out"; else result ok "an idle session is signed out"; fi

# A lost password. This comes after everything else: it changes the administrator's password.
# ask_for_link <user>: fill in the "lost password" form; the answer is in $work/body.
ask_for_link() {
  local csrf
  csrf=$(token "$work/lost")
  curl -s -o "$work/body" -A "$agent" -b "$work/lost" -c "$work/lost" --data-urlencode "user_name=$1" --data "action=requestpw&csrf=$csrf" "$base/login_register.php"
}
# choose_password <link token> <password> <password again>: send the form behind the link; the answer is in $work/body.
choose_password() {
  local csrf
  csrf=$(token "$work/lost")
  curl -s -o "$work/body" -A "$agent" -b "$work/lost" -c "$work/lost" --data-urlencode "user_password01=$2" --data-urlencode "user_password02=$3" \
    --data "action=setpw&token=$1&csrf=$csrf" "$base/login_register.php"
}
# The limit on failed attempts for this name was reached earlier in this run; clear the record to sign in again.
php "$here/failed-sign-ins.php" "$work/site/app/index/test.sqlite" admin --forget > /dev/null
sign_in "$work/elsewhere" admin "test-admin-password"
: > "$work/site/mail"
ask_for_link nobody-by-that-name
answer_unknown=$(grep -a -c "a message has been sent" "$work/body")
ask_for_link admin
answer_known=$(grep -a -c "a message has been sent" "$work/body")
link=$(grep -a -o 'login_register.php?action=resetpw&token=[0-9a-f]\{64\}' "$work/site/mail" | head -n 1)
if [ "$answer_unknown" = 1 ] && [ "$answer_known" = 1 ] && [ "$(grep -a -c 'action=resetpw' "$work/site/mail")" = 1 ]; then result ok "asking for a password link answers the same for any name and mails only a real user"
else result other "asking for a password link answers the same for any name and mails only a real user"; fi
if grep -a -q "test-admin-password" "$work/site/mail" || ! signed_in "$work/elsewhere"; then result other "asking for a link changes nothing and mails no password"
else result ok "asking for a link changes nothing and mails no password"; fi
ask_for_link admin
if [ "$(grep -a -c 'action=resetpw' "$work/site/mail")" = 1 ]; then result ok "asking again straight away sends no second message"
else result other "asking again straight away sends no second message"; fi
curl -s -o "$work/body" -A "$agent" -b "$work/lost" -c "$work/lost" "$base/$link"
if [ -n "$link" ] && grep -a -q 'name="user_password01"' "$work/body"; then result ok "the link in the message offers a new password"
else result other "the link in the message offers a new password"; fi
choose_password "${link##*=}" "a-new-password-1" "a-different-one-2"
if grep -a -q "Passwords do not match" "$work/body"; then result ok "two different new passwords are refused"; else result other "two different new passwords are refused"; fi
choose_password "$(printf 'a%.0s' $(seq 64))" "a-new-password-1" "a-new-password-1"
if grep -a -q "This link does not work any more" "$work/body"; then result ok "a made-up link is refused"; else result other "a made-up link is refused"; fi
choose_password "${link##*=}" "a-new-password-1" "a-new-password-1"
if grep -a -q "Your password has been changed" "$work/body"; then result ok "the link sets the new password"; else result other "the link sets the new password"; fi
if signed_in "$work/elsewhere"; then result other "sessions from before the change are ended"; else result ok "sessions from before the change are ended"; fi
curl -s -o "$work/body" -A "$agent" -b "$work/lost" -c "$work/lost" "$base/$link"
if grep -a -q "This link does not work any more" "$work/body"; then result ok "the link works only once"; else result other "the link works only once"; fi
sign_in "$work/old" admin "test-admin-password"
if signed_in "$work/old"; then result other "the old password no longer works"; else result ok "the old password no longer works"; fi
sign_in "$work/new" admin "a-new-password-1"
if signed_in "$work/new"; then result ok "the new password works"; else result other "the new password works"; fi

# Repeat the failures at the end, where they are easy to find.
grep "^FAIL" "$log" || true
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
