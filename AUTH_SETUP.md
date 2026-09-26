# Guristas.net EVE login — setup and integration

This package adds EVE SSO login to the **homepage and Ship Explorer**, plus `/account/` for saved preferences. A common account navigation partial and login modal are included for the site's other standalone pages. It does not contain your EVE application ID, database password, or a local configuration file.

## Setup order

1. Back up your current site and compare the packaged `public/index.php` with your latest homepage. This copy is based on the homepage you shared earlier; do not overwrite newer homepage work. Copy the other files to the same paths beneath your project root.
2. Create a MySQL database (for example `guristas_net`) and a database user with SELECT, INSERT and UPDATE access to that database. Import `sql/001_eve_accounts.sql` using phpMyAdmin or the MySQL command line. The SQL creates only two tables.
3. Copy `config/auth.local.php.example` to `config/auth.local.php` and enter the database DSN, database username and password, EVE client ID, and exact callback URL. Keep that file outside `public/` and append `/config/auth.local.php` to your existing `.gitignore`. The site starts in guest mode without this file, but sign-in will display a configuration error until it is supplied.
4. Register an application at [EVE Developers](https://developers.eveonline.com/) with the callback `https://guristas.net/auth/callback.php` (or the exact HTTPS URL you configured). This implementation uses Authorization Code with PKCE and requests **no private ESI scopes**. Choose the application type that supports PKCE. Enter its client ID in `config/auth.local.php`. The callback must match the developer portal registration byte for byte.
5. Verify that PHP 8.3 has cURL, OpenSSL, PDO and PDO MySQL enabled. PHP sessions must be enabled. Use HTTPS in production. Test the sign-in on the exact registered origin. Local `http://guristas.test` cannot use the production callback unless you register and configure a separate callback that CCP accepts.
6. Open `/` or `/ships/`, select **Log in**, complete the CCP sign-in, then check `/account/` and save a theme. Reload `/ships/`, rearrange a column, and sign in on another browser to check that the layout follows the character.

The login modal intentionally contains no EVE password form. CCP authenticates the player and returns a signed token; the server validates its signature against CCP's JWKS and checks its issuer, audience, expiry and character subject. The site saves the character ID, public ESI profile details and preferences in MySQL. Access and refresh tokens are not stored. Each EVE character is a separate account; linking multiple characters to one account is not included.

The current site duplicates its header on many routes. Login was integrated into the two page files we have (`public/index.php` and `public/ships/index.php`). To add it throughout the site, send the latest page files that render the navigation: `public/venal/index.php`, `public/zarzakh/overlay/index.php`, and any other routes where people browse a full site header. We also need `public/assets/js/themes.js` to confirm that its browser-side theme preference agrees with the new saved account theme. The shared `app/views/partials/account-nav.php` and `app/views/partials/login-modal.php` can be included by those pages.

## Existing files that might be removed

- `scripts/Remove-OldBackups.ps1` was a one-time tool. Once the four named backups are gone, it is no longer needed in the project.
- The old `*.backup-20260724-*` files from the earlier listing are absent from your latest folder listing, so do not run the cleanup command again.
- `storage/cache/**` is generated ESI/cache data and can usually be regenerated, but the services' cache behavior and retention have not been reviewed. Do not bulk-delete it as part of this login change.
- `storage/history/venal/**` contains historical snapshots and `scripts/collect_venal_activity.php` appears to create them; keep them.
- `.gitkeep` files keep intentionally empty directories in Git; keep them unless you change that directory structure.

No other removal is supported by the file listing alone. The contents of files matter for determining whether an asset or route is unused.
