#!/usr/bin/env bash
set -euo pipefail
cd /var/www/sites/guristas.net
source_file=deploy/systemd/guristas-systemd-control.py
if [[ $(id -u) -ne 0 ]]; then echo 'Run as root with sudo.' >&2; exit 1; fi
[[ -f "$source_file" ]] || { echo 'Helper source not deployed.' >&2; exit 1; }
/usr/bin/python3 -c 'import ast,sys; ast.parse(open(sys.argv[1],encoding="utf-8").read())' "$source_file"
/usr/bin/install -o root -g root -m 0755 "$source_file" /usr/local/sbin/guristas-systemd-control
sudoers_temp=$(mktemp /etc/sudoers.d/.guristas-systemd.XXXXXXXX)
trap 'rm -f "$sudoers_temp"' EXIT
printf '%s\n' 'apache ALL=(root) NOPASSWD: /usr/local/sbin/guristas-systemd-control *' > "$sudoers_temp"
chmod 0440 "$sudoers_temp"
/usr/sbin/visudo -cf "$sudoers_temp" >/dev/null
/usr/bin/install -o root -g root -m 0440 "$sudoers_temp" /etc/sudoers.d/guristas-systemd
/usr/sbin/visudo -cf /etc/sudoers.d/guristas-systemd
for name in guristas-venal-collector guristas-cache-cleanup; do
 echo "=== $name ==="
 sudo -n -u apache /usr/bin/sudo -n /usr/local/sbin/guristas-systemd-control config "$name"
 sudo -n -u apache /usr/bin/sudo -n /usr/local/sbin/guristas-systemd-control journal "$name" | tail -4
done
