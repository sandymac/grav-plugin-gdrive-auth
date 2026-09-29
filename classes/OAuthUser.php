<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

use Grav\Common\Cache;

/**
 * A Google account connected through the site's own ("bring your own")
 * OAuth Web application client. The refresh token, granted scopes and email
 * live in a 0600 token file; access tokens are minted from it on demand.
 */
final class OAuthUser implements Credentials
{
    use TokenCache;

    public const AUTH_URI = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    public const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';
    /** What Google writes into a downloaded client JSON. Anything else is refused: the secret and the code go there. */
    public const GOOGLE_AUTH_URIS = [self::AUTH_URI, 'https://accounts.google.com/o/oauth2/auth'];
    public const GOOGLE_TOKEN_URIS = [self::TOKEN_URI, 'https://accounts.google.com/o/oauth2/token', 'https://www.googleapis.com/oauth2/v4/token'];
    public const NOT_GOOGLE = "This file's sign-in or token address isn't Google's. Upload the JSON Google downloaded, without editing it.";

    /** @var callable(string, string, array): array{int, string, array<string, string>} */
    private $http;

    /**
     * @param array $client the `web` section of the client JSON (see validateClient)
     * @param callable(string, string, array): array{int, string, array<string, string>} $http
     */
    public function __construct(private array $client, private string $tokenFile, private ?Cache $cache, callable $http)
    {
        $this->http = $http;
    }

    /**
     * Checks an uploaded client JSON and returns its `web` section. Only a
     * *Web application* client can redirect back to the site, so a Desktop
     * ("installed") client is refused with a message saying so.
     */
    public static function validateClient(array $json): array
    {
        if (($json['type'] ?? '') === 'service_account') {
            throw new DriveException('This is a service-account key, not an OAuth client. Upload the client JSON downloaded from Google Auth Platform → Clients, or add this account as a service account instead.', 'bad_credential');
        }
        if (isset($json['installed'])) {
            throw new DriveException('This is a Desktop app OAuth client. Create a client of type "Web application" (Google Auth Platform → Clients → Create client) with this site\'s redirect URI, and upload its JSON.', 'bad_credential');
        }
        $web = $json['web'] ?? null;
        if (!is_array($web) || !is_string($web['client_id'] ?? null) || $web['client_id'] === '' || !is_string($web['client_secret'] ?? null) || $web['client_secret'] === '') {
            throw new DriveException('Not an OAuth client file: expected the JSON of a "Web application" client, shaped {"web": {"client_id": …, "client_secret": …}}.', 'bad_credential');
        }
        if (isset($web['redirect_uris']) && !is_array($web['redirect_uris'])) {
            throw new DriveException('The OAuth client\'s redirect_uris must be a list.', 'bad_credential');
        }
        if ((isset($web['auth_uri']) && !in_array($web['auth_uri'], self::GOOGLE_AUTH_URIS, true))
            || (isset($web['token_uri']) && !in_array($web['token_uri'], self::GOOGLE_TOKEN_URIS, true))) {
            throw new DriveException(self::NOT_GOOGLE, 'bad_credential');
        }

        return $web;
    }

    /**
     * PKCE pair: a 43-char base64url verifier from 32 random bytes, and its S256 challenge.
     *
     * @return array{verifier: string, challenge: string}
     */
    public static function pkce(): array
    {
        $verifier = self::b64url(random_bytes(32));

        return ['verifier' => $verifier, 'challenge' => self::b64url(hash('sha256', $verifier, true))];
    }

    /**
     * Google's consent URL. offline + consent guarantees a refresh token even on
     * a reconnect; include_granted_scopes keeps scopes other plugins already got.
     *
     * @param string[] $scopes
     */
    public function authUrl(array $scopes, string $redirectUri, string $state, string $codeChallenge): string
    {
        // Always Google's own endpoint, never the file's (validateClient only vouches for it at upload).
        return self::AUTH_URI . '?' . http_build_query([
            'client_id' => $this->client['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Swaps an authorization code (plus its PKCE verifier) for Google's token response. */
    public function exchange(string $code, string $verifier, string $redirectUri): array
    {
        return $this->post([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ], 'authorization code exchange');
    }

    /** Persists the refresh token, granted scopes and email; keeps the old refresh token if Google sent none. */
    public function saveToken(array $tokenResponse, string $email): void
    {
        $refresh = $tokenResponse['refresh_token'] ?? $this->stored()['refresh_token'] ?? '';
        if (!is_string($refresh) || $refresh === '') {
            throw new DriveException('gdrive: Google returned no refresh token; click Connect again.', 'not_connected');
        }
        $scopes = array_values(array_filter(explode(' ', (string) ($tokenResponse['scope'] ?? ''))));
        Accounts::writeSecret($this->tokenFile, (string) json_encode([
            'refresh_token' => $refresh,
            'scopes' => $scopes,
            'email' => $email,
            'connected_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return string[] scopes the user granted at the last Connect */
    public function granted(): array
    {
        return (array) ($this->stored()['scopes'] ?? []);
    }

    public function email(): string
    {
        return (string) ($this->stored()['email'] ?? '');
    }

    /**
     * The wanted scopes a grant doesn't cover. `drive` covers `drive.file` and
     * `drive.readonly`; anything else only covers itself.
     *
     * @param string[] $granted
     * @param string[] $wanted
     * @return list<string>
     */
    public static function missingScopes(array $granted, array $wanted): array
    {
        $full = in_array(Drive::SCOPE_FULL, $granted, true);

        return array_values(array_filter($wanted, static fn (string $s): bool => !in_array($s, $granted, true)
            && !($full && in_array($s, [Drive::SCOPE_FILE, Drive::SCOPE_READONLY], true))));
    }

    /** @param string[] $scopes */
    public function token(array $scopes): string
    {
        $stored = $this->stored();
        if ($stored === null) {
            throw new DriveException('gdrive: this Google account is not connected; click Connect on the Google Drive Auth settings page.', 'not_connected');
        }
        $missing = self::missingScopes((array) ($stored['scopes'] ?? []), $scopes);
        if ($missing !== []) {
            throw new DriveException('gdrive: not granted: ' . implode(' ', $missing) . '. Reconnect the account on the Google Drive Auth settings page to grant it.', 'scope_not_granted');
        }
        $key = $this->tokenKey($scopes);
        $cached = $this->cachedToken($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $this->post(['grant_type' => 'refresh_token', 'refresh_token' => (string) $stored['refresh_token']], 'token refresh');
        } catch (DriveException $e) {
            if ($e->reason === 'invalid_grant') {
                throw new DriveException('gdrive: Google refused the refresh token (revoked, a client left in Testing expires it after 7 days, or ~6 months unused). Reconnect the account.', 'invalid_grant', $e->status, $e);
            }
            throw $e;
        }

        return $this->storeToken($key, $data);
    }

    /**
     * Revokes the refresh token at Google, then deletes the token file even if
     * Google failed: the admin asked to disconnect. A 400 means already revoked.
     */
    public function revoke(): void
    {
        $refresh = (string) ($this->stored()['refresh_token'] ?? '');
        if ($refresh === '') {
            return;
        }
        try {
            [$status, $body] = ($this->http)('POST', self::REVOKE_URI, [
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => http_build_query(['token' => $refresh]),
            ]);
        } finally {
            @unlink($this->tokenFile);
        }
        if ($status !== 200 && $status !== 400) {
            throw DriveException::fromResponse($status, $body, 'token revoke');
        }
    }

    /** POSTs a grant to the token endpoint with the client's credentials. */
    private function post(array $params, string $what): array
    {
        [$status, $body] = ($this->http)('POST', self::TOKEN_URI, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($params + [
                'client_id' => $this->client['client_id'],
                'client_secret' => $this->client['client_secret'],
            ]),
        ]);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            throw DriveException::fromResponse($status, $body, $what);
        }

        return $data;
    }

    /** The token file, or null when not connected. */
    private function stored(): ?array
    {
        $json = is_file($this->tokenFile) ? @file_get_contents($this->tokenFile) : false;
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) && is_string($data['refresh_token'] ?? null) ? $data : null;
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
