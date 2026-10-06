# v1.0.1
## 2026-10-06

1. [](#bugfix)
    * Account names, OAuth states and Google Cloud project IDs are matched with PCRE's `D` modifier, so a value with a trailing newline is refused instead of slipping past `$` (noted in the GPM review, [getgrav/grav#4348](https://github.com/getgrav/grav/issues/4348)).
1. [](#improved)
    * The Accounts tab's Remove confirmation uses Admin2's dialog only; the `window.confirm` fallback is gone now that admin2 2.1.24+ is a declared dependency.
    * The README and the OAuth guide say that the redirect URI follows `system.custom_base_url` when set and the current request otherwise, so sites behind a proxy or CDN know to set it.

# v1.0.0
## 2026-10-06

1. [](#new)
    * Initial public release: Google sign-in for the Grav 2 Google Drive plugin family. Connect a Google account once and every plugin built on it ([gdrive-images](https://github.com/sandymac/grav-plugin-gdrive-images), [gdrive-backup](https://github.com/sandymac/grav-plugin-gdrive-backup)) uses it. No Composer dependencies: curl and `openssl_sign` only.
    * Two kinds of account: service accounts (hand-signed RS256 JWT) and OAuth user accounts through the site's own Google "Web application" client, with PKCE S256, offline access, `include_granted_scopes` and revoke. Sign-in and token requests go only to Google's own endpoints, whatever an uploaded JSON says.
    * Account registry: `plugins.gdrive-auth.accounts.<name>.type` in config; keys, clients and refresh tokens in `user/data/gdrive/auth/<name>.{sa,client,token}.json`, written atomically with mode 0600 and never returned by any endpoint. Account names are validated before any file path is built.
    * Public OAuth callback at `/gdrive-oauth/callback`, trusting only a single-use, 10-minute, server-side state; every failure is a generic 400 with the reason in the log.
    * Thin Drive v3 client (`request`, `paginate`, `children`, `findByAppProperty`, `ensureFolder`, `download`, resumable single-PUT `upload`, `trash`, `about`) with Shared Drive support on every call, a 401 → refresh-once retry and backoff on 429/5xx. `DriveException` carries Google's error `reason` and a Troubleshooting anchor.
    * `onGdriveScopes` event for dependent plugins to declare the account and scopes they need. `Gdrive::drive()` refuses a scope an OAuth account hasn't granted (`scope_not_granted`); a granted `drive` covers `drive.file` and `drive.readonly`.
    * Admin2 settings page (Plugins → Google Drive Auth): **Start here** (which kind of account, a per-account checklist, who uses what), **Guided setup** (a few questions, then only the steps that apply, with every Cloud console link opening your project), **Accounts** (add by upload or paste, Test, Connect/Reconnect in a popup, Remove), and the full **OAuth**, **Service account** and **Troubleshooting** guides with this site's redirect URI, service-account emails and needed scopes filled in. The guides live once, in `docs/setup/*.md`.
    * Admin2 endpoints under `/api/v1/gdrive` (list, add, remove, test, connect, guide), all behind the `api.gdrive.manage` permission; errors are problem+json with the reason `code` and the Troubleshooting `anchor`.
    * `Setup` blueprint helpers for dependent plugins: `accountOptions()` for an account picker and `consumerNotice()` for a "Uses Google Drive account … · Set up Google Drive access →" line.
    * `tests/smoke.php` (JWT, PKCE, state single-use and expiry, scope enforcement, retry and 401 logic, upload request shape, pinned endpoints, error parsing, name and credential validation, the settings page's rows and guides, a Troubleshooting anchor for every reason code, version drift), PHPStan level 6 (against Grav 2.0.23 and the current 2.2 release) and yamllint, all in CI.
