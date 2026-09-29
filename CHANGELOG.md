# v0.1.11
## 09/29/2026

1. [](#improved)
    * Http::curl takes an optional per-request timeout.
    * `Accounts::withHttp($http)`: the registry over another transport, so a short-timeout check's token refreshes use it too.

# v0.1.10
## 09/29/2026

1. [](#improved)
    * The access line on consumer plugins' settings explains the scope in a few words, e.g. **with `drive.file` access (only files it creates)**, instead of a sentence.

# v0.1.9
## 09/29/2026

1. [](#improved)
    * The **Uses Google Drive account … with `drive.file` access** line on the backup and gallery settings now explains, in words, what that permission (scope) lets the plugin do.
    * Guided setup's intro says when its steps were last revised (`Setup::GUIDES_REVISED`) and that Google may have changed its console since.

# v0.1.8
## 09/29/2026

1. [](#bugfix)
    * An OAuth account granted the full `drive` scope no longer fails with `scope_not_granted` when a plugin asks for `drive.file` or `drive.readonly`: `drive` covers both. The Accounts tab, the **Start here** checklist and **Who uses what** now use the same rule (`OAuthUser::missingScopes()`).

# v0.1.7
## 09/29/2026

1. [](#improved)
    * Guided setup now pre-fills the **Add account** name and type when you follow its link to the Accounts tab.
    * The **Start here** tab now comes first, with **Guided setup** second.
    * OAuth account rows list **Connect**, **Test**, **Remove** in that order; **Test** is disabled until the account is connected.

# v0.1.6
## 09/29/2026

1. [](#improved)
    * Renamed to **Google Drive Auth**: what it gives the other Google Drive plugins is the Google sign-in (accounts and credentials).

# v0.1.5
## 09/29/2026

1. [](#bugfix)
    * The **Google Drive account** dropdown on the backup and gallery settings was empty in Admin2: the API only resolves allowlisted `data-options@` providers, so `Setup::accountOptions` is now registered with `Blueprint::addAllowedDynamicCallable`. Its labels no longer include account emails, because page editors can call that endpoint too.

# v0.1.4
## 09/29/2026

1. [](#bugfix)
    * The full OAuth and Service account guides no longer say their console links open "the project you named": only Guided setup, with a project ID entered, says so now.

# v0.1.3
## 09/29/2026

1. [](#new)
    * **Guided setup** tab, first on the settings page: a few questions (which Google account, personal or Workspace, Shared Drive, admin rights, optional Cloud project ID) and only the steps that apply, with every Cloud console link opening your project. OAuth is recommended for everyone; the service account is the alternative for Workspace with a Shared Drive. Answers stay in your browser; the email is never sent.
    * `GET /api/v1/gdrive/guide` renders it from the same `docs/setup/*.md`, whose `<!-- only: … -->` markers (invisible on GitHub) mark the path-specific parts. The full guides stay complete.
1. [](#improved)
    * The personal-account (OAuth) path comes first: Start here's table, the recommendation, the tab order (OAuth guide before Service account guide), the OAuth guide's audience step, and the Accounts tab's type picker.
    * Tables on the settings page have padded columns (Admin2's display fields had none).

# v0.1.2
## 09/29/2026

1. [](#improved)
    * Renamed to **Google Drive Library** in the plugin list, so it reads as the shared piece the other Google Drive plugins depend on.

# v0.1.1
## 09/29/2026

1. [](#bugfix)
    * Accounts tab crashed in Admin2 ("Cannot read properties of null"): Admin2 renders fields inside its own `<form>`, so the nested add-account `<form>` was dropped by the HTML parser. It is a `div` now, Enter no longer submits the settings form, and smoke checks both.

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
    * Admin2 settings page (Plugins → Google Drive) with tabs: **Start here** (which account type to use, a per-account checklist, and "who uses what"), **Accounts** (the `gdrive-accounts` field: add by upload or paste, Test, Connect/Reconnect in a popup, Remove), and full **Service account**, **OAuth** and **Troubleshooting** guides.
    * Guides live once, in `docs/setup/*.md`, and render in Admin2 with this site's redirect URI, service-account emails and needed scopes filled in. Troubleshooting has a stable anchor for every error code, and Test results link straight to it.
    * Admin2 endpoints under `/api/v1/gdrive/accounts` (list, add, remove, test, connect), all behind the new `api.gdrive.manage` permission. Errors are problem+json with the reason `code` and Troubleshooting `anchor`.
    * `Setup` blueprint helpers for dependent plugins: `accountOptions()` for an account picker, `consumerNotice($plugin)` for a "Needs … · Set up Google Drive access →" line.
    * `tests/smoke.php`: JWT, PKCE, state single-use and expiry, scope enforcement, retry and 401 logic, upload request shape, error parsing, account-name and credential validation, the settings page's rows, guide rendering, request-body validation, a Troubleshooting anchor for every reason code, version drift.
