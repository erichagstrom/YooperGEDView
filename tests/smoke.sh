#!/usr/bin/env bash
# Black-box check: every listed page answers 200 and shows no PHP error. Usage: tests/smoke.sh
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
port=${PORT:-8099}
work=$(mktemp -d)
"$here/serve.sh" "$port" "$work/site" >"$work/server.log" 2>&1 &
server=$!
trap 'kill $server 2>/dev/null; rm -rf "$work"' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$port/GPL.txt" && break; sleep 0.2; done

pass=0; fail=0
for page in index.php login.php indilist.php famlist.php search.php calendar.php; do
  code=$(curl -s -m 60 -A "Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/100.0" -o "$work/body" -w '%{http_code}' "http://127.0.0.1:$port/$page")
  error=$(grep -m1 -oE '(Fatal error|Parse error|Warning|Deprecated|Notice)</b>:[^<]{0,160}' "$work/body" | sed 's#</b>##')
  if [ "$code" = 200 ] && [ -z "$error" ]; then pass=$((pass + 1)); echo "PASS  $page"
  else fail=$((fail + 1)); echo "FAIL  $page (HTTP $code) $error"; fi
done
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
