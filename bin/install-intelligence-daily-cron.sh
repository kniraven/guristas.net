#!/usr/bin/env bash
# Run once as root (sudo). Preserves all existing apache crontab entries.
set -euo pipefail
if [[ "$(id -u)" -ne 0 ]]; then echo 'Run with sudo.' >&2; exit 1; fi
ROOT='/var/www/sites/guristas.net'
for file in "$ROOT/bin/guristas-sde-sync.py" "$ROOT/bin/guristas-intelligence.php"; do
    [[ -f "$file" ]] || { echo "Missing $file" >&2; exit 1; }
done
[[ -x /usr/bin/python3 ]] || { echo 'Python 3 missing from /usr/bin/python3' >&2; exit 1; }
[[ -x /usr/bin/php ]] || { echo 'PHP missing from /usr/bin/php' >&2; exit 1; }
old="$(crontab -u apache -l 2>/dev/null || true)"
# Only replace our explicitly marked tasks, never unrelated cron entries.
clean="$(printf '%s\n' "$old" | grep -v '# GURISTAS_DAILY_SDE$' | grep -v '# GURISTAS_DAILY_LP$' || true)"
{
  printf '%s\n' "$clean"
  printf '%s\n' '25 9 * * * cd /var/www/sites/guristas.net && /usr/bin/python3 bin/guristas-sde-sync.py >/dev/null 2>&1 # GURISTAS_DAILY_SDE'
  printf '%s\n' '10 9 * * * cd /var/www/sites/guristas.net && /usr/bin/php bin/guristas-intelligence.php offers >/dev/null 2>&1 # GURISTAS_DAILY_LP'
} | crontab -u apache -
echo 'Configured two daily jobs in apache crontab (09:10 LP and 09:25 SDE UTC). Other entries preserved.'
crontab -u apache -l | grep GURISTAS_DAILY
