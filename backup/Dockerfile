# TMC backup service (tender §4.7: RPO 15 minutes) — see tmc-backup.sh.
# Same MariaDB release as the database service, so mariadb-dump always matches the server.
FROM mariadb:11.4.13

# rsync: incremental file snapshots (--link-dest); openssh-client: off-host copy (scripts/backup/offsite-copy.sh)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends rsync openssh-client ca-certificates; \
    rm -rf /var/lib/apt/lists/*; \
    groupadd --system --gid 10001 tmcbackup; \
    useradd --system --uid 10001 --gid 10001 --home-dir /backups --no-create-home --shell /usr/sbin/nologin tmcbackup; \
    mkdir -p /backups; \
    chown 10001:10001 /backups; \
    chmod 0700 /backups

COPY retention.awk /usr/local/lib/tmc-backup/retention.awk
COPY tmc-backup.sh /usr/local/bin/tmc-backup
RUN chmod 0755 /usr/local/bin/tmc-backup && chmod 0644 /usr/local/lib/tmc-backup/retention.awk

# Unprivileged: reads the WordPress volume (world-readable files) and writes only /backups.
USER 10001:10001
WORKDIR /backups
ENTRYPOINT ["/usr/local/bin/tmc-backup"]
CMD ["daemon"]
