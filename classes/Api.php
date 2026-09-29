<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * The Admin2 endpoints under /api/v1/gdrive (routes in gdrive.php). Only
 * loaded when the api plugin dispatches to it, so the library itself never
 * depends on the api plugin. Every route needs api.gdrive.manage (API super
 * users pass). Responses never carry a secret: status(), test() and the last
 * test file hold type, email, scopes and reasons only.
 *
 * @internal
 */
final class Api extends AbstractApiController
{
    public const PERMISSION = 'api.gdrive.manage';

    /** GET /gdrive/accounts */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        return $this->guard(fn (): ResponseInterface => ApiResponse::create($this->view()));
    }

    /** POST /gdrive/accounts {name, type, json}: validate and store the credential, record the account in config. */
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        try {
            $body = Setup::accountBody($this->getRequestBody($request));
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }

        return $this->guard(function () use ($body): ResponseInterface {
            $accounts = Gdrive::accounts();
            $accounts->saveCredential($body['name'], $body['type'], $body['json']);
            @unlink($this->testFile($body['name'])); // it described the old credential
            $this->persist($accounts->config());

            return ApiResponse::create($this->view(), 201);
        });
    }

    /** DELETE /gdrive/accounts/{name}: revoke (OAuth), delete the files, drop it from config. */
    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $name = $this->name($request);

        return $this->guard(function () use ($name): ResponseInterface {
            $accounts = Gdrive::accounts();
            $accounts->type($name);
            $accounts->remove($name);
            @unlink($this->testFile($name));
            $this->persist($accounts->config());

            return ApiResponse::create($this->view());
        });
    }

    /** POST /gdrive/accounts/{name}/test: a real about.get with the declared scopes; the result is kept for the page. */
    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $name = $this->name($request);

        return $this->guard(function () use ($name): ResponseInterface {
            $accounts = Gdrive::accounts();
            $accounts->type($name);
            $result = $accounts->test($name, Setup::declaredScopes(Gdrive::scopes(), $name)) + ['at' => date('c')];
            if (isset($result['message'])) {
                $result['message'] = (string) preg_replace('/^gdrive: /', '', $result['message']);
            }
            Accounts::writeSecret($this->testFile($name), (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ApiResponse::create(['result' => $result] + $this->view());
        });
    }

    /** POST /gdrive/accounts/{name}/connect: Google's consent URL for every declared and already granted scope. */
    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        $name = $this->name($request);
        $username = (string) $this->getUser($request)->get('username');

        return $this->guard(function () use ($name, $username): ResponseInterface {
            $accounts = Gdrive::accounts();
            $scopes = array_values(array_unique([...Setup::declaredScopes(Gdrive::scopes(), $name), ...$accounts->status($name)['scopes']]));

            return ApiResponse::create(['url' => $accounts->startConnect($name, $scopes ?: [Drive::SCOPE_FILE], Gdrive::redirectUri(), $username)]);
        });
    }

    /** GET /gdrive/guide?kind=&method=&shared_drive=&admin=&project=: the Guided setup steps as HTML plus the suggested account name (422 on anything off the whitelist). */
    public function guide(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);
        try {
            $profile = Setup::guideProfile($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }

        return ApiResponse::create([
            'html' => Setup::guided($profile),
            'method' => $profile['method'],
            'tags' => Setup::guideTags($profile, Gdrive::scopes()),
            'account' => Setup::suggestAccount(Gdrive::scopes(), Setup::existingNames(), $profile['method']),
        ]);
    }

    /** @return array{accounts: array, redirect_uri: string, wanted: string[]} */
    private function view(): array
    {
        $accounts = Gdrive::accounts();
        $declarations = Gdrive::scopes();

        return [
            'accounts' => Setup::rows($accounts, $declarations, Setup::dataDir()),
            'redirect_uri' => Gdrive::redirectUri(),
            // accounts some plugin wants that don't exist yet, so the page can offer to add them
            'wanted' => array_values(array_diff(array_unique(array_column($declarations, 'account')), $accounts->names())),
        ];
    }

    private function name(ServerRequestInterface $request): string
    {
        $name = (string) $this->getRouteParam($request, 'name');
        if (preg_match(Accounts::NAME, $name) !== 1) {
            throw new NotFoundException('No Google Drive account by that name.');
        }

        return $name;
    }

    private function testFile(string $name): string
    {
        return Setup::dataDir() . "/{$name}.test.json";
    }

    /**
     * Writes plugins.gdrive.accounts to user/config/plugins/gdrive.yaml,
     * keeping whatever else the file holds, and updates the live config.
     * ponytail: always the base user/config; an environment overlay
     * (user/env/<env>/config/plugins/gdrive.yaml) that sets accounts would
     * shadow it. Write through the env if a site ever needs per-env accounts.
     */
    private function persist(array $config): void
    {
        $accounts = (array) ($config['accounts'] ?? []);
        $dir = $this->grav['locator']->findResource('user://config', true);
        if (!is_string($dir) || $dir === '') {
            throw new DriveException('gdrive: user/config not found', 'io');
        }
        $file = YamlFile::instance($dir . '/plugins/gdrive.yaml');
        try {
            $data = (array) $file->content();
            $data['accounts'] = $accounts;
            $file->save($data);
        } catch (\Throwable $e) {
            throw new DriveException('gdrive: cannot write user/config/plugins/gdrive.yaml', 'io', 0, $e);
        } finally {
            $file->free();
        }
        $this->config->set('plugins.gdrive.accounts', $accounts);
    }

    /** Runs $fn, turning a DriveException into problem+json with `code` = reason and the Troubleshooting `anchor`. */
    private function guard(callable $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (DriveException $e) {
            $status = match ($e->reason) {
                'unknown_account' => 404,
                'io' => 500,
                'transport' => 502,
                default => 422,
            };
            $body = [
                'status' => $status,
                'title' => 'Google Drive',
                'detail' => (string) preg_replace('/^gdrive: /', '', $e->getMessage()),
                'code' => $e->reason,
                'anchor' => $e->anchor(),
            ];

            return new Response($status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store, max-age=0'], (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
