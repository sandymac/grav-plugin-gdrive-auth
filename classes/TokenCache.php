<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

/**
 * Access-token caching shared by both credential kinds, so the cache-key rule
 * lives in one place: gdrive.token.<sha1(email|sorted scopes)>, kept for
 * expires_in - 60 seconds, memoised per process on top of Grav's cache.
 *
 * @internal
 */
trait TokenCache
{
    /** @var array<string, string> */
    private array $tokens = [];

    /** @param string[] $scopes */
    private function tokenKey(array $scopes): string
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return 'gdrive.token.' . sha1($this->email() . '|' . implode(' ', $scopes));
    }

    private function cachedToken(string $key): ?string
    {
        if (isset($this->tokens[$key])) {
            return $this->tokens[$key];
        }
        $cached = $this->cache?->fetch($key);

        return is_string($cached) && $cached !== '' ? $this->tokens[$key] = $cached : null;
    }

    /** Stores a token endpoint response's access_token and returns it. */
    private function storeToken(string $key, array $response): string
    {
        $token = (string) $response['access_token'];
        $this->tokens[$key] = $token;
        $this->cache?->save($key, $token, max(60, (int) ($response['expires_in'] ?? 3600) - 60));

        return $token;
    }

    /** @param string[] $scopes */
    public function forget(array $scopes): void
    {
        $key = $this->tokenKey($scopes);
        unset($this->tokens[$key]);
        $this->cache?->delete($key);
    }
}
