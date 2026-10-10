#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
env_file=${ORACLE_ENV_FILE:-$root/.env.oracle}
project=${ORACLE_PROJECT_NAME:-musako-oracle}
case "$project" in musako-oracle|musako-oracle-*) ;; *) echo 'Use a musako-oracle project name.' >&2; exit 1 ;; esac
[ -f "$env_file" ] || { echo 'Create the Oracle-only environment file first.' >&2; exit 1; }
exec docker compose --project-name "$project" --env-file "$env_file" -f "$root/compose.oracle.yaml" "$@"
