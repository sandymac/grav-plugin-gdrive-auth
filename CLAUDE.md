# grav-plugin-gdrive

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
- **Pattern library:** `C:\dev\grav-plugin-gdrive-images` (the gallery this
  was extracted from) and `C:\dev\grav-plugin-mcp-server`: spl autoload
  fallback, bare-PHP `tests/smoke.php` with `check()`, PHPStan config, and a
  `VERSION` constant that smoke asserts matches `blueprints.yaml`.
- Leave one runnable check behind for non-trivial logic. Bare PHP, fake
  transports, no network.

## Trust boundaries (never simplified away)

- **Credential files** live in `user/data/gdrive/` under fixed names
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

## Tooling

No local PHP. Run it via Docker; from Git Bash on Windows:

```
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" php:8.3-cli php /app/tests/smoke.php
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app php:8.3-cli php .gravtest/phpstan.phar analyse --memory-limit=1G
```

Without `MSYS_NO_PATHCONV=1` MSYS rewrites the `-v` colon, docker mounts
nothing, and a stray `<dir>;C` directory appears next to the repo.

On a deployed site PHP class changes take effect immediately; **YAML config
changes need `bin/grav clearcache`** (`clearcache`, not `clear-cache`).

Machine- and deployment-specific notes (hosts, ssh aliases) go in
`CLAUDE.local.md`, which is gitignored.

## Agent skills

### Issue tracker

GitHub Issues on `sandymac/grav-plugin-gdrive`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.
