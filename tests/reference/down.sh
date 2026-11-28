#!/usr/bin/env bash
# Stops the reference site and deletes its database. Usage: tests/reference/down.sh
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
REFERENCE_CONTEXT=$here docker compose -f "$here/compose.yaml" down --volumes
