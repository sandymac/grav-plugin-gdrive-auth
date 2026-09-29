<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

use Grav\Common\Grav;

/**
 * Markdown and options for blueprints (`data-content@`, `data-options@`),
 * plus the pure helpers the Admin2 endpoints share with them.
 *
 * Blueprints are loaded in odd places (a disabled plugin, a CLI cache warm, a
 * half-booted Grav), so every public renderer catches everything and falls back
 * to text that still makes sense. Admin2 hides a display field whose content
 * contains `<script` or `<div id=`, so nothing here may ever emit either.
 */
final class Setup
{
    public const GUIDES = ['start-here', 'service-account', 'oauth', 'troubleshooting'];
    /** The largest credential JSON the endpoint accepts; Google's are ~2.5 KB. */
    public const MAX_JSON = 65536;
    /** Guide file → settings-page tab (blueprints.yaml keys; Admin2 selects a tab by `#<key>`). */
    private const TABS = ['start-here' => 'start', 'service-account' => 'service_account', 'oauth' => 'oauth', 'troubleshooting' => 'troubleshooting'];
    private const SCOPE_PREFIX = 'https://www.googleapis.com/auth/';
    private const TYPE_LABEL = ['service_account' => 'Service account', 'oauth' => 'OAuth'];

    /**
     * Account picker options for other plugins' blueprints:
     * `data-options@: '\Grav\Plugin\Gdrive\Setup::accountOptions'`.
     *
     * @return array<string, string> name → "name (Service account, svc@…)"
     */
    public static function accountOptions(): array
    {
        try {
            $accounts = Gdrive::accounts();

            return self::options(array_map([$accounts, 'status'], $accounts->names()));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A line for a dependent plugin's settings page: which account and scopes
     * it declared through onGdriveScopes, whether they're granted, and a link
     * to this plugin's settings page.
     */
    public static function consumerNotice(string $plugin): string
    {
        $link = 'Set up Google Drive access in **Plugins → Google Drive Library**.';
        try {
            $link = sprintf('[Set up Google Drive access →](%s)', self::settingsUrl());
            $mine = array_values(array_filter(Gdrive::scopes(), static fn (array $d): bool => $d['plugin'] === $plugin));
            if ($mine === []) {
                return self::safe("This plugin hasn't declared a Google Drive account yet (is it enabled?). {$link}");
            }
            $rows = self::rows(Gdrive::accounts(), $mine, self::dataDir());
            $lines = [];
            foreach ($mine as $d) {
                $scopes = self::scopeList($d['scopes']);
                $row = current(array_filter($rows, static fn (array $r): bool => $r['name'] === $d['account']));
                $lines[] = sprintf('Uses Google Drive account **%s** with %s: %s', self::clean($d['account']), $scopes, self::verdict($row ?: null, $d['scopes']));
            }

            return self::safe(implode("\n\n", $lines) . "\n\n" . $link);
        } catch (\Throwable) {
            return self::safe($link);
        }
    }

    /** A setup guide from docs/setup/<name>.md with this site's values filled in. */
    public static function guide(string $name): string
    {
        if (!in_array($name, self::GUIDES, true)) {
            return '';
        }
        $md = @file_get_contents(dirname(__DIR__) . "/docs/setup/{$name}.md");
        if ($md === false) {
            return "The {$name} guide is missing from this install of the plugin.";
        }

        return self::render($md, self::values());
    }

    /** Per-account status as of page load, every ✘ linking to its fix. */
    public static function checklist(): string
    {
        try {
            $rows = self::rows(Gdrive::accounts(), Gdrive::scopes(), self::dataDir());
        } catch (\Throwable) {
            return 'The account checklist is unavailable right now; the **Accounts** tab shows the live state.';
        }
        if ($rows === []) {
            return "### Your accounts\n\nNo accounts yet. Pick a type with the table above, follow its guide, then add the account on the **Accounts** tab.";
        }
        $out = ["### Your accounts\n\n_As of page load; reload the page after changing an account._"];
        foreach ($rows as $r) {
            $oauth = $r['type'] === 'oauth';
            $items = [
                $r['has_credential'] ? '✔ ' . ($oauth ? 'OAuth client uploaded' : 'Key uploaded') : '✘ No ' . ($oauth ? 'OAuth client' : 'key') . ' uploaded yet: upload it on the **Accounts** tab',
            ];
            if ($oauth && $r['has_credential']) {
                $items[] = $r['connected'] ? '✔ Connected as ' . self::clean((string) $r['email']) : '✘ Not connected: click **Connect** on the **Accounts** tab ([help](#troubleshooting--not-connected))';
            }
            if ($r['missing'] !== []) {
                $items[] = '✘ Not granted: ' . self::scopeList($r['missing']) . ': click **Reconnect** ([help](#troubleshooting--scope-not-granted))';
            }
            $t = $r['test'];
            $items[] = match (true) {
                $t === null => '• Not tested yet: click **Test** on the **Accounts** tab',
                $t['ok'] ?? false => '✔ Drive reachable (tested ' . self::clean((string) ($t['at'] ?? '')) . ')',
                default => '✘ Last test failed: `' . self::clean((string) ($t['reason'] ?? 'error')) . '` ([fix](#troubleshooting--' . self::clean((string) ($t['anchor'] ?? '')) . '))',
            };
            $out[] = sprintf("**%s** (%s)\n\n- %s", self::clean($r['name']), self::TYPE_LABEL[$r['type']], implode("\n- ", $items));
        }

        return self::safe(implode("\n\n", $out));
    }

    /** The plugin · account · scopes · granted? table from onGdriveScopes. */
    public static function whoUsesWhat(): string
    {
        try {
            $declarations = Gdrive::scopes();
            $rows = self::rows(Gdrive::accounts(), $declarations, self::dataDir());
        } catch (\Throwable) {
            return 'The "who uses what" table is unavailable right now.';
        }
        if ($declarations === []) {
            return "### Who uses what\n\nNo installed plugin has declared a Google Drive account yet.";
        }
        $out = "### Who uses what\n\n| Plugin | Account | Scopes | Ready? |\n|---|---|---|---|\n";
        foreach ($declarations as $d) {
            $row = current(array_filter($rows, static fn (array $r): bool => $r['name'] === $d['account']));
            $out .= sprintf("| %s | %s | %s | %s |\n", self::clean($d['plugin']), self::clean($d['account']), self::scopeList($d['scopes']), self::verdict($row ?: null, $d['scopes']));
        }

        return self::safe($out);
    }

    /**
     * Fills {{placeholders}}, points links between guides at the settings-page
     * tabs (`oauth.md` → `#oauth`, `troubleshooting.md#invalid-grant` → `#troubleshooting--invalid-grant`)
     * and neutralises anything Admin2 would refuse to display. Pure.
     *
     * @internal
     * @param array<string, string> $values placeholder → markdown
     */
    public static function render(string $md, array $values): string
    {
        $md = (string) preg_replace_callback('/\]\((start-here|service-account|oauth|troubleshooting)\.md(?:#([a-z0-9-]+))?\)/', static fn (array $m): string => '](#' . self::TABS[$m[1]] . (isset($m[2]) ? '--' . $m[2] : '') . ')', $md);

        return self::safe(strtr($md, array_combine(array_map(static fn (string $k): string => '{{' . $k . '}}', array_keys($values)), $values)));
    }

    /**
     * Checks the POST /gdrive/accounts body and returns it typed. Throws
     * \InvalidArgumentException with a message fit to show the admin. Pure.
     *
     * @internal
     * @return array{name: string, type: string, json: string}
     */
    public static function accountBody(mixed $body): array
    {
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Send a JSON object: {"name", "type", "json"}.');
        }
        $name = $body['name'] ?? null;
        $type = $body['type'] ?? null;
        $json = $body['json'] ?? null;
        if (!is_string($name) || preg_match(Accounts::NAME, $name) !== 1) {
            throw new \InvalidArgumentException('Account names are 1–32 characters: lowercase letters, digits, "-" and "_", starting with a letter or digit.');
        }
        if (!is_string($type) || !in_array($type, Accounts::TYPES, true)) {
            throw new \InvalidArgumentException('The type must be "service_account" or "oauth".');
        }
        if (!is_string($json) || trim($json) === '') {
            throw new \InvalidArgumentException('Choose the JSON file Google gave you, or paste its contents.');
        }
        if (strlen($json) > self::MAX_JSON) {
            throw new \InvalidArgumentException('That file is too large to be a Google credential (the limit is 64 KB).');
        }

        return ['name' => $name, 'type' => $type, 'json' => $json];
    }

    /**
     * What the settings page shows per account: status() plus what plugins
     * declared for it, the declared scopes an OAuth account hasn't granted,
     * and the last Test result. No secrets.
     *
     * @internal
     * @param array<int, array{plugin: string, account: string, scopes: string[]}> $declarations
     * @return list<array{name: string, type: string, has_credential: bool, connected: bool, email: ?string, scopes: string[], declared: list<array{plugin: string, scopes: string[]}>, missing: string[], test: ?array}>
     */
    public static function rows(Accounts $accounts, array $declarations, string $dataDir): array
    {
        $rows = [];
        foreach ($accounts->names() as $name) {
            try {
                $row = $accounts->status($name);
            } catch (DriveException) {
                continue;
            }
            $row['declared'] = [];
            foreach ($declarations as $d) {
                if ($d['account'] === $name) {
                    $row['declared'][] = ['plugin' => $d['plugin'], 'scopes' => $d['scopes']];
                }
            }
            $row['missing'] = $row['type'] === 'oauth' && $row['connected'] ? array_values(array_diff(self::declaredScopes($declarations, $name), $row['scopes'])) : [];
            $test = json_decode((string) @file_get_contents("{$dataDir}/{$name}.test.json"), true);
            $row['test'] = is_array($test) ? $test : null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @internal
     * @param array<int, array{plugin: string, account: string, scopes: string[]}> $declarations
     * @return string[] every scope any plugin declared for $account
     */
    public static function declaredScopes(array $declarations, string $account): array
    {
        $scopes = [];
        foreach ($declarations as $d) {
            if ($d['account'] === $account) {
                $scopes = [...$scopes, ...$d['scopes']];
            }
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @internal
     * @param array<int, array{name: string, type: string, email: ?string, connected: bool}> $statuses
     * @return array<string, string>
     */
    public static function options(array $statuses): array
    {
        $out = [];
        foreach ($statuses as $s) {
            $who = $s['email'] ?? null;
            $out[$s['name']] = sprintf('%s (%s, %s)', $s['name'], self::TYPE_LABEL[$s['type']] ?? $s['type'], $who !== null && $who !== '' ? $who : ($s['type'] === 'oauth' ? 'not connected' : 'no key yet'));
        }

        return $out;
    }

    /** @internal resolved user://data/gdrive */
    public static function dataDir(): string
    {
        return (string) Grav::instance()['locator']->findResource('user://data/gdrive', true, true);
    }

    /** Placeholder values for the guides; each one falls back on its own. */
    private static function values(): array
    {
        $try = static function (callable $fn, string $fallback): string {
            try {
                $v = (string) $fn();

                return $v !== '' ? $v : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        return [
            'redirect_uri' => $try(static fn (): string => self::clean(Gdrive::redirectUri()), 'https://YOUR-SITE' . Gdrive::CALLBACK),
            'site' => $try(static fn (): string => self::clean((string) parse_url(Gdrive::redirectUri(), PHP_URL_HOST)), 'your site'),
            'sa_emails' => $try(static function (): string {
                $accounts = Gdrive::accounts();
                $emails = [];
                foreach ($accounts->names() as $name) {
                    $s = $accounts->status($name);
                    if ($s['type'] === 'service_account' && $s['email'] !== null) {
                        $emails[] = sprintf('`%s` (account **%s**)', self::clean($s['email']), $name);
                    }
                }

                return implode(', ', $emails);
            }, '_(upload a key first, and the address appears here)_'),
            'scopes' => $try(static function (): string {
                $lines = [];
                foreach (Gdrive::scopes() as $d) {
                    foreach ($d['scopes'] as $scope) {
                        $lines[] = sprintf('- `%s` (for **%s**, account **%s**)', self::clean($scope), self::clean($d['plugin']), self::clean($d['account']));
                    }
                }

                return implode("\n", array_unique($lines));
            }, '- No installed plugin has declared any yet. Galleries need `' . Drive::SCOPE_READONLY . '`; backups need `' . Drive::SCOPE_FILE . '`.'),
        ];
    }

    /**
     * "✔ ready" or "✘ why", for one declaration against its account's row.
     *
     * @param string[] $scopes
     */
    private static function verdict(?array $row, array $scopes): string
    {
        return match (true) {
            $row === null => '✘ no such account yet: add it',
            !$row['has_credential'] => '✘ no credential uploaded yet',
            !$row['connected'] => '✘ not connected yet: click Connect',
            $row['type'] === 'oauth' && array_diff($scopes, $row['scopes']) !== [] => '✘ not granted yet: click Reconnect',
            $row['test'] !== null && !($row['test']['ok'] ?? false) => '✘ last test failed: `' . self::clean((string) ($row['test']['reason'] ?? 'error')) . '`',
            default => '✔ ready',
        };
    }

    /** @param string[] $scopes */
    private static function scopeList(array $scopes): string
    {
        return implode(', ', array_map(static fn (string $s): string => '`' . self::clean(str_replace(self::SCOPE_PREFIX, '', $s)) . '`', $scopes)) ?: '(no scopes)';
    }

    private static function settingsUrl(): string
    {
        $grav = Grav::instance();
        $route = trim((string) $grav['config']->get('plugins.admin2.route', '/admin'), '/');

        return rtrim((string) $grav['uri']->rootUrl(false), '/') . '/' . $route . '/plugins/gdrive';
    }

    /** Values from uploaded files and other plugins go into markdown: no markup, no code-span breakouts. */
    private static function clean(string $s): string
    {
        return (string) preg_replace('/[<>`|\r\n]/', '', $s);
    }

    /** The last line of defence for Admin2's display-field rule. */
    private static function safe(string $md): string
    {
        return (string) preg_replace('/<(?=\s*(script|div\s+id\s*=))/i', '&lt;', $md);
    }
}
