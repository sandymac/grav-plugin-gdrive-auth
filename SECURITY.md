# Security

This plugin holds Google credentials for a Grav site: service-account keys,
OAuth client secrets and refresh tokens. If you find a way to read, replace or
misuse them, please report it privately rather than in a public issue.

- Use **Report a vulnerability** under this repository's Security tab (GitHub's
  private advisory), or email the author listed in `blueprints.yaml`.
- Say which version (`blueprints.yaml` `version:`) and, if it applies, which
  web server and hosting setup.

What the plugin promises, so you know what counts as a bug:

- Credentials live only in `user/data/gdrive/auth/`, written atomically with
  mode 0600. They never go into config files, logs or API responses; the
  settings page and the endpoints expose type, email, scopes and error codes.
- Account names are validated (`/^[a-z0-9][a-z0-9_-]{0,31}$/`) before any file
  path is built, and uploaded credentials are shape-checked before storage.
- Sign-in and token requests go only to Google's own endpoints, whatever an
  uploaded JSON says.
- The OAuth callback trusts a single-use, 10-minute, server-side state (32
  random bytes, stored by its SHA-256) plus PKCE S256; every failure is a
  generic 400.
- Every Admin2 endpoint requires the `api.gdrive.manage` permission.

Keep your web server refusing `user/data/` (Grav's shipped Apache, nginx,
Caddy, lighttpd and IIS configs do), and treat Grav backups that include
`user/data/gdrive/auth/` like passwords.
