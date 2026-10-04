# Guristas.net

PHP website with EVE login, character preferences, ship exploration, Venal intelligence, stream overlays, and staff tickets.

## Project layout

| Path | Responsibility |
| --- | --- |
| `public/` | Apache document root: pages, API endpoints, public assets |
| `app/services/` | Authentication, ESI clients, business logic, file storage |
| `app/views/partials/` | Reusable navigation, login modal, editor markup |
| `config/` | Shared configuration, private environment configuration, examples |
| `config/stream-defaults/` | Versioned initial stream content for new installations |
| `scripts/` | CLI setup, data collection, data generation, verification |
| `sql/` | Versioned schema migrations |
| `storage/` | Private runtime files; preserve during deployments |

## Environments

| Setting | Windows development | EC2 production |
| --- | --- | --- |
| Site root | `K:\xampp\htdocs\guristas.net` | `/var/www/sites/guristas.net` |
| Apache document root | Site root plus `public` | Site root plus `public` |
| Login origin | `http://localhost:8085` | `https://guristas.net` |
| EVE callback | `http://localhost:8085/auth/callback.php` | `https://guristas.net/auth/callback.php` |
| EVE application | Separate development application | Production application |
| MySQL | Shared EC2 database, using the development DB account | EC2 database, using the production DB account |
| Token environment | `local` | `production` (also the default) |
| Attachment storage | Transfer to EC2 through the storage bridge | Native private EC2 attachment directory |

`guristas.test` is another local virtual host; use `localhost:8085` for the configured local login flow. Start login and receive its callback on the same origin.

Both sites currently share ticket, character, staff, and preference records. Local writes change production data. Stream JSON and caches remain specific to each machine.

## PHP and database setup

The current local runtime is PHP 8.0.30; the deployed EC2 runtime is PHP 8.4.18. Keep changes executable on both until the development runtime is deliberately upgraded.

Enable cURL, OpenSSL, Sodium, PDO MySQL, DOM, Fileinfo, and ZipArchive, and ensure PHP sessions work. On Windows, if Sodium loads in CLI but fails in Apache, inspect the Apache startup log; the current installation needed `libsodium.dll` from `K:\xampp\php` copied into `K:\xampp\apache\bin`. Restart Apache after PHP extension changes.

For a fresh database, apply the files in `sql/` in numeric order. These migrations are retained for reproducible installation; do not rerun data migrations indiscriminately against an existing database. For the shared-database local login setup, create the separate token table once:

```sql
CREATE TABLE IF NOT EXISTS eve_character_tokens_local LIKE eve_character_tokens;
```

Use database accounts with the permissions needed for the site (SELECT, INSERT, UPDATE, DELETE). The current local DSN connects directly to EC2, with the AWS inbound rule restricted to the developer's public IP. Update that rule if the public IP changes.

## Private configuration

Copy the appropriate `.example` files and fill in the actual values. Private files live outside `public/`, stay out of Git, and are managed separately per environment.

| File | Values |
| --- | --- |
| `config/auth.local.php` | EVE client ID, callback, DB DSN/credentials, token encryption key, token environment |
| `config/tickets.local.php` | Owner character ID; the owner manually grants staff access |
| `config/ticket-storage.local.php` | Shared attachment bridge secret; `remote_url` on Windows only |
| `config/stream.local.php` | Stream administration key and loopback-access setting |

Register each EVE callback exactly as configured. Authentication uses Authorization Code with PKCE and validates the returned JWT. Requested permissions are defined in `config/esi-scopes.php`; currently these cover standings, character FW statistics, and private killmails. Access and refresh tokens are encrypted in MySQL.

Generate each token encryption key once using `base64_encode(random_bytes(32))`. Preserve the key while tokens encrypted with it remain in use. Development uses `eve_character_tokens_local`; production uses `eve_character_tokens`. Use `'token_environment' => 'local'` in the Windows auth config and `'token_environment' => 'production'` in the EC2 config. Do not deploy the Windows auth config to EC2.

The attachment bridge uses the same independently generated 64-character hexadecimal secret on both machines. The local storage config also contains:

```php
'remote_url' => 'https://guristas.net/admin/tickets/storage.php',
```

The EC2 storage config must omit `remote_url`. HTTPS certificate verification remains enabled for EVE and attachment transfers. If Windows cURL needs a CA bundle, configure `curl.cainfo` with a trusted bundle.

Stream administration has its own authorization mechanism: a configured administration key, with an optional loopback exception. It currently does not use ticket staff membership.

## Stream installation

Runtime stream files are editable data, not deployable source. Versioned defaults contain the starting message types, messages, and settings. Initialize missing files explicitly:

Windows PowerShell, from the site root:

```powershell
& 'K:\xampp\php\php.exe' scripts/initialize-stream-storage.php
```

EC2, with the source installed and the runtime directory writable by Apache:

```bash
sudo -u apache php /var/www/sites/guristas.net/scripts/initialize-stream-storage.php
```

The script preserves existing valid files, creates only missing files, and fails on malformed existing JSON. Updating defaults does not replace saved content. Back up `storage/stream/` with other runtime data.

## Deployment

Work on a branch named after the ticket, such as `GURI-034`. Prefix every commit with the issue, such as `GURI-034: Consolidate setup documentation`.

1. Verify the branch locally and review the complete changes, including removed files.
2. Back up the shared database and EC2 runtime data before applying migrations or moving tracked runtime files.
3. Apply required migrations in the order specified by each update. GURI-034 requires `sql/007_ticket_summary_html.sql` before deploying the HTML-only ticket code. The shared EC2 database was converted and verified on 2026-10-03; do not repeat it for the local environment using that same database.
4. Deploy source and intentional deletions together. Preserve environment configuration, attachments, stream data, and history. Initialize missing stream files and check Apache ownership/permissions on runtime directories.
5. Reload PHP-FPM and verify the affected features in production.

**GURI-034 transition:** Git records the old `storage/stream/*.json` files as moving into `config/stream-defaults/`. A checkout/pull can remove the originals. Before that transition on EC2, preserve its current three stream JSON files outside the checkout and restore them to `storage/stream/` afterward. Preserve `config/EveAppInfo.txt` separately too if needed; it is being removed from tracking. Do not restore obsolete source backups into the application tree.

Database backups do not include uploaded files, stream configuration, or historical snapshots. Back up these directories separately. Existing Windows-only attachments are not migrated by the EC2 storage bridge.

## Verification and logs

Lint changed PHP files on both runtimes. Existing CLI checks:

```powershell
& 'K:\xampp\php\php.exe' scripts/check-ticket-update.php
& 'K:\xampp\php\php.exe' scripts/check-ticket-storage.php
```

The first checks rich-text sanitization and required ticket extensions; the second checks attachment request signing. Neither replaces an end-to-end upload/download test.

Check affected routes and API responses, login/logout, preferences, all four themes, ship filters/layout, maps, stream overlays, and ticket permissions/editor/comments/history/subtasks/attachments as appropriate to the change. Check browser console/network errors and server logs.

- Local login virtual host: `K:\xampp\apache\logs\guristas-local-error.log`
- Local Apache startup: `K:\xampp\apache\logs\error.log`
- EC2 Guristas Apache: `/var/log/httpd/guristas_ssl_error.log`
- EC2 PHP-FPM: `/var/log/php-fpm/www-error.log`

`storage/cache/` is generated data; `storage/history/venal/` contains collected historical snapshots. Treat their retention separately. `scripts/collect_venal_activity.php` collects history; `scripts/update_ship_data.py` generates public ship data.

## Scheduled maintenance

Venal activity runs hourly on EC2 using the units in `deploy/systemd/`. The collector runs as `apache`, preserves existing hourly snapshots, and retains 720 hours (30 days). Missed runs trigger collection when the timer resumes but cannot reconstruct missing historical hours.

Install or update from the EC2 site root:

    sudo install -m 0644 deploy/systemd/guristas-venal-collector.service /etc/systemd/system/
    sudo install -m 0644 deploy/systemd/guristas-venal-collector.timer /etc/systemd/system/
    sudo systemctl daemon-reload
    sudo systemctl enable --now guristas-venal-collector.timer
    sudo systemctl restart guristas-venal-collector.timer
    sudo systemctl start guristas-venal-collector.service
    sudo systemctl list-timers guristas-venal-collector.timer --no-pager
    sudo journalctl -u guristas-venal-collector.service -n 20 --no-pager

Apache needs write access to the site's cache and history directories.

Apache and PHP-FPM logs rotate weekly with four archives, verified on 2026-10-03. Compression is disabled. Inactive services may leave older archives; review these separately before deleting them.

Ship and theme artwork without current references is intentionally retained for future use.

### Daily cache cleanup

Preview: `php scripts/cleanup-cache.php`
Apply: `php scripts/cleanup-cache.php --apply`

Cleanup examines recognized cache JSON files directly inside `storage/cache/esi`, `derived`, and `frontlines`. Entries remain for at least 24 hours after expiry, or longer if the configured stale-error window exceeds 24 hours. Unknown files, links, and entries without valid expiry information are skipped. History, attachments, sessions, and stream settings are outside its scope.

After deploying, install and enable the daily timer from the EC2 site root:

    sudo install -m 0644 deploy/systemd/guristas-cache-cleanup.service /etc/systemd/system/
    sudo install -m 0644 deploy/systemd/guristas-cache-cleanup.timer /etc/systemd/system/
    sudo systemctl daemon-reload
    sudo systemctl enable --now guristas-cache-cleanup.timer
    sudo systemctl list-timers guristas-cache-cleanup.timer --no-pager

Check results with:

    sudo journalctl -u guristas-cache-cleanup.service -n 20 --no-pager

### Guristas log review

Verified on 2026-10-03: the HTTPS virtual host uses `guristas_ssl_error.log` and `guristas_ssl_access.log`. Apache's main configuration also references `guristas_error.log` and `guristas_access.log`; these remain configured and must not be treated as obsolete.

Existing logrotate settings rotate nonempty logs weekly and retain four archives. The daily logrotate timer is enabled. Empty logs can retain older archives because `notifempty` skips rotation. No log deletion or shared Apache configuration changes were needed for GURI-036.
