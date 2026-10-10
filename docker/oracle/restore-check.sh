#!/bin/bash
# Restore only into a new, network-isolated disposable MySQL container.
set -Eeuo pipefail
root=$(cd -- "$(dirname -- "$0")/../.." && pwd)
compose="$root/docker/oracle/compose.sh"
file=${1:?Usage: restore-check.sh backup-file.sql.gz}
[[ "$file" =~ ^musako_trial-[A-Za-z0-9-]+\.sql\.gz$ ]] || { echo 'Use a backup basename, not a path.' >&2; exit 1; }
image=mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242
umask 077
secret_file=$(mktemp)
container=
cleanup() {
    rm -f "$secret_file"
    if [[ -n "$container" ]]; then
        docker rm -fv "$container" >/dev/null || echo "Remove the temporary restore container: $container" >&2
    fi
}
trap cleanup EXIT
printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 32)" > "$secret_file"
printf 'MYSQL_DATABASE=musako_trial\n' >> "$secret_file"
container=$(docker run -d --platform linux/arm64 --network none --env-file "$secret_file" "$image")
ready=false
for ((attempt=0; attempt<60; attempt++)); do
    if docker exec "$container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "SELECT 1"' >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 2
done
[[ "$ready" == true ]] || { echo 'Restore MySQL did not become ready.' >&2; exit 1; }
"$compose" exec -T backup gzip -t "/backups/$file"
"$compose" exec -T backup gzip -dc "/backups/$file" |
    docker exec -i "$container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot'
tables=$(docker exec "$container" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"musako_trial\""')
[[ "$tables" -gt 0 ]] || { echo 'Restore contained no application tables.' >&2; exit 1; }
echo "Restore OK: $tables tables; isolated container will be removed."
