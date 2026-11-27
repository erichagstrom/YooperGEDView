#!/usr/bin/env bash
# Runs inside the web container, where requests come from 127.0.0.1: the one address the image trusts as a
# reverse proxy by default. Acts as that proxy would: says who the real visitor is and that they used HTTPS.
# Usage: docker compose exec -T web bash -s < tests/proxy-check.sh
# Prints the response headers of a page fetched "over HTTPS"; leaves one failed sign-in by user "via-proxy".
set -euo pipefail
agent="Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/100.0"
visitor="X-Forwarded-For: 203.0.113.9"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# A failed sign-in, to see which address is recorded for it. Sent without the HTTPS header: curl would not
# send a Secure cookie back over the plain connection it really has.
curl -s -A "$agent" -H "$visitor" -c "$work/cookies" -o "$work/page" "http://127.0.0.1/login.php?url=index.php"
csrf=$(grep -o 'name="csrf" value="[0-9a-f]*"' "$work/page" | head -n 1 | cut -d'"' -f4)
curl -s -A "$agent" -H "$visitor" -b "$work/cookies" -o /dev/null \
  --data "action=login&url=index.php&username=via-proxy&password=wrong-password&csrf=$csrf" http://127.0.0.1/login.php

curl -s -A "$agent" -H "$visitor" -H "X-Forwarded-Proto: https" -D - -o /dev/null "http://127.0.0.1/login.php?url=index.php"
