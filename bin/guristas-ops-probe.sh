#!/usr/bin/env bash
set -euo pipefail
cd /var/www/sites/guristas.net
if [ "$(id -un)" != "apache" ]; then echo 'Run with: sudo -u apache bash bin/guristas-ops-probe.sh'; exit 2; fi
TOKEN=$(/usr/bin/php -r 'echo bin2hex(random_bytes(12));')
TARGET="storage/operations/probe-$TOKEN.json"
LOG='storage/operations/worker-launch.log'
# Use the same detached shell strategy as the managed dispatcher, without invoking a real data job.
/bin/sh -c "nohup /usr/bin/php bin/guristas-ops.php probe '$TOKEN' </dev/null >>'$LOG' 2>&1 & echo \$!" >/dev/null
for i in 1 2 3 4 5 6 7 8 9 10; do
 if [ -f "$TARGET" ]; then
   /usr/bin/php -r '$v=json_decode(file_get_contents($argv[1]),true);if(!isset($v["pid"],$v["time"]))exit(1);echo "PASS: detached Apache worker launched and wrote probe (PID ".$v["pid"].")\n";' "$TARGET"
   rm -f -- "$TARGET"
   exit 0
 fi
 sleep 1
done
echo 'FAIL: detached worker did not report. Examine storage/operations/worker-launch.log and cron/SELinux restrictions.' >&2
exit 1
