#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
exec "$root/docker/oracle/compose.sh" -f "$root/compose.oracle.micro.yaml" "$@"
