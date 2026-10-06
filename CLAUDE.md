# grav-plugin-gdrive-auth

A Grav CMS plugin (PHP 8.3+, Grav 2.0.23+): the shared Google Drive library
for the gdrive family. Google auth (service accounts and bring-your-own OAuth
Web clients), a thin Drive v3 client, the account registry and the public
OAuth callback. Dependent plugins (`grav-plugin-gdrive-images`,
`grav-plugin-gdrive-backup`) call `Gdrive::drive()`.

The design lives in `PLAN.md` (§3 is this repo). Read it before changing
behaviour; the decisions in §1 are settled.

## Conventions

- **Thin and lazy.** No Composer dependencies: curl and `openssl_sign` only.
  One class per concern in `classes/`, final classes, `declare(strict_types=1)`.
  Terse docblocks that explain *why*.
- **Raw Drive arrays.** No resource objects and no caching in `Drive`; callers
  pick `fields` and cache their own way.
- **One transport seam.** Everything takes
  `callable(string $method, string $url, array $opts): array{int, string, array}`.
  Tests pass fakes; `Gdrive::setHttp()` does it for dependent plugins.
- **Public API is what the README lists.** Everything else is internal; keep
  the listed names and signatures stable (semver).
- **Pattern library:** the sibling repos `grav-plugin-gdrive-images` (the
  gallery this was extracted from) and `grav-plugin-mcp-server`: spl autoload
  fallback, bare-PHP `tests/smoke.php` with `check()`, PHPStan config, and a
  `VERSION` constant that smoke asserts matches `blueprints.yaml`.
- Leave one runnable check behind for non-trivial logic. Bare PHP, fake
  transports, no network.

## Trust boundaries (never simplified away)

- **Credential files** live in `user/data/gdrive/auth/` under fixed names
  (`<name>.sa.json`, `<name>.client.json`, `<name>.token.json`), written
  atomically with mode 0600. Never in config, never in the repo, never logged.
- **Account names become filenames**: every entry point validates
  `/^[a-z0-9][a-z0-9_-]{0,31}$/`.
- **Uploaded credentials are shape-checked** at `saveCredential()`, and the
  error says which kind was expected without echoing the file.
- **Secrets are never returned**: `status()` and `test()` expose type, email,
  scopes and reasons only.
- **OAuth state is single-use**: 32 random bytes, stored by its sha256 in
  `oauth-state/` for 10 minutes, deleted before use. The callback carries no
  admin login (Admin2 uses an in-memory bearer token), so the state is the only
  thing it trusts. Failures get a generic 400; the reason goes to the log.
- PKCE (S256) on every Connect; `postMessage` only to the site's own origin.
- **The endpoints are a trust boundary too**: `Setup::accountBody()` checks the
  body's shape and caps `json` at 64 KB before `saveCredential()` sees it;
  route names are validated before any file path is built.

## Admin2 contract (api 1.0.41, admin2 2.1.24; what bites when maintaining)

- **Routes:** `onApiRegisterRoutes` is subscribed unconditionally (never behind
  `isAdmin()`, which is false on API requests) and fires only when the api
  plugin rebuilds its route cache (keyed on enabled plugins and each
  `blueprints.yaml` mtime). After changing routes, touch `blueprints.yaml` or
  `bin/grav clearcache`. Handlers must be `[Api::class, 'method']`, not
  closures. Everything stays under `/gdrive`: a clashing route breaks the
  whole API.
- **Controller:** `classes/Api.php` extends the api plugin's
  `AbstractApiController`, so it's the only file that needs the api plugin;
  nothing else may reference api classes. Success is `ApiResponse::create()`
  (`{data}`); `DriveException` becomes problem+json with `code` + `anchor`
  (built by hand, since `ErrorResponse` has no extra fields). PHPStan scans
  `.gravtest/grav-admin/user/plugins/api/classes` (CI clones api 1.0.41 there).
- **Permission:** `api.gdrive.manage`. "Super" means `api.super`, not
  `admin.super`. Core doesn't load plugin `permissions.yaml`; the
  `PermissionsRegisterEvent` handler does. Demo accounts are blocked from every
  route (the permission doesn't end in `.read`).
- **Config writes:** the endpoints write `accounts` to base
  `user/config/plugins/gdrive-auth.yaml` with `YamlFile`. Admin2's settings-form
  save posts the whole config it loaded, so `onAdminSave` resets `accounts` to
  the on-disk value; otherwise a stale page revives removed accounts.
- **Slug rename (0.1.13):** slug `gdrive-auth`, class `GdriveAuthPlugin`; the
  namespace `Grav\Plugin\Gdrive`, `user/data/gdrive/` (its `auth/` subdir since 0.1.15), `/gdrive` routes,
  `api.gdrive.manage` and `/gdrive-oauth/callback` deliberately kept the old
  name. `Gdrive::withLegacyAccounts()` is the read-only fallback to a legacy
  `gdrive.yaml`; drop it once sites have migrated.
- **Blueprint:** `data-content@: ['\Class::method', 'arg']` works (file-loaded
  blueprints are trusted). Admin2 **hides a display field** whose content is
  empty or contains `<script` or `<div id=`; `Setup::safe()` guards that.
  Blueprint keys outside the serializer's whitelist never reach a custom
  field, so the component gets nothing from its blueprint entry.
- **Tabs follow the URL hash:** `#<tab key>` (lowercase, `--` separates
  levels). `Setup::render()` rewrites `oauth.md` → `#oauth` and
  `troubleshooting.md#x` → `#troubleshooting--x`; the component switches tabs
  the same way, then scrolls to `<a id="x">`. Renaming a tab key or an anchor
  breaks those links (smoke checks the anchors).
- **Revising the guides:** after checking `docs/setup/*.md` against Google's
  console, bump `Setup::GUIDES_REVISED`; Guided setup shows it with a
  "Google may have changed this since" disclaimer.
- **Guide markers:** `docs/setup/*.md` mark path-specific parts with
  `<!-- only: tag[,tag…] -->` … `<!-- /only -->`, each on its own line
  (indent inside list items). A block is kept if any entry is active; an entry
  is `tag`, `not-tag` (active when tag isn't) or `a+b` (all). No nesting. Tags:
  `gmail`, `workspace`, `oauth`, `sa`, `shared-drive`, `admin`, `not-admin`,
  `project`, and `backup`/`gallery` (from declared scopes; both if none).
  `guide()` strips the markers (full guides keep everything); the Guided setup
  (`Setup::guided()`, `GET /gdrive/guide`) filters with them. Keep blank lines
  around blocks so the unfiltered text still reads.
- **Display-field tables:** Admin2's display field has Tailwind preflight and
  no table CSS, so `guide()`/`whoUsesWhat()` turn pipe tables into HTML with
  inline cell padding (`Setup::tables()`; DOMPurify keeps `style`).
- **Custom fields** (`admin-next/fields/gdrive-*.js`): evaluated as an
  ES module from a blob, so no imports; tag from `window.__GRAV_FIELD_TAG`.
  Read `window.__GRAV_API_TOKEN` at call time (it's refreshed), send it as
  `X-API-Token` plus the environment headers. It must never dispatch `change`
  (display only); the blueprint also sets `validate: {ignore: true}`. Don't
  name the field `accounts`: `validate.ignore` would strip that config key on
  every form save.

## Tooling

`php tests/smoke.php`, `phpstan analyse --memory-limit=1G` (needs the
`.gravtest/grav-admin` layout that `.github/workflows/ci.yml` builds) and
`node --check admin-next/fields/*.js`. Without a local PHP, Docker works; from
Git Bash on Windows:

```
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" php:8.3-cli php /app/tests/smoke.php
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app php:8.3-cli php .gravtest/phpstan.phar analyse --memory-limit=1G
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app node:22 node --check admin-next/fields/gdrive-accounts.js
```

Without `MSYS_NO_PATHCONV=1` MSYS rewrites the `-v` colon, docker mounts
nothing, and a stray `<dir>;C` directory appears next to the repo.

On a deployed site PHP class changes take effect immediately; **YAML config
changes need `bin/grav clearcache`** (`clearcache`, not `clear-cache`).

Machine- and deployment-specific notes (hosts, ssh aliases) go in
`CLAUDE.local.md`, which is gitignored.

## Agent skills

### Issue tracker

GitHub Issues on `sandymac/grav-plugin-gdrive-auth`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.
