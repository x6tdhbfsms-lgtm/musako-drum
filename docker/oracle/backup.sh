#!/bin/bash
set -Eeuo pipefail
umask 077
export TZ=Asia/Tokyo
if [[ ${1:-loop} == once ]]; then
    trap 'echo "$(date -Iseconds) backup FAILED" >&2' ERR
    BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-14}
    [[ ${DB_DATABASE:-} =~ ^[A-Za-z0-9_]+$ ]] || { echo 'backup FAILED: invalid database name' >&2; exit 1; }
    [[ $BACKUP_RETENTION_DAYS =~ ^([7-9]|[12][0-9]|30)$ ]] || { echo 'backup FAILED: retention must be 7–30 days' >&2; exit 1; }
    mkdir -p /backups
    temporary=$(mktemp "/backups/.${DB_DATABASE}-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
    trap 'rm -f -- "$temporary"' EXIT
    mysqldump --host=mysql --user=root --single-transaction --quick \
        --routines --events --triggers --no-tablespaces --set-gtid-purged=OFF \
        --column-statistics=0 --databases "$DB_DATABASE" | gzip > "$temporary"
    gzip -t "$temporary"
    target="/backups/$(basename "$temporary" | cut -c2-).sql.gz"
    mv -- "$temporary" "$target"
    find /backups -maxdepth 1 -type f -name "${DB_DATABASE}-*.sql.gz" -mmin "+$((BACKUP_RETENTION_DAYS * 1440))" -delete
    touch /backups/.last-success
    echo "$(date -Iseconds) backup OK $(basename "$target") $(stat -c%s "$target") bytes"
    exit 0
fi
while true; do
    if bash "$0" once; then delay=86400; else delay=300; fi
    sleep "$delay" &
    wait $!
done
