# Quick Reference: Operations (TMC IT infrastructure)

**Where:** Docker host, service account, `~/docker/tmc-website` · **Full manual:**
[System and Security Administration Manual](../system-security-administration-manual.md)

```bash
cd ~/docker/tmc-website && set -a && . ./.env && set +a
wp() { docker compose run --rm -T wpcli "$@" </dev/null; }
```

## Daily

| Check | Command | Good result |
|---|---|---|
| Containers | `docker compose ps` | `tmc-wp`, `tmc-db` (healthy), `tmc-redis`, `tmc-cron` running |
| Websites | `./scripts/smoke-test.sh` | `smoke test passed` |
| PHP errors | `docker compose logs --since 24h wordpress \| grep "PHP Fatal"` | nothing |
| Disk | `df -h` | ≥ 20 % free |
| Live release | `tail -1 .release-history` | expected commit |

## Common tasks

| Task | Command |
|---|---|
| Logs | `docker compose logs --tail=200 wordpress` / `cron` |
| Start after reboot | `docker compose up -d` |
| Apply an `.env` change | `docker compose up -d wordpress cron` |
| Flush cache | `wp --url="$TMC_BASE_DOMAIN" cache flush` |
| Scheduled events | `wp --url="tmh.$TMC_BASE_DOMAIN" cron event list --fields=hook,next_run_relative` |
| On-demand DB backup | [Backup §3.2](../../operations/backup-restore.md#32-database) |
| Restore | [Backup §4](../../operations/backup-restore.md#4-restore-procedures) |
| Deploy / roll back | Only via *Actions → Pipeline* ([Installation Guide §4–5](../../operations/installation-deployment.md#4-routine-deployment-every-release)) |

## Never

- `docker compose down -v` (deletes the database and uploads).
- Edit files inside containers or change servers by hand.
- Put secrets in tickets, chat or documents.

## Incident (P1)

Contain (maintenance page at proxy, disable accounts) → preserve evidence
([manual §9](../system-security-administration-manual.md#9-security-incident-technical-steps)) → call the
Vendor Support Lead → TMC reports to CERT-In within 6 hours.

## Targets

Availability 99.5 %/month · RPO 15 min · RTO 60 min · Critical 4 business hours · High 1 business day ·
Medium 3 business days · Security patches ≤ 30 days (critical on priority).
