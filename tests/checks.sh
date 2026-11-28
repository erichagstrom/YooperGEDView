#!/usr/bin/env bash
# The checks that tests/smoke.sh and tests/reference/moved.sh share. Sourced, not run. The script that sources
# it sets: base (the site's address), port, agent (the browser to pass for), work (a scratch folder), and the
# counters pass and fail, and here (the tests folder, for the lists of pages); and defines report <line>.
# shellcheck shell=bash
# shellcheck disable=SC2154  # set by the script that sources this file

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

# expect <who> <page> has|lacks <text>: what each kind of user may see.
expect() {
  local found=lacks
  curl -s -g -m 120 -A "$agent" -b "$work/$1" -c "$work/$1" -o "$work/body" "$base/$2"
  grep -a -q -- "$4" "$work/body" && found=has
  if [ "$found" = "$3" ]; then pass=$((pass + 1)); report "PASS  $1  $2 $3 '$4'"
  else fail=$((fail + 1)); report "FAIL  $1  $2 should $3 '$4'"; fi
}

# result <ok|other> <what>: record a check made by hand.
result() {
  if [ "$1" = ok ]; then pass=$((pass + 1)); report "PASS  $2"; else fail=$((fail + 1)); report "FAIL  $2"; fi
}
