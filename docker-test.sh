#!/usr/bin/env bash
set -euo pipefail

DRIVER_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CORE_ROOT="${KOUTENDB_CORE_DIR:-$(cd "$DRIVER_ROOT/../koutendb" 2>/dev/null && pwd || true)}"
IMAGE="${PHP_IMAGE:-koutendb-php-ffi:8.3}"

if [[ "$CORE_ROOT" == "" || ! -d "$CORE_ROOT" ]]; then
  echo "KOUTENDB_CORE_DIR must point to a KoutenDB core checkout" >&2
  exit 1
fi

if [[ ! -f "$CORE_ROOT/lib/libkoutendb.so" ]]; then
  echo "$CORE_ROOT/lib/libkoutendb.so not found; build the KoutenDB C ABI first" >&2
  exit 1
fi

docker build -t "$IMAGE" "$DRIVER_ROOT"

docker run --rm \
  -v "$DRIVER_ROOT":/driver \
  -v "$CORE_ROOT":/koutendb \
  -w /driver \
  -e LD_LIBRARY_PATH=/koutendb/lib \
  "$IMAGE" \
  sh -lc 'php -d ffi.enable=1 tests/driver_test.php && php -d ffi.enable=1 examples/embedded.php'
