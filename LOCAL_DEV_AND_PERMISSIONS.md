# Guristas.net: local SSO and permissions plan

## What works in the current package

- The homepage and Ship Explorer remain public.
- Logging in identifies one EVE character. Public corporation and alliance information appears on `/account/`.
- Theme, favorite ship and Ship Explorer column settings save for that character.
- The login requests no private permissions and does not store EVE access or refresh tokens. Enabling scopes in the CCP portal does not change that.

## Local testing before AWS

The CCP application currently registered at `https://guristas.net/auth/callback.php` is for production. Do not point your local config to that URL: it would return the browser to AWS, not XAMPP.

Use a **separate development application** in the CCP portal, with the same available scopes but a local callback. The registration form shown to us allows `http` only for `localhost`; it does **not** allow `http://guristas.test`. There are two good options:

1. Keep `guristas.test` and configure a local HTTPS virtual host and trusted local certificate. Register `https://guristas.test/auth/callback.php`. Set the exact same URL in local `config/auth.local.php`.
2. Configure a localhost XAMPP virtual host whose document root is `K:\xampp\htdocs\guristas.net\public`, on an unused port if needed. Register `http://localhost:PORT/auth/callback.php` (or `http://localhost/auth/callback.php` if using port 80). Use the exact registered URL in local `config/auth.local.php`.

Keep separate local and AWS `config/auth.local.php` files. Each has the appropriate EVE client ID, callback, and database details. Keep both out of Git. PHP sessions need to work, and the browser must start the login and return on the **same local host and scheme**.

Do not select a local URL until the local Apache virtual host is confirmed. XAMPP already has a `guristas.test` host; its Apache configuration, SSL availability and port bindings determine the simplest option.

## Permission flow for upcoming tools

1. A visitor can read public pages without an account.
2. EVE login requests no private scopes. Each EVE character remains a separate site identity unless character linking is intentionally added later.
3. A tool declares the exact scopes it needs. When the signed-in character opens it, the site first checks the **verified granted scopes** and whether a usable refresh token exists for that character.
4. If permission is missing, explain what the tool will read and why. A button starts a new CCP SSO authorization for the missing tool scopes. Never let a query parameter supply arbitrary scope names or return URLs.
5. On callback, verify state, PKCE, JWT signature, issuer, audience, character ID, expiry and the `scp` list. The character ID must match the currently signed-in character. Store the refresh token encrypted on the server, associated with that character and the scopes actually granted. Never send it to browser JavaScript.
6. Before a private API call, obtain a current access token with the corresponding refresh token. Replace a refresh token if CCP rotates it. If the user revokes access or refreshing fails, mark that grant unavailable and ask again when the feature is used.
7. A later tool may require another grant. Track grants by character and scope set; do not assume a later authorization silently includes older scopes. A previously granted scope should work without another consent prompt while its refresh token remains usable. CCP may still show its own sign-in screen or require consent again.

For initial profile features, public ESI character data supplies starting race/bloodline, corporation and alliance. Standings need `esi-characters.read_standings.v1`; personal FW stats need `esi-characters.read_fw_stats.v1`; private character killmails need `esi-killmails.read_killmails.v1`. Separately verify current militia enlistment from the appropriate current data and avoid calling a character "Guristas verified" based only on birthplace, corporation name or positive standings. Design the classification rules and their refresh interval before gating content.

When achievements are built, distinguish **EVE-provided achievements** from **site-computed achievements**. ESI killmail coverage may differ from a complete record of every participation; define exactly what the badge proves before showing it.

## Files needed for the next integration

- The current `public/index.php`, `public/ships/index.php`, `public/account/index.php`, and `app/services/EveAuth.php` after you copied the package. This avoids replacing local edits.
- `public/assets/js/themes.js` and the navigation page files (`public/venal/index.php`, other full page headers) for consistent account UI.
- The Apache virtual host for `guristas.test`, the SSL virtual host if present, and the active port configuration (`httpd-vhosts.conf`, relevant `httpd-ssl.conf` and `httpd.conf` sections). **Remove any private keys, passwords, or unrelated domains before sharing.**
- The XAMPP PHP version and whether the `curl`, `openssl`, `pdo_mysql`, and `sodium` extensions are enabled.

Never send `config/auth.local.php`, your CCP client secret, database password or TLS private key. The client ID is safe to share, but it can also be entered locally without sending it.
