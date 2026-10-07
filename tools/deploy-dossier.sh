#!/usr/bin/env bash
set -Eeuo pipefail
trap 'status=$?; printf "Deployment failed at line %s (exit %s).\n" "$LINENO" "$status" >&2; exit "$status"' ERR
archive=${1:?Archive required}
commit=${2:?Commit required}
[[ "$archive" =~ ^/tmp/guristas-dossier-[a-f0-9]+\.tar$ && "$commit" =~ ^[a-f0-9]{40,64}$ ]] || { printf "Deployment validation failed at line %s.\n" "$LINENO" >&2; exit 1; }
site=/var/www/sites/guristas.net
[[ -d "$site/app" && -d "$site/public" && ! -L "$site/storage" ]] || { echo 'Expected production layout not found.' >&2; exit 1; }
work=$(mktemp -d /tmp/guristas-dossier-stage-XXXXXX)
chmod 700 "$work"
cleanup() { rm -rf -- "$work"; rm -f -- "$archive" "${archive%.tar}.sh"; }
trap cleanup EXIT
mkdir "$work/source" "$work/before"
tar -xf "$archive" --no-same-owner -C "$work/source"
manifest="$work/source/docs/dossier-package-files.txt"
[[ -f "$manifest" ]] || { echo 'Deployment manifest missing.' >&2; exit 1; }
backup_dir=/home/ec2-user/site-backups
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
exec 9>"$backup_dir/.guristas.net-backup.lock"
flock -n 9 || { echo 'Another deployment/backup is running.' >&2; exit 1; }
mapfile -t files < "$manifest"
# Normalize manifest lines from Windows Git archives.
for i in "${!files[@]}"; do files[$i]=${files[$i]%$'\r'}; done
for file in "${files[@]}"; do
    [[ "$file" =~ ^(app|public|tests|docs|tools)/[a-zA-Z0-9_./-]+$ || "$file" == config/esi-scopes.php ]] || { printf "Deployment validation failed at line %s.\n" "$LINENO" >&2; exit 1; }
    [[ "$file" != *..* && -f "$work/source/$file" && ! -L "$work/source/$file" ]] || { printf "Deployment validation failed at line %s.\n" "$LINENO" >&2; exit 1; }
    parent="$site/$file"
    while [[ "$parent" != "$site" ]]; do
        [[ ! -L "$parent" ]] || { echo "Symlink blocks deployment: $parent" >&2; exit 1; }
        parent=$(dirname "$parent")
    done
    if [[ "$file" == *.php ]]; then php -l "$work/source/$file" >/dev/null; fi
    if [[ -f "$site/$file" ]]; then
        mkdir -p "$work/before/$(dirname "$file")"
        sudo -n cp -p "$site/$file" "$work/before/$file"
    fi
done
# Back up the live site, including persistent pilot records, before any application writes.
backup="$backup_dir/guristas.net-dossier-$(date -u +%Y%m%d-%H%M%S)-${commit:0:12}.tar.gz"
umask 077
sudo -n tar -czf - --exclude=guristas.net/storage/cache --exclude=guristas.net/storage/logs --exclude=guristas.net/storage/sessions -C /var/www/sites guristas.net > "$backup.partial"
tar -tzf "$backup.partial" >/dev/null
mv "$backup.partial" "$backup"
echo "Verified backup: $backup"
rollback() {
    echo 'Deployment failed; restoring the previous application files.' >&2
    for file in "${files[@]}"; do
        if [[ -f "$work/before/$file" ]]; then sudo -n cp -p "$work/before/$file" "$site/$file"; else sudo -n rm -f -- "$site/$file"; fi
    done
    echo 'Persistent pilot records were not rolled back.' >&2
}
trap 'rollback; exit 1' ERR
for file in "${files[@]}"; do
    if [[ ! -d "$site/$(dirname "$file")" ]]; then sudo -n install -d -o ec2-user -g apache -m 0755 "$site/$(dirname "$file")"; fi
    # A rename replaces each complete file; readers never see a partially copied PHP file.
    sudo -n install -o ec2-user -g apache -m 0644 "$work/source/$file" "$site/$file.dossier-new"
    sudo -n mv -f "$site/$file.dossier-new" "$site/$file"
done
sudo -n mkdir -p "$site/storage/pilot-record"
sudo -n chown apache:apache "$site/storage/pilot-record"
sudo -n chmod 2750 "$site/storage/pilot-record"
# Ensure Apache can traverse the new application directories; leave runtime data alone.
for path in app/data tools docs; do sudo -n chmod 0755 "$site/$path"; done
curl --fail --silent --show-error --max-time 30 -o /dev/null https://guristas.net/
trap - ERR
echo "Application patch deployed: $commit"
echo 'Existing pilot records, uploads and server-local secrets are preserved.'
