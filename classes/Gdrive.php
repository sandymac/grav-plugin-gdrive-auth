<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

use Grav\Common\Grav;
use RocketTheme\Toolbox\File\YamlFile;
use RocketTheme\Toolbox\Event\Event;

/**
 * The entry point for other plugins:
 *   Gdrive::drive('site', [Drive::SCOPE_READONLY])->children(...)
 * Built lazily from Grav's config, locator and cache on first use.
 */
final class Gdrive
{
    public const CALLBACK = '/gdrive-oauth/callback';

    private static ?Accounts $accounts = null;
    /** @var (callable(string, string, array): array{int, string, array<string, string>})|null */
    private static $http = null;

    /**
     * A Drive client for a configured account. Throws DriveException
     * (scope_not_granted, not_connected, unknown_account…) on first use if the
     * account can't supply the scopes.
     *
     * @param string[] $scopes
     */
    public static function drive(string $account, array $scopes): Drive
    {
        return self::accounts()->drive($account, $scopes);
    }

    public static function accounts(): Accounts
    {
        if (self::$accounts === null) {
            $grav = Grav::instance();
            $dir = (string) $grav['locator']->findResource('user://data/gdrive', true, true);
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $config = (array) $grav['config']->get('plugins.gdrive-auth', []);
            $legacy = $grav['locator']->findResource('user://config/plugins/gdrive.yaml', true);
            if (is_string($legacy) && empty($config['accounts'])) {
                $file = YamlFile::instance($legacy);
                try {
                    [$config, $used] = self::withLegacyAccounts($config, (array) $file->content());
                    if ($used) {
                        $grav['log']->notice('gdrive: using accounts from legacy user/config/plugins/gdrive.yaml; rename it to gdrive-auth.yaml');
                    }
                } catch (\Throwable) {
                    // unreadable legacy file: behave as if absent
                } finally {
                    $file->free();
                }
            }
            self::$accounts = new Accounts(
                $config,
                $dir,
                $grav['cache'],
                self::$http ?? Http::withRetry([Http::class, 'curl']),
            );
        }

        return self::$accounts;
    }

    /**
     * Read-only upgrade path for sites still on the pre-0.1.13 slug `gdrive`:
     * when plugins.gdrive-auth has no accounts, borrow them from the legacy
     * user/config/plugins/gdrive.yaml. Nothing is moved or written.
     *
     * @param array $config plugins.gdrive-auth
     * @param array $legacy the legacy file's parsed content
     * @return array{0: array, 1: bool} config, and whether the legacy accounts were used
     */
    public static function withLegacyAccounts(array $config, array $legacy): array
    {
        if (empty($config['accounts']) && !empty($legacy['accounts']) && is_array($legacy['accounts'])) {
            $config['accounts'] = $legacy['accounts'];

            return [$config, true];
        }

        return [$config, false];
    }

    /**
     * Test seam: every client built afterwards uses $http instead of curl
     * (null restores curl). Also drops the memoised registry.
     *
     * @param (callable(string, string, array): array{int, string, array<string, string>})|null $http
     */
    public static function setHttp(?callable $http): void
    {
        self::$http = $http;
        self::$accounts = null;
    }

    /**
     * What dependent plugins declared through onGdriveScopes.
     *
     * @return array<int, array{plugin: string, account: string, scopes: string[]}>
     */
    public static function scopes(): array
    {
        $event = Grav::instance()->fireEvent('onGdriveScopes', new Event(['declarations' => []]));
        $out = [];
        foreach ((array) $event['declarations'] as $d) {
            if (is_array($d) && is_string($d['plugin'] ?? null) && is_string($d['account'] ?? null) && is_array($d['scopes'] ?? null)) {
                $out[] = ['plugin' => $d['plugin'], 'account' => $d['account'], 'scopes' => array_values(array_map('strval', $d['scopes']))];
            }
        }

        return $out;
    }

    /** The OAuth redirect URI to register on the Google client. */
    public static function redirectUri(): string
    {
        return rtrim((string) Grav::instance()['uri']->rootUrl(true), '/') . self::CALLBACK;
    }
}
