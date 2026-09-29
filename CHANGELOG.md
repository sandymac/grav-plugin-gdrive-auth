# v0.1.0
## unreleased

1. [](#new)
    * Initial release: the shared Google Drive library for the gdrive plugin family, extracted from the gallery plugin (now `grav-plugin-gdrive-images`). No Composer dependencies.
    * Two kinds of account: service accounts (hand-signed RS256 JWT via `openssl_sign`) and OAuth user accounts through the site's own Google "Web application" client, with PKCE (S256), offline access and revoke.
    * Account registry: `plugins.gdrive.accounts.<name>.type` in config; keys, clients and refresh tokens in `user/data/gdrive/<name>.{sa,client,token}.json`, written atomically with mode 0600.
    * Public OAuth callback at `/gdrive-oauth/callback`, trusting only a single-use, 10-minute server-side state.
    * Thin Drive v3 client (`request`, `paginate`, `children`, `findByAppProperty`, `ensureFolder`, `download`, resumable single-PUT `upload`, `trash`, `about`) with Shared Drive support on every call, a 401 → refresh-once retry, and backoff on 429/5xx.
    * `DriveException` carries Google's error `reason` and a Troubleshooting anchor.
    * `onGdriveScopes` event for dependent plugins to declare the scopes they need.
    * `tests/smoke.php`: JWT, PKCE, state single-use and expiry, scope enforcement, retry and 401 logic, upload request shape, error parsing, account-name and credential validation, version drift.
