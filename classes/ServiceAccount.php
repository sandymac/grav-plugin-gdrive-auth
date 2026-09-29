<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

use Grav\Common\Cache;

/**
 * Service-account credentials. No SDK: an RS256 JWT signed with openssl_sign
 * is swapped for an access token at Google's token endpoint (never the key's
 * token_uri: that's only checked to be Google's).
 */
final class ServiceAccount implements Credentials
{
    use TokenCache;

    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var callable(string, string, array): array{int, string, array<string, string>} */
    private $http;

    /**
     * @param array $sa decoded service-account key JSON (already validated)
     * @param callable(string, string, array): array{int, string, array<string, string>} $http
     */
    public function __construct(private array $sa, private ?Cache $cache, callable $http)
    {
        $this->http = $http;
    }

    /** @param callable(string, string, array): array{int, string, array<string, string>} $http */
    public static function fromFile(string $path, ?Cache $cache, callable $http): self
    {
        $json = @file_get_contents($path);
        $sa = $json === false ? null : json_decode($json, true);
        if (!is_array($sa)) {
            throw new DriveException("The service-account key file couldn't be read. Upload it again on the Accounts tab.", 'bad_credential');
        }

        return new self(self::validateKey($sa), $cache, $http);
    }

    /**
     * Checks an uploaded key's shape before it's stored. The messages are
     * shown to the admin, so they say what was expected, never the key.
     */
    public static function validateKey(array $json): array
    {
        if (isset($json['web']) || isset($json['installed'])) {
            throw new DriveException('This is an OAuth client file, not a service-account key. Upload the JSON key created under the service account (Keys → Add key → JSON), or add this account as OAuth instead.', 'bad_credential');
        }
        if (($json['type'] ?? '') !== 'service_account' || !is_string($json['client_email'] ?? null) || !is_string($json['private_key'] ?? null)) {
            throw new DriveException('Not a service-account key: expected a JSON file with "type": "service_account", "client_email" and "private_key".', 'bad_credential');
        }
        if (openssl_pkey_get_private($json['private_key']) === false) {
            throw new DriveException('The service-account key\'s private_key is not a readable PEM key. Upload the file Google gave you unchanged.', 'bad_credential');
        }
        if (isset($json['token_uri']) && !in_array($json['token_uri'], OAuthUser::GOOGLE_TOKEN_URIS, true)) {
            throw new DriveException(OAuthUser::NOT_GOOGLE, 'bad_credential');
        }

        return $json;
    }

    /**
     * Signed RS256 JWT for the token exchange. Pure, so the smoke test can verify it.
     *
     * @param string[] $scopes
     */
    public static function jwt(array $sa, array $scopes, int $now): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64((string) json_encode([
            'iss' => $sa['client_email'],
            'scope' => implode(' ', $scopes),
            'aud' => self::TOKEN_URI, // what Google expects, whatever the key file says
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';
        if (!openssl_sign("{$header}.{$claims}", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new DriveException('gdrive: openssl_sign failed: ' . (string) openssl_error_string(), 'bad_credential');
        }

        return "{$header}.{$claims}." . $b64($signature);
    }

    public function email(): string
    {
        return (string) $this->sa['client_email'];
    }

    /** @param string[] $scopes */
    public function token(array $scopes): string
    {
        $key = $this->tokenKey($scopes);
        $cached = $this->cachedToken($key);
        if ($cached !== null) {
            return $cached;
        }

        [$status, $body] = ($this->http)('POST', self::TOKEN_URI, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => self::jwt($this->sa, $scopes, time()),
            ]),
        ]);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            throw DriveException::fromResponse($status, $body, 'service-account token exchange');
        }

        return $this->storeToken($key, $data);
    }
}
