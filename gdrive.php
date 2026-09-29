<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\Gdrive\DriveException;
use Grav\Plugin\Gdrive\Gdrive;

/**
 * Shared Google Drive library. Its only route is the public OAuth callback;
 * everything else is classes other plugins call through Gdrive::drive().
 */
class GdrivePlugin extends Plugin
{
    public const VERSION = '0.1.0';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 1000],
            ],
        ];
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
