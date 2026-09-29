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
    /** What each Drive scope lets a plugin do, in words, for the notice on consumer plugins' settings pages. */
    public const SCOPE_MEANING = [
        'https://www.googleapis.com/auth/drive.file' => 'it can see and change only the files and folders it creates itself; the rest of the Drive stays invisible to it',
        'https://www.googleapis.com/auth/drive.readonly' => 'it can read every file the account can see, but can\'t change or delete anything',
        'https://www.googleapis.com/auth/drive' => 'it can read and change every file the account can see, which it needs to use a folder you picked yourself',
    ];
    /** When docs/setup/ was last checked against Google's console. Bump it whenever the guides are revised. */
    public const GUIDES_REVISED = '2026-09-29';
    /** The largest credential JSON the endpoint accepts; Google's are ~2.5 KB. */
    public const MAX_JSON = 65536;
    /** Guide file → settings-page tab (blueprints.yaml keys; Admin2 selects a tab by `#<key>`). */
    private const TABS = ['start-here' => 'start', 'service-account' => 'service_account', 'oauth' => 'oauth', 'troubleshooting' => 'troubleshooting'];
    private const SCOPE_PREFIX = 'https://www.googleapis.com/auth/';
    private const TYPE_LABEL = ['service_account' => 'Service account', 'oauth' => 'OAuth'];
    /** Google Cloud project IDs: 6–30 chars, lowercase letter first, no trailing hyphen. */
    public const PROJECT_ID = '/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/';
    /** `<!-- only: a,b+c -->` … `<!-- /only -->`, each marker on its own line (indent allowed, for list items). */
    private const ONLY = '/^[ \t]*<!--\s*only:\s*([a-z0-9+, -]*?)\s*-->[ \t]*\R(.*?)^[ \t]*<!--\s*\/only\s*-->[ \t]*(?:\R|$)/ms';
    private const MARKER = '/^[ \t]*<!--\s*\/?only\b.*?-->[ \t]*(?:\R|$)/m';
    /** Admin2's display field has Tailwind's preflight (padding 0) and no table CSS; inline style survives its DOMPurify. */
    private const CELL = 'padding:6px 14px;vertical-align:top;text-align:left;border-bottom:1px solid rgba(127,127,127,.3)';

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
        $link = 'Set up Google Drive access in **Plugins → Google Drive Auth**.';
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
                $lines[] = sprintf('Uses Google Drive account **%s** with %s access: %s', self::clean($d['account']), $scopes, self::verdict($row ?: null, $d['scopes']))
                    . self::scopeMeaning($d['scopes']);
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

        return self::padTables(self::render((string) preg_replace(self::MARKER, '', $md), self::values()));
    }

    /**
     * The Guided setup tab's steps, as HTML: a one-line summary of the choice,
     * the method's guide filtered to this profile (filterGuide), and what to do
     * on the Accounts tab afterwards. Only our own markdown is rendered (Parsedown
     * safe mode); the profile is whitelisted, and the project ID (matching
     * PROJECT_ID) is the only input that reaches the output.
     *
     * @internal
     * @param array<string, mixed> $profile kind, method, shared_drive, admin, project (see guideProfile)
     */
    public static function guided(array $profile): string
    {
        try {
            $p = self::guideProfile($profile);
        } catch (\InvalidArgumentException $e) {
            return '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
        }
        try {
            $declarations = Gdrive::scopes();
        } catch (\Throwable) {
            $declarations = [];
        }
        $tags = self::guideTags($p, $declarations);
        $md = @file_get_contents(dirname(__DIR__) . '/docs/setup/' . ($p['method'] === 'sa' ? 'service-account' : 'oauth') . '.md');
        if ($md === false) {
            return '<p>The setup guide is missing from this install of the plugin.</p>';
        }
        $md = self::render(self::intro($p, $tags) . "\n\n" . self::filterGuide($md, $tags) . "\n\n" . self::then($p['method'], $declarations, self::suggestAccount($declarations, self::existingNames(), $p['method'])), self::values());

        return self::html($p['project'] !== '' ? self::withProject($md, $p['project']) : $md);
    }

    /**
     * Keeps a `<!-- only: … -->` block when any comma-separated entry is
     * active; an entry is `tag`, `not-tag` (active when tag isn't) or `a+b`
     * (all of them). Drops every marker line. No nesting. Pure.
     *
     * @internal
     * @param string[] $tags
     */
    public static function filterGuide(string $md, array $tags): string
    {
        $on = static fn (string $t): bool => in_array($t, $tags, true) || (str_starts_with($t, 'not-') && !in_array(substr($t, 4), $tags, true));
        $md = (string) preg_replace_callback(self::ONLY, static function (array $m) use ($on): string {
            foreach (explode(',', $m[1]) as $any) {
                if (array_filter(explode('+', trim($any)), static fn (string $t): bool => !$on(trim($t))) === []) {
                    return $m[2];
                }
            }

            return '';
        }, $md);

        return (string) preg_replace(self::MARKER, '', $md);
    }

    /**
     * Checks GET /gdrive/guide's query against fixed whitelists and fills the
     * defaults: OAuth unless a method is given, and admin "no" for Workspace.
     * Unknown keys are ignored. Throws \InvalidArgumentException, never
     * echoing the input. Pure.
     *
     * @internal
     * @param array<string, mixed> $q
     * @return array{kind: string, method: string, shared_drive: string, admin: string, project: string}
     */
    public static function guideProfile(array $q): array
    {
        $pick = static function (string $key, array $allowed) use ($q): string {
            $v = $q[$key] ?? '';
            if (!is_string($v) || !in_array($v, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('"%s" must be one of: %s%s.', $key, implode(', ', array_filter($allowed)), in_array('', $allowed, true) ? ', or empty' : ''));
            }

            return $v;
        };
        $kind = $pick('kind', ['gmail', 'workspace']);
        $work = $kind === 'workspace';
        $shared = $pick('shared_drive', ['', 'yes', 'no', 'unsure']);
        $admin = $pick('admin', ['', 'yes', 'no']);
        $method = $pick('method', ['', 'oauth', 'sa']);
        $project = $q['project'] ?? '';
        if (!is_string($project) || ($project !== '' && preg_match(self::PROJECT_ID, $project) !== 1)) {
            throw new \InvalidArgumentException('"project" must be a Google Cloud project ID: 6–30 lowercase letters, digits and hyphens, starting with a letter.');
        }

        return [
            'kind' => $kind,
            'method' => $method ?: 'oauth',
            'shared_drive' => $work ? $shared : '',
            'admin' => $work ? ($admin ?: 'no') : '',
            'project' => $project,
        ];
    }

    /**
     * The filterGuide tags for a checked profile. `backup` and `gallery` come
     * from what plugins declared (drive.file or drive; drive.readonly); with
     * nothing declared, both. Pure.
     *
     * @internal
     * @param array{kind: string, method: string, shared_drive: string, admin: string, project: string} $p
     * @param array<int, array{plugin: string, account: string, scopes: string[]}> $declarations
     * @return string[]
     */
    public static function guideTags(array $p, array $declarations): array
    {
        $scopes = array_merge([], ...array_column($declarations, 'scopes'));
        $write = array_intersect($scopes, [Drive::SCOPE_FILE, Drive::SCOPE_FULL]) !== [];
        $read = in_array(Drive::SCOPE_READONLY, $scopes, true);
        $tags = [$p['kind'], $p['method']];
        if ($p['shared_drive'] === 'yes') {
            $tags[] = 'shared-drive';
        }
        if ($p['kind'] === 'workspace') {
            $tags[] = $p['admin'] === 'yes' ? 'admin' : 'not-admin';
        }
        if ($p['project'] !== '') {
            $tags[] = 'project';
        }
        if ($write || !$read) {
            $tags[] = 'backup';
        }
        if ($read || !$write) {
            $tags[] = 'gallery';
        }

        return $tags;
    }

    /**
     * Adds `project=<id>` to every Cloud console URL so each link opens that
     * project, merging with an existing query and keeping any #fragment.
     * Other URLs are left alone. Pure.
     *
     * @internal
     */
    public static function withProject(string $text, string $project): string
    {
        if (preg_match(self::PROJECT_ID, $project) !== 1) {
            return $text;
        }

        return (string) preg_replace_callback('~https://console\.cloud\.google\.com(?![\w.-])[^\s)<>"\'`\]]*~', static function (array $m) use ($project): string {
            [$url, $frag] = array_pad(explode('#', $m[0], 2), 2, null);
            if (preg_match('/[?&]project=/', $url) === 1) {
                return $m[0];
            }
            $sep = str_contains($url, '?') ? (str_ends_with($url, '?') || str_ends_with($url, '&') ? '' : '&') : '?';

            return $url . $sep . 'project=' . $project . ($frag !== null ? '#' . $frag : '');
        }, $text);
    }

    /**
     * Pipe tables → HTML tables with padded cells, for Admin2's display field
     * (see CELL); GitHub keeps rendering the markdown. $inline renders one
     * cell's markdown. Pure.
     * ponytail: splits cells on every `|`, so a cell can't contain one (not
     * even in `code`); none of the guides needs it.
     *
     * @internal
     * @param callable(string): string $inline
     */
    public static function tables(string $md, callable $inline): string
    {
        return (string) preg_replace_callback('/^\|.*\|[ \t]*\R\|[ \t:|-]+\|[ \t]*(?:\R\|.*\|[ \t]*)*(?:\R|$)/m', static function (array $m) use ($inline): string {
            $lines = preg_split('/\R/', trim($m[0])) ?: [];
            $row = static fn (string $line, string $tag): string => '<tr>' . implode('', array_map(
                static fn (string $c): string => sprintf('<%1$s style="%2$s">%3$s</%1$s>', $tag, self::CELL . ($tag === 'th' ? ';font-weight:600' : ''), $inline(trim($c))),
                explode('|', trim(trim($line), '|'))
            )) . '</tr>';
            $body = implode('', array_map(static fn (string $l): string => $row($l, 'td'), array_slice($lines, 2)));

            return '<table style="border-collapse:collapse;margin:0.75em 0"><thead>' . $row($lines[0], 'th') . '</thead><tbody>' . $body . "</tbody></table>\n";
        }, $md);
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

        return self::safe(self::padTables($out));
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
            $row['missing'] = $row['type'] === 'oauth' && $row['connected'] ? OAuthUser::missingScopes($row['scopes'], self::declaredScopes($declarations, $name)) : [];
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
     * @param array<int, array{name: string, type: string, email: ?string, connected: bool, has_credential?: bool}> $statuses
     * @return array<string, string>
     */
    public static function options(array $statuses): array
    {
        $out = [];
        // No emails: Admin2 fetches these through /data/resolve, which page editors
        // (api.pages.read) can call too. The Accounts tab shows who each one is.
        foreach ($statuses as $s) {
            $state = match (true) {
                $s['type'] === 'oauth' => !empty($s['connected']) ? 'connected' : 'not connected',
                empty($s['has_credential']) => 'no key yet',
                default => '',
            };
            $type = self::TYPE_LABEL[$s['type']] ?? $s['type'];
            $out[$s['name']] = sprintf('%s (%s)', $s['name'], $state === '' ? $type : "{$type}, {$state}");
        }

        return $out;
    }

    /**
     * @internal
     * @return string[] names of the accounts that exist (none if the registry can't load)
     */
    public static function existingNames(): array
    {
        try {
            return Gdrive::accounts()->names();
        } catch (\Throwable) {
            return [];
        }
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
     * @param array{kind: string, method: string, shared_drive: string, admin: string, project: string} $p
     * @param string[] $tags
     */
    private static function intro(array $p, array $tags): string
    {
        $who = $p['kind'] === 'gmail' ? 'a personal Google account' : 'a Google Workspace account';
        $how = $p['method'] === 'oauth'
            ? "You'll set up an **OAuth** account for {$who}: the site acts as that account, and the files it creates are yours. "
                . ($p['kind'] === 'gmail' ? "You'll publish your Google app, so the connection doesn't expire after 7 days." : 'An **Internal** app keeps it simple: no 7-day expiry and no warning screen.')
            : "You'll set up a **service account** for {$who}: a Google identity of its own, with a key file that never expires. "
                . (in_array('shared-drive', $tags, true) ? 'It writes into a Shared Drive you add it to.' : 'Without a Shared Drive it can only read folders you share with it.');
        $needs = array_filter([in_array('gallery', $tags, true) ? 'reading shared folders (galleries)' : '', in_array('backup', $tags, true) ? 'writing files (backups)' : '']);

        // Only here, not in the markdown: the full guides have no project to point at.
        $project = $p['project'] !== '' ? " The console links below already open the project you named (`{$p['project']}`)." : '';

        $revised = (\DateTimeImmutable::createFromFormat('!Y-m-d', self::GUIDES_REVISED) ?: new \DateTimeImmutable())->format('j F Y');

        return $how . "\n\nThese steps cover " . implode(' and ', $needs) . '. The full guides are on the other tabs.' . $project
            . " They were last revised on {$revised}. Google changes its console from time to time, so a page or button may look a little different from what's described.";
    }

    /**
     * The account name the Guided setup pre-fills: the first name plugins
     * declared that doesn't exist yet, else the first declared, else a default
     * per method. Only names matching Accounts::NAME are considered. Pure.
     *
     * @internal
     * @param array<int, array{plugin: string, account: string, scopes: string[]}> $declarations
     * @param string[] $existing
     */
    public static function suggestAccount(array $declarations, array $existing, string $method): string
    {
        $names = array_values(array_filter(array_column($declarations, 'account'), static fn (string $n): bool => preg_match(Accounts::NAME, $n) === 1));

        return current(array_diff($names, $existing)) ?: ($names[0] ?? ($method === 'sa' ? 'site' : 'personal'));
    }

    /** @param array<int, array{plugin: string, account: string, scopes: string[]}> $declarations */
    private static function then(string $method, array $declarations, string $account): string
    {
        $out = "### Then\n\n1. On the [Accounts](#accounts_tab) tab, add an account named **" . self::clean($account) . '**'
            . ': choose **' . ($method === 'sa' ? 'Service account' : 'OAuth') . "** and upload the JSON file from above.\n"
            . '2. ' . ($method === 'sa' ? 'Click' : 'Click **Connect**, then click') . " **Test**. A ✘ links to its fix in [Troubleshooting](troubleshooting.md).\n"
            . "3. In each Drive plugin's settings, pick the account" . ($declarations === [] ? " by that name.\n" : ":\n");
        foreach ($declarations as $d) {
            $out .= sprintf("   - **%s** is set to an account named **%s**. Name yours the same, or change it in that plugin's settings.\n", self::clean($d['plugin']), self::clean($d['account']));
        }

        return $out;
    }

    /** Our own markdown → HTML; safe mode escapes any raw HTML. Without Grav (tests) it's shown escaped. */
    private static function html(string $md): string
    {
        if (!class_exists(\ParsedownExtra::class)) {
            return '<pre>' . htmlspecialchars($md, ENT_QUOTES) . '</pre>';
        }
        $parser = new \ParsedownExtra();
        $parser->setSafeMode(true);

        return (string) $parser->text($md);
    }

    /** tables() with Parsedown for the cells; the markdown unchanged where Parsedown isn't loaded (tests). */
    private static function padTables(string $md): string
    {
        if (!class_exists(\Parsedown::class)) {
            return $md;
        }
        $parser = new \Parsedown();
        $parser->setSafeMode(true);

        return self::tables($md, static fn (string $cell): string => $parser->line($cell));
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
            $row['type'] === 'oauth' && OAuthUser::missingScopes($row['scopes'], $scopes) !== [] => '✘ not granted yet: click Reconnect',
            $row['test'] !== null && !($row['test']['ok'] ?? false) => '✘ last test failed: `' . self::clean((string) ($row['test']['reason'] ?? 'error')) . '`',
            default => '✔ ready',
        };
    }

    /** @param string[] $scopes */
    /**
     * "  \n`drive.file` is Google's name for this permission (a scope): it can …" — one
     * line per known scope, empty for unknown ones. Pure.
     *
     * @param string[] $scopes
     */
    public static function scopeMeaning(array $scopes): string
    {
        $out = '';
        foreach ($scopes as $s) {
            if (isset(self::SCOPE_MEANING[$s])) {
                $out .= "  \n`" . str_replace(self::SCOPE_PREFIX, '', $s) . "` is Google's name for this permission (a *scope*): " . self::SCOPE_MEANING[$s] . '.';
            }
        }

        return $out;
    }

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
