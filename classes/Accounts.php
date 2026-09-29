<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

use Grav\Common\Cache;

/**
 * The account registry: account name → Credentials. Config
 * (plugins.gdrive-auth.accounts.<name>.type) says what kind each account is; the
 * secrets live in the data dir under fixed names, never in config:
 *   <name>.sa.json      service-account key
 *   <name>.client.json  OAuth Web client
 *   <name>.token.json   OAuth refresh token, granted scopes, email
 *   oauth-state/        pending Connects, keyed by sha256(state)
 * Names become filenames, so every entry point validates them.
 */
final class Accounts
{
    public const NAME = '/^[a-z0-9][a-z0-9_-]{0,31}$/';
    public const TYPES = ['service_account', 'oauth'];
    private const STATE_TTL = 600;
    public const REVOKE_FAILED = "Google couldn't be reached, so check Google Account → Third-party connections (https://myaccount.google.com/connections) and remove this site there.";

    /** @var callable(string, string, array): array{int, string, array<string, string>} */
    private $http;
    /** @var array<string, Credentials> */
    private array $creds = [];

    /**
     * @param array $config plugins.gdrive-auth
     * @param string $dataDir resolved user://data/gdrive/auth
     * @param callable(string, string, array): array{int, string, array<string, string>} $http
     */
    public function __construct(private array $config, private string $dataDir, private ?Cache $cache, callable $http)
    {
        $this->http = $http;
    }

    /**
     * This registry over another transport, e.g. a short-timeout one for a
     * settings-page check, so its token refreshes use it too. Credentials are
     * rebuilt; tokens are still shared through the cache.
     *
     * @param callable(string, string, array): array{int, string, array<string, string>} $http
     */
    public function withHttp(callable $http): self
    {
        $copy = clone $this;
        $copy->http = $http;
        $copy->creds = [];

        return $copy;
    }

    /**
     * $config with the account added or retyped. Pure: the caller persists it.
     */
    public static function configWith(array $config, string $name, string $type): array
    {
        self::check($name);
        if (!in_array($type, self::TYPES, true)) {
            throw new DriveException("gdrive: unknown account type '{$type}'", 'bad_credential');
        }
        $config['accounts'][$name] = ['type' => $type] + (array) ($config['accounts'][$name] ?? []);

        return $config;
    }

    /** The current config, including accounts saved or removed through this instance; the caller persists it. */
    public function config(): array
    {
        return $this->config;
    }

    /** @return string[] */
    public function names(): array
    {
        $names = array_map('strval', array_keys((array) ($this->config['accounts'] ?? [])));

        return array_values(array_filter($names, static fn (string $n): bool => preg_match(self::NAME, $n) === 1));
    }

    public function type(string $name): string
    {
        self::check($name);
        $type = $this->config['accounts'][$name]['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            throw new DriveException("gdrive: no Google Drive account named '{$name}'", 'unknown_account');
        }

        return $type;
    }

    public function credentials(string $name): Credentials
    {
        if (isset($this->creds[$name])) {
            return $this->creds[$name];
        }
        $type = $this->type($name);
        $file = $this->file($name, $type === 'oauth' ? 'client' : 'sa');
        if (!is_file($file)) {
            throw new DriveException("gdrive: account '{$name}' has no credential uploaded yet", 'not_connected');
        }
        if ($type === 'service_account') {
            return $this->creds[$name] = ServiceAccount::fromFile($file, $this->cache, $this->http);
        }
        $client = json_decode((string) @file_get_contents($file), true);

        return $this->creds[$name] = new OAuthUser(OAuthUser::validateClient(is_array($client) ? $client : []), $this->file($name, 'token'), $this->cache, $this->http);
    }

    /** @param string[] $scopes */
    public function drive(string $name, array $scopes): Drive
    {
        return new Drive($this->credentials($name), $scopes, $this->http);
    }

    /**
     * Validates and stores an uploaded credential (0600, fixed filename) and
     * records the account in this instance's config(). The JSON is never
     * logged or echoed; the error messages only say what kind was expected.
     *
     * @return string '' or, when replacing a connection whose token Google
     *   couldn't revoke, a warning for the admin (the files are replaced anyway)
     */
    public function saveCredential(string $name, string $type, string $json): string
    {
        $revoked = true;
        $config = self::configWith($this->config, $name, $type);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new DriveException('That is not a JSON file. Upload the file Google gave you, unchanged.', 'bad_credential');
        }
        if ($type === 'service_account') {
            ServiceAccount::validateKey($data);
            $revoked = $this->revokeQuietly($name); // still typed as before, so an old OAuth token is revoked
            @unlink($this->file($name, 'client'));
            @unlink($this->file($name, 'token'));
        } else {
            $web = OAuthUser::validateClient($data);
            $old = json_decode((string) @file_get_contents($this->file($name, 'client')), true);
            if (($old['web']['client_id'] ?? null) !== $web['client_id']) {
                $revoked = $this->revokeQuietly($name); // a token only works with the client that issued it
                @unlink($this->file($name, 'token'));
            }
            @unlink($this->file($name, 'sa'));
        }
        self::writeSecret($this->file($name, $type === 'oauth' ? 'client' : 'sa'), (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->config = $config;
        unset($this->creds[$name]);

        return $revoked ? '' : "Saved. The old connection's access at Google wasn't revoked: " . self::REVOKE_FAILED;
    }

    /**
     * Revokes an OAuth token (best effort), deletes the account's files, and drops it from config().
     *
     * @return string '' or, when Google couldn't revoke the token, a warning for the admin (the files are deleted anyway)
     */
    public function remove(string $name): string
    {
        self::check($name);
        $revoked = $this->revokeQuietly($name);
        foreach (['sa', 'client', 'token'] as $kind) {
            @unlink($this->file($name, $kind));
        }
        unset($this->creds[$name], $this->config['accounts'][$name]);

        return $revoked ? '' : 'Removed from this site. ' . self::REVOKE_FAILED;
    }

    /**
     * What the settings page may show about an account. No secrets.
     *
     * @return array{name: string, type: string, has_credential: bool, connected: bool, email: ?string, scopes: string[]}
     */
    public function status(string $name): array
    {
        $type = $this->type($name);
        $status = ['name' => $name, 'type' => $type, 'has_credential' => is_file($this->file($name, $type === 'oauth' ? 'client' : 'sa')), 'connected' => false, 'email' => null, 'scopes' => []];
        if (!$status['has_credential']) {
            return $status;
        }
        try {
            $creds = $this->credentials($name);
        } catch (DriveException) {
            return $status;
        }
        $email = $creds->email();
        $status['email'] = $email === '' ? null : $email;
        if ($creds instanceof OAuthUser) {
            $status['scopes'] = $creds->granted();
            $status['connected'] = is_file($this->file($name, 'token'));
        } else {
            $status['connected'] = true;
        }

        return $status;
    }

    /**
     * A real about.get. With no $scopes, uses what the account can already do.
     *
     * @param string[] $scopes
     * @return array{ok: true, email: ?string, quota: ?array}|array{ok: false, reason: string, anchor: string, message: string}
     */
    public function test(string $name, array $scopes): array
    {
        try {
            $creds = $this->credentials($name);
            if ($scopes === []) {
                $scopes = $creds instanceof OAuthUser ? $creds->granted() : [Drive::SCOPE_FILE];
            }
            $about = (new Drive($creds, $scopes, $this->http))->about();

            return ['ok' => true, 'email' => $about['user']['emailAddress'] ?? null, 'quota' => $about['storageQuota'] ?? null];
        } catch (\RuntimeException $e) {
            $e = $e instanceof DriveException ? $e : new DriveException($e->getMessage(), 'bad_credential');

            return ['ok' => false, 'reason' => $e->reason, 'anchor' => $e->anchor(), 'message' => $e->getMessage()];
        }
    }

    /**
     * Starts an OAuth Connect: a single-use random state bound server-side to
     * the account, PKCE verifier, scopes, admin and redirect URI for 10
     * minutes. Admin2's bearer token doesn't come back with Google's redirect,
     * so this state is the only thing the callback trusts.
     *
     * @param string[] $scopes
     * @return string Google's consent URL
     */
    public function startConnect(string $name, array $scopes, string $redirectUri, string $username): string
    {
        $creds = $this->credentials($name);
        if (!$creds instanceof OAuthUser) {
            throw new DriveException("gdrive: '{$name}' is a service account; only OAuth accounts connect.", 'bad_credential');
        }
        $this->purgeStates();
        $pkce = OAuthUser::pkce();
        $state = bin2hex(random_bytes(32));
        self::writeSecret($this->stateFile($state), (string) json_encode([
            'account' => $name,
            'verifier' => $pkce['verifier'],
            'scopes' => array_values($scopes),
            'username' => $username,
            'redirect_uri' => $redirectUri,
            'expires' => time() + self::STATE_TTL,
        ]));

        return $creds->authUrl($scopes, $redirectUri, $state, $pkce['challenge']);
    }

    /**
     * Completes a Connect from Google's redirect. The state file is deleted
     * before it's used, so a replayed or raced callback fails with bad_state.
     *
     * @param ?string $account set to the account once the state checks out, so a failure can still say which
     * @param-out string $account
     * @return array status() plus the admin `username` who started it
     */
    public function finishConnect(string $state, string $code, ?string &$account = null): array
    {
        $pending = $this->takeState($state);
        $name = $account = (string) $pending['account'];
        $creds = $this->credentials($name);
        if (!$creds instanceof OAuthUser) {
            throw new DriveException("gdrive: '{$name}' is no longer an OAuth account", 'bad_state');
        }

        $token = $creds->exchange($code, (string) ($pending['verifier'] ?? ''), (string) ($pending['redirect_uri'] ?? ''));
        [$status, $body] = ($this->http)('GET', Drive::API . '/about?fields=user(emailAddress)', ['headers' => ['Authorization: Bearer ' . $token['access_token']]]);
        $about = json_decode($body, true);
        $email = is_array($about) ? (string) ($about['user']['emailAddress'] ?? '') : '';
        if ($status !== 200 || $email === '') {
            throw DriveException::fromResponse($status, $body, 'about.get after Connect');
        }
        $creds->saveToken($token, $email);

        return $this->status($name) + ['username' => (string) ($pending['username'] ?? '')];
    }

    /** Consumes the state of a Connect that Google reports as failed (e.g. cancelled); returns its account, or null. */
    public function cancelConnect(string $state): ?string
    {
        try {
            return (string) $this->takeState($state)['account'];
        } catch (DriveException) {
            return null;
        }
    }

    /**
     * The pending Connect for $state, deleted before it's returned: only one
     * caller can win the unlink, so a replayed or raced callback gets bad_state.
     *
     * @return array<string, mixed> with a string `account`
     */
    private function takeState(string $state): array
    {
        $file = preg_match('/^[0-9a-f]{64}$/', $state) === 1 ? $this->stateFile($state) : '';
        $raw = $file !== '' && is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false || !@unlink($file)) {
            throw new DriveException('gdrive: unknown or already used OAuth state', 'bad_state');
        }
        $pending = json_decode($raw, true);
        if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time() || !is_string($pending['account'] ?? null)) {
            throw new DriveException('gdrive: expired OAuth state; click Connect again', 'bad_state');
        }

        return $pending;
    }

    /**
     * Writes a secret atomically with mode 0600: chmod before the first byte,
     * then rename over the target, so no reader sees a partial or open file.
     *
     * @internal
     */
    public static function writeSecret(string $path, string $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new DriveException('gdrive: cannot write ' . basename($path), 'io');
        }
        @chmod($tmp, 0600);
        $ok = fwrite($fh, $data) === strlen($data);
        fclose($fh);
        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new DriveException('gdrive: cannot write ' . basename($path), 'io');
        }
    }

    private static function check(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new DriveException('Account names are 1–32 characters: lowercase letters, digits, "-" and "_", starting with a letter or digit.', 'bad_credential');
        }
    }

    private function file(string $name, string $kind): string
    {
        self::check($name);

        return "{$this->dataDir}/{$name}.{$kind}.json";
    }

    private function stateFile(string $state): string
    {
        return "{$this->dataDir}/oauth-state/" . hash('sha256', $state) . '.json';
    }

    private function purgeStates(): void
    {
        foreach (glob("{$this->dataDir}/oauth-state/*.json") ?: [] as $file) {
            $pending = json_decode((string) @file_get_contents($file), true);
            if ((int) ($pending['expires'] ?? 0) < time()) {
                @unlink($file);
            }
        }
    }

    /** False when there was a token and Google didn't confirm revoking it (refused, or unreachable). */
    private function revokeQuietly(string $name): bool
    {
        $hadToken = is_file($this->file($name, 'token'));
        try {
            $creds = $this->credentials($name);
            if ($creds instanceof OAuthUser) {
                $creds->revoke();
            }

            return true;
        } catch (\RuntimeException) {
            return !$hadToken; // the files are deleted either way
        }
    }
}
