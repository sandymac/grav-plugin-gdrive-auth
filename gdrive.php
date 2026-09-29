<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Gdrive\Api;
use Grav\Plugin\Gdrive\DriveException;
use Grav\Plugin\Gdrive\Gdrive;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\AbstractFile;

/**
 * Shared Google Drive library. Its only front-end route is the public OAuth
 * callback; the settings page talks to the Admin2 endpoints in Gdrive\Api;
 * everything else is classes other plugins call through Gdrive::drive().
 */
class GdrivePlugin extends Plugin
{
    public const VERSION = '0.1.7';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 1000],
            ],
            // Unconditional: on API requests isAdmin() is still false here, and
            // the api plugin fires this only when it rebuilds its route cache.
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            'onAdminSave' => ['onAdminSave', 0],
        ];
    }

    /** Handlers are [class, method] so the api plugin can cache the route table. */
    public function onApiRegisterRoutes(Event $event): void
    {
        $event['routes']->group('/gdrive', static function ($r): void {
            $r->get('/accounts', [Api::class, 'list']);
            $r->post('/accounts', [Api::class, 'save']);
            $r->delete('/accounts/{name}', [Api::class, 'remove']);
            $r->post('/accounts/{name}/test', [Api::class, 'test']);
            $r->post('/accounts/{name}/connect', [Api::class, 'connect']);
            $r->get('/guide', [Api::class, 'guide']);
        });
    }

    /** Core doesn't read plugin permissions.yaml by itself; this puts api.gdrive.manage in the Users/Groups editor. */
    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $event->permissions->addActions(PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml"));
    }

    /**
     * Admin2 posts the whole config it loaded when the settings form is saved,
     * accounts included. The endpoints own that key, so a stale copy from a
     * page opened before an account was added or removed must not win.
     */
    public function onAdminSave(Event $event): void
    {
        $obj = $event['object'] ?? null;
        $file = $obj instanceof Data ? $obj->file() : null;
        if ($file instanceof AbstractFile && str_ends_with(str_replace('\\', '/', (string) $file->filename()), '/plugins/gdrive.yaml')) {
            $obj->set('accounts', (array) $this->config->get('plugins.gdrive.accounts', []));
        }
    }

    public function autoload(): void
    {
        self::registerAutoload();
    }

    /** Idempotent spl fallback so a git clone into user/plugins works without Composer. */
    private static function registerAutoload(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\Gdrive\\';
            if (str_starts_with($class, $prefix)) {
                $path = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            }
        });
    }

    /** Admin2 is API-driven, so there's nothing to skip for admin; only the callback path is intercepted. */
    public function onPluginsInitialized(): void
    {
        // Admin2 resolves data-options@ through the API's /data/resolve, which only
        // calls allowlisted providers. accountOptions() is read-only and carries no emails.
        \Grav\Common\Data\Blueprint::addAllowedDynamicCallable(\Grav\Plugin\Gdrive\Setup::class . '::accountOptions');

        if ((string) $this->grav['uri']->path() === Gdrive::CALLBACK) {
            $this->enable(['onPagesInitialized' => ['callback', 100000]]);
        }
    }

    /**
     * Google's redirect after consent. It carries no admin login (Admin2 uses
     * an in-memory bearer token), so the single-use server-side state is the
     * only thing trusted. Failures get a generic 400; the reason goes to the log.
     */
    public function callback(): void
    {
        $uri = $this->grav['uri'];
        try {
            $error = $uri->query('error');
            if (is_string($error) && $error !== '') {
                throw new DriveException('Google returned error ' . substr((string) preg_replace('/[^\w.-]/', '', $error), 0, 64), 'consent');
            }
            $status = Gdrive::accounts()->finishConnect((string) ($uri->query('state') ?? ''), (string) ($uri->query('code') ?? ''));
            $this->grav['log']->info(sprintf('gdrive: account %s connected as %s by %s', $status['name'], (string) $status['email'], $status['username']));
            $this->respond(200, 'Connected as ' . htmlspecialchars((string) $status['email'], ENT_QUOTES) . '. You can close this window.', $status['name']);
        } catch (\Throwable $e) {
            $this->grav['log']->warning('gdrive: OAuth callback refused: ' . $e->getMessage());
            $this->respond(400, 'Google Drive could not be connected. Close this window and click Connect again.', null);
        }
    }

    /** A tiny self-contained page; on success it tells the opener (same origin only) to refresh. */
    private function respond(int $code, string $html, ?string $account): never
    {
        $nonce = base64_encode(random_bytes(16));
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer'); // the URL carried the code and state
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $script = $account === null ? '' : sprintf(
            '<script nonce="%s">window.opener && window.opener.postMessage({gdrive: "connected", account: %s}, location.origin);</script>',
            $nonce,
            json_encode($account, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        );
        echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>Google Drive</title></head><body><p>{$html}</p>{$script}</body></html>";
        exit;
    }
}
