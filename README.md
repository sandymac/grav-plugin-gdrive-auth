# Google Drive Library for Grav

The shared Google Drive library for Grav 2 plugins. It manages Google
accounts (service accounts, and OAuth user accounts through your own Google
Cloud "Web application" client), gives other plugins a thin Drive v3 client,
and handles the OAuth callback. Its settings page in Admin2 (Plugins → Google
Drive) manages the accounts and carries the setup guides. Its only front-end
route is that callback, and it has no Composer dependencies.

Plugins that use it: [gdrive-images](https://github.com/sandymac/grav-plugin-gdrive-images)
(photo galleries) and [gdrive-backup](https://github.com/sandymac/grav-plugin-gdrive-backup)
(backups to Drive).

Requires PHP 8.3+ and Grav 2.0.23+.

## Setup

Open **Plugins → Google Drive Library** in Admin2. The page has the guides as tabs,
with this site's redirect URI, service-account emails and needed scopes filled
in, and an **Accounts** tab to upload credentials, **Test** and **Connect**.
The same guides, readable here:

1. [Start here](docs/setup/start-here.md): service account or OAuth?
2. [Service account guide](docs/setup/service-account.md)
3. [OAuth guide](docs/setup/oauth.md)
4. [Troubleshooting](docs/setup/troubleshooting.md), one entry per error code

Managing accounts needs the **Manage Google Drive accounts** permission
(`api.gdrive.manage`); API super users have it. The settings page needs the
[api](https://github.com/getgrav/grav-plugin-api) plugin (Admin2 uses it
anyway); the library itself doesn't.

Accounts are declared in `user/config/plugins/gdrive.yaml`:

```yaml
accounts:
  site:     { type: service_account }
  personal: { type: oauth }
```

Their credentials live in `user/data/gdrive/` under fixed names, mode 0600:
`<name>.sa.json` (service-account key), `<name>.client.json` (OAuth client),
`<name>.token.json` (refresh token, granted scopes, email), plus
`<name>.test.json` (the last Test result, no secrets). Secrets never go
in config. Make sure your web server refuses `user/data/`.

## Public API

Everything below is stable under semver. Anything not listed is internal.
Dependent plugins declare `{ name: gdrive, version: '>=0.1.0' }` and should
check `class_exists(\Grav\Plugin\Gdrive\Drive::class)` before use.

### `Grav\Plugin\Gdrive\Gdrive`

```php
Gdrive::drive(string $account, array $scopes): Drive
Gdrive::accounts(): Accounts
Gdrive::scopes(): array            // list of {plugin, account, scopes} from onGdriveScopes
Gdrive::redirectUri(): string      // rootUrl(true) . '/gdrive-oauth/callback'
Gdrive::setHttp(?callable $http): void   // test seam, see below
```

### `Grav\Plugin\Gdrive\Drive`

Returns Drive's raw JSON arrays; you choose `fields`. `supportsAllDrives=true`
is sent on every call and `includeItemsFromAllDrives=true` on every `/files`
listing. No caching.

```php
const SCOPE_FILE, SCOPE_READONLY, SCOPE_FULL, FOLDER, API, UPLOAD_API

request(string $method, string $path, array $query = [], ?array $json = null): array
paginate(string $path, array $query): \Generator
children(string $folderId, array $mimes, string $fields): array
findByAppProperty(string $parentId, string $key, string $value, string $fields): array
ensureFolder(string $name, string $parentId = 'root'): string
download(string $id, string $dest): void
upload(string $localPath, string $parentId, string $name, array $appProperties = [], string $fields = 'id,name,md5Checksum,size'): array
trash(string $id): void
about(string $fields = 'user(emailAddress,displayName),storageQuota'): array
```

`upload()` opens a resumable session and streams the whole file in one PUT;
on failure it starts again from zero.

### `Grav\Plugin\Gdrive\DriveException`

Extends `\RuntimeException`. Every failure is one of these.

- `int $status`: the HTTP status (0 when there was none).
- `string $reason`: Google's error reason (`storageQuotaExceeded`,
  `notFound`, `invalid_grant`…) or one of ours: `scope_not_granted`,
  `not_connected`, `bad_credential`, `bad_state`, `unknown_account`,
  `transport`, `io`.
- `anchor(): string`: the Troubleshooting anchor for the reason
  (`storageQuotaExceeded` → `storage-quota-exceeded`).

`Gdrive::drive()` throws `scope_not_granted` (reconnect to grant it) when an
OAuth account lacks a scope, on the first call that needs a token.

### The `onGdriveScopes` event

Declare what your plugin needs, so the settings page can request it on Connect
and show "who uses what". The event is `Event(['declarations' => [...]])`; its
array access returns copies, so append by reassigning:

```php
public function onGdriveScopes(Event $e): void
{
    $e['declarations'] = [...$e['declarations'], [
        'plugin' => 'gdrive-images',
        'account' => (string) $this->config->get('plugins.gdrive-images.account', 'site'),
        'scopes' => [\Grav\Plugin\Gdrive\Drive::SCOPE_READONLY],
    ]];
}
```

### `Grav\Plugin\Gdrive\Setup` (blueprint helpers)

Static, safe to call from a blueprint: each falls back to sensible text if Grav
isn't fully booted, and never emits `<script` or `<div id=` (Admin2 hides such
display fields).

```php
Setup::accountOptions(): array            // name => "name (Service account|OAuth, email)"
Setup::consumerNotice(string $plugin): string   // markdown: declared account + scopes, granted?, link to the settings page
Setup::guide(string $name): string        // docs/setup/<name>.md with {{redirect_uri}}, {{sa_emails}}, {{scopes}}, {{site}} filled
Setup::checklist(): string                // markdown: per-account status, each ✘ linked to Troubleshooting
Setup::whoUsesWhat(): string              // markdown table: plugin · account · scopes · ready?
```

In a dependent plugin's blueprint:

```yaml
account:
  type: select
  label: Google Drive account
  default: site
  data-options@: '\Grav\Plugin\Gdrive\Setup::accountOptions'
drive_notice:
  type: display
  markdown: true
  data-content@: ['\Grav\Plugin\Gdrive\Setup::consumerNotice', 'gdrive-images']
```

### Admin2 endpoints

Registered through the api plugin's `onApiRegisterRoutes`, under `/api/v1`.
All need `api.gdrive.manage`. Success is `{"data": …}`; errors are
`application/problem+json` with `code` (the `DriveException` reason) and
`anchor` (its Troubleshooting entry). No response ever carries a secret.

| Method and path | Does |
|---|---|
| `GET /gdrive/accounts` | `{accounts, redirect_uri, wanted}`: each account's `status()` plus `declared` (per plugin), `missing` (declared scopes an OAuth account hasn't granted) and `test` (the last Test result); `wanted` lists declared account names that don't exist yet |
| `POST /gdrive/accounts` | Body `{name, type: service_account\|oauth, json}` (`json` is the file's text, ≤ 64 KB). Validates and stores the credential, records the account in `user/config/plugins/gdrive.yaml`. 201 with the list |
| `DELETE /gdrive/accounts/{name}` | Revokes (OAuth), deletes the files, drops it from config. The list |
| `POST /gdrive/accounts/{name}/test` | A real `about.get` with the declared scopes; stored in `user/data/gdrive/<name>.test.json`. `{result, …list}` |
| `POST /gdrive/accounts/{name}/connect` | `{url}`: Google's consent URL for the declared ∪ granted scopes (OAuth only) |

### The transport test seam

Every HTTP call goes through one callable:

```php
callable(string $method, string $url, array $opts): array{0: int, 1: string, 2: array<string, string>}
// returns [status, body, lowercase response headers]
// $opts: headers (string[]), body (string), infile (resource) + infile_size (int), sink (resource)
```

`Gdrive::setHttp($fake)` makes every client built afterwards use `$fake`
instead of curl (`null` restores curl), so a dependent plugin's smoke test can
stub Drive. `Drive`, `ServiceAccount`, `OAuthUser` and `Accounts` also take it
as a constructor argument.

## Development

```
php tests/smoke.php
phpstan analyse --memory-limit=1G    # needs a Grav install at .gravtest/grav-admin, with the api plugin in its user/plugins/api
```

## License

MIT
