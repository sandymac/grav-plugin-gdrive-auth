<?php

declare(strict_types=1);

/**
 * Smallest checks that fail if the JWT signer, PKCE, the OAuth state store,
 * scope enforcement, the retry/401 logic, the upload request shape, error
 * parsing or account-name validation breaks. Fake transports only; no network.
 * Run: php tests/smoke.php   (no Grav install needed)
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Grav\\Plugin\\Gdrive\\';
    if (str_starts_with($class, $prefix) && is_file($path = __DIR__ . '/../classes/' . substr($class, strlen($prefix)) . '.php')) {
        require $path;
    }
});

use Grav\Plugin\Gdrive\Accounts;
use Grav\Plugin\Gdrive\Credentials;
use Grav\Plugin\Gdrive\Drive;
use Grav\Plugin\Gdrive\DriveException;
use Grav\Plugin\Gdrive\Http;
use Grav\Plugin\Gdrive\OAuthUser;
use Grav\Plugin\Gdrive\ServiceAccount;
use Grav\Plugin\Gdrive\Setup;

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
}

/** Runs $fn and returns the DriveException reason it threw, or null. */
function reason(callable $fn): ?string
{
    try {
        $fn();
    } catch (DriveException $e) {
        return $e->reason;
    }

    return null;
}

$b64d = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));
$b64u = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

// --- JWT: signs with the private key, verifies with the public one, carries the right claims.
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
check($key !== false, 'openssl can generate a test key');
openssl_pkey_export($key, $pem);
$pub = openssl_pkey_get_details($key)['key'];
$sa = ['type' => 'service_account', 'client_email' => 'svc@example.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token'];

$jwt = ServiceAccount::jwt($sa, [Drive::SCOPE_READONLY, Drive::SCOPE_FILE], 1_700_000_000);
[$h, $c, $s] = explode('.', $jwt);
check(json_decode($b64d($h), true) === ['alg' => 'RS256', 'typ' => 'JWT'], 'JWT header is RS256');
$claims = json_decode($b64d($c), true);
check($claims['iss'] === $sa['client_email'] && $claims['aud'] === $sa['token_uri'], 'JWT claims carry iss/aud');
check($claims['scope'] === Drive::SCOPE_READONLY . ' ' . Drive::SCOPE_FILE, 'JWT scope is the space-joined scope list');
check($claims['iat'] === 1_700_000_000 && $claims['exp'] === 1_700_003_600, 'JWT lives exactly one hour');
check(openssl_verify("{$h}.{$c}", $b64d($s), $pub, OPENSSL_ALGO_SHA256) === 1, 'JWT signature verifies against the public key');
check(!str_contains($jwt, '=') && !str_contains($jwt, '+') && !str_contains($jwt, '/'), 'JWT uses unpadded base64url');

$exchanges = 0;
$saHttp = static function (string $method, string $url, array $opts) use (&$exchanges): array {
    parse_str($opts['body'], $post);
    check($method === 'POST' && $post['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count($post['assertion'], '.') === 2, 'SA token request is a JWT-bearer grant');
    $exchanges++;

    return [200, json_encode(['access_token' => 'sa-tok', 'expires_in' => 3600]), []];
};
$svc = new ServiceAccount(ServiceAccount::validateKey($sa), null, $saHttp);
check($svc->token([Drive::SCOPE_FILE]) === 'sa-tok' && $svc->token([Drive::SCOPE_FILE]) === 'sa-tok' && $exchanges === 1, 'SA token is memoised per scope set');
$svc->forget([Drive::SCOPE_FILE]);
$svc->token([Drive::SCOPE_FILE]);
check($exchanges === 2, 'forget() forces a fresh exchange');

// --- PKCE: 43+ char base64url verifier, S256 challenge.
$pkce = OAuthUser::pkce();
check(preg_match('/^[A-Za-z0-9_-]{43,128}$/', $pkce['verifier']) === 1, 'PKCE verifier is 43+ base64url chars');
check($pkce['challenge'] === $b64u(hash('sha256', $pkce['verifier'], true)), 'PKCE challenge = base64url(sha256(verifier))');
check(OAuthUser::pkce()['verifier'] !== $pkce['verifier'], 'PKCE verifiers are random');

// --- DriveException parses Google's error shapes.
$e = DriveException::fromResponse(403, (string) json_encode(['error' => ['code' => 403, 'message' => 'The user\'s Drive storage quota has been exceeded.', 'errors' => [['reason' => 'storageQuotaExceeded']]]]), 'POST /files');
check($e->reason === 'storageQuotaExceeded' && $e->status === 403 && $e->anchor() === 'storage-quota-exceeded', 'Drive error reason and anchor');
$e = DriveException::fromResponse(400, '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}', 'token refresh');
check($e->reason === 'invalid_grant' && $e->anchor() === 'invalid-grant' && str_contains($e->getMessage(), 'expired or revoked'), 'OAuth error reason');
check(DriveException::fromResponse(403, '{"error":{"status":"PERMISSION_DENIED"}}', 'x')->anchor() === 'permission-denied', 'error.status fallback');
check(DriveException::fromResponse(502, '<html>', 'x')->reason === '', 'non-JSON body leaves reason empty');

// --- Http::withRetry: retries 503 and transport errors, not 404, never a streamed request.
$slept = [];
$sleep = static function (float $s) use (&$slept): void {
    $slept[] = $s;
};
$script = static function (array $responses) use (&$calls): callable {
    return static function () use (&$responses, &$calls): array {
        $calls++;
        $next = array_shift($responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    };
};
$calls = 0;
$r = Http::withRetry($script([[503, '', []], [200, 'ok', []]]), 4, $sleep)('GET', 'https://x', []);
check($r[0] === 200 && $calls === 2 && count($slept) === 1, 'withRetry retries a 503 then returns the success');
$calls = 0;
check(Http::withRetry($script([[404, '', []], [200, '', []]]), 4, $sleep)('GET', 'https://x', [])[0] === 404 && $calls === 1, 'withRetry does not retry a 404');
$calls = 0;
check(Http::withRetry($script([new DriveException('reset', 'transport'), [200, '', []]]), 4, $sleep)('GET', 'https://x', [])[0] === 200 && $calls === 2, 'withRetry retries a transport error');
$calls = 0;
check(Http::withRetry($script(array_fill(0, 9, [503, '', []])), 3, $sleep)('GET', 'https://x', [])[0] === 503 && $calls === 3, 'withRetry gives up after $tries');
$calls = 0;
$sink = fopen('php://memory', 'w+');
check(Http::withRetry($script([[503, '', []], [200, '', []]]), 4, $sleep)('GET', 'https://x', ['sink' => $sink])[0] === 503 && $calls === 1, 'withRetry never replays a streamed request');
check($slept[0] >= 1 && $slept[0] <= 2, 'first backoff is 1s plus jitter');

// --- Drive: 401 → forget → one retry; upload is a resumable POST then one streamed PUT.
$fakeCreds = new class () implements Credentials {
    public int $forgot = 0;

    public function token(array $scopes): string
    {
        return 'tok' . $this->forgot;
    }

    public function email(): string
    {
        return 'me@example.com';
    }

    public function forget(array $scopes): void
    {
        $this->forgot++;
    }
};
$seen = [];
$drive = new Drive($fakeCreds, [Drive::SCOPE_FILE], static function (string $method, string $url, array $opts) use (&$seen): array {
    $seen[] = [$method, $url, $opts];

    return in_array('Authorization: Bearer tok0', $opts['headers'], true) ? [401, '{"error":{"code":401}}', []] : [200, '{"kind":"drive#about"}', []];
});
check($drive->about() === ['kind' => 'drive#about'] && $fakeCreds->forgot === 1 && count($seen) === 2, 'request() forgets the token on 401 and retries once');
check(str_contains($seen[0][1], 'supportsAllDrives=true'), 'every call sends supportsAllDrives=true');

$tmp = sys_get_temp_dir() . '/gdrive-smoke-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/site--20260928120000.zip', str_repeat('z', 1000));
$seen = [];
$drive = new Drive($fakeCreds, [Drive::SCOPE_FILE], static function (string $method, string $url, array $opts) use (&$seen): array {
    $seen[] = [$method, $url, $opts + ['bytes' => isset($opts['infile']) ? stream_get_contents($opts['infile']) : null]];
    if ($method === 'POST') {
        return [200, '', ['location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=SESSION']];
    }

    return [200, (string) json_encode(['id' => 'NEWFILE', 'md5Checksum' => md5(str_repeat('z', 1000)), 'size' => '1000']), []];
});
$file = $drive->upload($tmp . '/site--20260928120000.zip', 'FOLDER1', 'site--20260928120000.zip', ['grav_backup' => '1']);
check($file['id'] === 'NEWFILE' && count($seen) === 2, 'upload returns the file resource after two calls');
[$m, $u, $o] = $seen[0];
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
check($m === 'POST' && str_starts_with($u, Drive::UPLOAD_API . '/files?') && $q['uploadType'] === 'resumable' && $q['supportsAllDrives'] === 'true' && $q['fields'] === 'id,name,md5Checksum,size', 'upload opens a resumable session');
check(json_decode($o['body'], true) === ['name' => 'site--20260928120000.zip', 'parents' => ['FOLDER1'], 'appProperties' => ['grav_backup' => '1']], 'session metadata carries name, parent, appProperties');
check(in_array('X-Upload-Content-Length: 1000', $o['headers'], true) && in_array('X-Upload-Content-Type: application/zip', $o['headers'], true), 'session announces length and type');
[$m, $u, $o] = $seen[1];
check($m === 'PUT' && $u === 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=SESSION', 'upload PUTs to the session URI unchanged');
check(array_key_exists('infile', $o) && $o['infile_size'] === 1000 && $o['bytes'] === str_repeat('z', 1000) && !isset($o['body']), 'PUT streams the whole file via infile, not a body string');
check(reason(static fn () => (new Drive($fakeCreds, [], static fn (): array => [403, '{"error":{"errors":[{"reason":"storageQuotaExceeded"}]}}', []]))->upload($tmp . '/site--20260928120000.zip', 'F', 'x.zip')) === 'storageQuotaExceeded', 'a refused upload session throws with Google\'s reason');

// --- Accounts: names are filenames, credentials are checked by kind.
$client = ['web' => ['client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'shh', 'redirect_uris' => ['https://example.com/gdrive-oauth/callback']]];
$googleCalls = [];
$google = static function (string $method, string $url, array $opts) use (&$googleCalls): array {
    parse_str($opts['body'] ?? '', $post);
    $googleCalls[] = [$url, $post];
    if (str_ends_with($url, '/token') && ($post['grant_type'] ?? '') === 'authorization_code') {
        check($post['code_verifier'] !== '' && $post['client_secret'] === 'shh' && $post['redirect_uri'] === 'https://example.com/gdrive-oauth/callback', 'code exchange sends verifier, secret and the same redirect URI');

        return [200, (string) json_encode(['access_token' => 'fresh', 'refresh_token' => 'R1', 'expires_in' => 3599, 'scope' => Drive::SCOPE_FILE]), []];
    }
    if (str_ends_with($url, '/token') && ($post['grant_type'] ?? '') === 'refresh_token') {
        return $post['refresh_token'] === 'R1' ? [200, '{"access_token":"refreshed","expires_in":3599}', []] : [400, '{"error":"invalid_grant"}', []];
    }
    if (str_contains($url, '/about')) {
        return [200, '{"user":{"emailAddress":"me@gmail.com"}}', []];
    }
    if (str_contains($url, '/revoke')) {
        return [200, '', []];
    }

    return [404, '', []];
};
$accounts = new Accounts(['accounts' => ['personal' => ['type' => 'oauth']]], $tmp, null, $google);
check(reason(static fn () => $accounts->saveCredential('../x', 'oauth', (string) json_encode($client))) === 'bad_credential', 'account name ../x is refused');
check(reason(static fn () => $accounts->saveCredential('Big', 'oauth', (string) json_encode($client))) === 'bad_credential', 'uppercase account names are refused');
check(reason(static fn () => $accounts->status('../../etc')) === 'bad_credential', 'status() validates the name too');
check(reason(static fn () => $accounts->saveCredential('personal', 'oauth', (string) json_encode($sa))) === 'bad_credential', 'an SA key is refused for an OAuth account');
check(reason(static fn () => $accounts->saveCredential('site', 'service_account', (string) json_encode($client))) === 'bad_credential', 'an OAuth client is refused for a service account');
try {
    $accounts->saveCredential('personal', 'oauth', (string) json_encode(['installed' => $client['web']]));
    check(false, 'a Desktop client must be refused');
} catch (DriveException $e) {
    check(str_contains($e->getMessage(), 'Web application'), 'refusing a Desktop client says a Web application client is needed');
}
check(reason(static fn () => $accounts->saveCredential('site', 'service_account', '{not json')) === 'bad_credential', 'non-JSON is refused');
check(!is_file($tmp . '/site.sa.json') && !is_file($tmp . '/personal.client.json'), 'nothing is written for a refused credential');

$accounts->saveCredential('site', 'service_account', (string) json_encode($sa));
$accounts->saveCredential('personal', 'oauth', (string) json_encode($client));
check(is_file($tmp . '/site.sa.json') && is_file($tmp . '/personal.client.json') && glob($tmp . '/*.tmp') === [], 'credentials land under fixed names, no temp files left');
if (DIRECTORY_SEPARATOR === '/') {
    check((fileperms($tmp . '/site.sa.json') & 0777) === 0600, 'credential files are 0600');
}
check($accounts->config()['accounts'] === ['personal' => ['type' => 'oauth'], 'site' => ['type' => 'service_account']], 'saved accounts are reflected in config()');
check(Accounts::configWith([], 'x', 'oauth') === ['accounts' => ['x' => ['type' => 'oauth']]], 'configWith is pure and shaped');
$st = $accounts->status('site');
check($st['has_credential'] && $st['connected'] && $st['email'] === $sa['client_email'] && !str_contains((string) json_encode($st), 'PRIVATE KEY'), 'SA status shows the email and no secret');
$st = $accounts->status('personal');
check($st['has_credential'] && !$st['connected'] && $st['scopes'] === [], 'OAuth status before Connect');

// --- OAuth: not connected → Connect via single-use state → scope enforcement → refresh.
check(reason(static fn () => $accounts->credentials('personal')->token([Drive::SCOPE_FILE])) === 'not_connected', 'token() before Connect is not_connected');
$authUrl = $accounts->startConnect('personal', [Drive::SCOPE_FILE], 'https://example.com/gdrive-oauth/callback', 'admin');
parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $aq);
check(str_starts_with($authUrl, OAuthUser::AUTH_URI . '?'), 'auth URL defaults to Google\'s v2 endpoint');
check($aq['response_type'] === 'code' && $aq['access_type'] === 'offline' && $aq['prompt'] === 'consent' && $aq['include_granted_scopes'] === 'true' && $aq['code_challenge_method'] === 'S256' && $aq['scope'] === Drive::SCOPE_FILE && $aq['client_id'] === 'cid.apps.googleusercontent.com', 'auth URL carries offline, consent, PKCE S256 and the scopes');
check(strlen($aq['state']) === 64 && is_file($tmp . '/oauth-state/' . hash('sha256', $aq['state']) . '.json'), 'state is 32 random bytes, stored by its sha256');
check(!str_contains((string) file_get_contents($tmp . '/oauth-state/' . hash('sha256', $aq['state']) . '.json'), $aq['state']), 'the raw state is never written to disk');

$done = $accounts->finishConnect($aq['state'], 'CODE');
check($done['connected'] && $done['email'] === 'me@gmail.com' && $done['scopes'] === [Drive::SCOPE_FILE] && $done['username'] === 'admin', 'finishConnect saves the token and reports who connected');
check(reason(static fn () => $accounts->finishConnect($aq['state'], 'CODE')) === 'bad_state', 'a state works once only');
check(reason(static fn () => $accounts->finishConnect('nonsense', 'CODE')) === 'bad_state', 'an unknown state is refused');
$token = json_decode((string) file_get_contents($tmp . '/personal.token.json'), true);
check($token['refresh_token'] === 'R1' && $token['email'] === 'me@gmail.com' && $token['scopes'] === [Drive::SCOPE_FILE], 'token file holds refresh token, scopes, email');

$authUrl = $accounts->startConnect('personal', [Drive::SCOPE_FILE], 'https://example.com/gdrive-oauth/callback', 'admin');
parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $aq);
$stateFile = $tmp . '/oauth-state/' . hash('sha256', $aq['state']) . '.json';
$pending = json_decode((string) file_get_contents($stateFile), true);
$pending['expires'] = time() - 1;
file_put_contents($stateFile, json_encode($pending));
check(reason(static fn () => $accounts->finishConnect($aq['state'], 'CODE')) === 'bad_state' && !is_file($stateFile), 'an expired state is refused and consumed');

$oauth = $accounts->credentials('personal');
check(reason(static fn () => $oauth->token([Drive::SCOPE_FULL])) === 'scope_not_granted', 'token() refuses a scope the user did not grant');
check($oauth->token([Drive::SCOPE_FILE]) === 'refreshed', 'token() refreshes for a granted scope');
check($accounts->test('personal', [])['ok'] === true, 'test() runs about.get with the granted scopes');
$bad = (new Accounts(['accounts' => ['site' => ['type' => 'service_account']]], $tmp, null, static fn (): array => [400, '{"error":"invalid_grant"}', []]))->test('site', [Drive::SCOPE_FILE]);
check($bad['ok'] === false && $bad['reason'] === 'invalid_grant' && $bad['anchor'] === 'invalid-grant', 'test() reports the reason and anchor');

$accounts->remove('personal');
check(!is_file($tmp . '/personal.token.json') && !is_file($tmp . '/personal.client.json') && !isset($accounts->config()['accounts']['personal']), 'remove() deletes the files and the config entry');
check(count(array_filter($googleCalls, static fn (array $c): bool => str_contains($c[0], '/revoke') && $c[1]['token'] === 'R1')) === 1, 'remove() revokes the refresh token first');

// --- Setup: the settings page's rows, renderers and body validation (no Grav booted here).
$decl = [
    ['plugin' => 'gdrive-images', 'account' => 'site', 'scopes' => [Drive::SCOPE_READONLY]],
    ['plugin' => 'gdrive-backup', 'account' => 'site', 'scopes' => [Drive::SCOPE_FILE]],
    ['plugin' => 'gdrive-backup', 'account' => 'nope', 'scopes' => [Drive::SCOPE_FILE]],
];
check(Setup::declaredScopes($decl, 'site') === [Drive::SCOPE_READONLY, Drive::SCOPE_FILE], 'declaredScopes() unions one account\'s declarations');
$rows = Setup::rows($accounts, $decl, $tmp);
check(count($rows) === 1 && $rows[0]['name'] === 'site' && count($rows[0]['declared']) === 2 && $rows[0]['missing'] === [] && $rows[0]['test'] === null, 'rows(): status plus declarations, no test yet');
Accounts::writeSecret($tmp . '/site.test.json', (string) json_encode(['ok' => false, 'reason' => 'notFound', 'anchor' => 'not-found', 'message' => 'x', 'at' => 'now']));
$rows = Setup::rows($accounts, $decl, $tmp);
check(($rows[0]['test']['reason'] ?? '') === 'notFound', 'rows() includes the last test result');
check(!str_contains((string) json_encode($rows), 'PRIVATE KEY') && !str_contains((string) json_encode($rows), 'private_key'), 'rows() carry no secret');

$opts = Setup::options([
    ['name' => 'site', 'type' => 'service_account', 'email' => 'svc@x.iam.gserviceaccount.com', 'connected' => true],
    ['name' => 'me', 'type' => 'oauth', 'email' => null, 'connected' => false],
]);
check($opts === ['site' => 'site (Service account, svc@x.iam.gserviceaccount.com)', 'me' => 'me (OAuth, not connected)'], 'options(): name => "name (type, email)"');
check(Setup::accountOptions() === [], 'accountOptions() falls back to [] without Grav');

$bodyError = static function (mixed $b): ?string {
    try {
        Setup::accountBody($b);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};
check(Setup::accountBody(['name' => 'site', 'type' => 'oauth', 'json' => '{}', 'extra' => 1]) === ['name' => 'site', 'type' => 'oauth', 'json' => '{}'], 'accountBody() keeps only name, type, json');
foreach ([
    'not an object' => 'x',
    'a path as the name' => ['name' => '../etc', 'type' => 'oauth', 'json' => '{}'],
    'an uppercase name' => ['name' => 'Site', 'type' => 'oauth', 'json' => '{}'],
    'an unknown type' => ['name' => 'site', 'type' => 'jwt', 'json' => '{}'],
    'json as an object' => ['name' => 'site', 'type' => 'oauth', 'json' => ['web' => []]],
    'empty json' => ['name' => 'site', 'type' => 'oauth', 'json' => '  '],
    'oversized json' => ['name' => 'site', 'type' => 'oauth', 'json' => str_repeat(' ', Setup::MAX_JSON) . '{}'],
] as $what => $b) {
    check($bodyError($b) !== null, "accountBody() refuses {$what}");
}

$md = Setup::render('[a](oauth.md) [b](troubleshooting.md#invalid-grant) [c](https://x/oauth.md) `{{redirect_uri}}` {{x}} <script>alert(1)</script> <div id="x"> < SCRIPT', ['redirect_uri' => 'https://ex.com/cb']);
check(str_contains($md, '](#oauth)') && str_contains($md, '](#troubleshooting--invalid-grant)') && str_contains($md, '(https://x/oauth.md)'), 'render() points guide links at the settings tabs and leaves others alone');
check(str_contains($md, '`https://ex.com/cb`') && str_contains($md, '{{x}}'), 'render() fills known placeholders only');
check(!preg_match('/<\s*script|<div id=/i', $md), 'render() never emits <script or <div id=');
foreach (Setup::GUIDES as $g) {
    $out = Setup::guide($g);
    check(strlen($out) > 500 && !str_contains($out, '{{') && !preg_match('/<\s*script|<div id=/i', $out), "guide({$g}) renders without Grav: fallbacks filled, nothing Admin2 would hide");
}
check(str_contains(Setup::guide('oauth'), 'https://YOUR-SITE/gdrive-oauth/callback') && str_contains(Setup::guide('service-account'), 'upload a key first'), 'unknown values get friendly fallbacks');
check(Setup::guide('../README') === '', 'guide() only reads its own four files');
check(!preg_match('/<\s*script|<div id=/i', Setup::guide('start-here')) && str_contains(Setup::guide('start-here'), 'Most people: use OAuth'), 'start-here renders, recommends OAuth, and has nothing Admin2 would hide');

// --- Guided setup: conditional markers, the full guides unfiltered, tables, deep links, the query whitelist.
$md = "a\n<!-- only: gmail -->\nG\n<!-- /only -->\n<!-- only: workspace+not-admin -->\nW\n<!-- /only -->\n   <!-- only: not-admin, sa -->\n   N\n   <!-- /only -->\nz\n";
check(Setup::filterGuide($md, ['gmail']) === "a\nG\n   N\nz\n", 'filterGuide(): a tag keeps its block, not-admin is active when admin is not, workspace+not-admin needs both');
check(Setup::filterGuide($md, ['workspace', 'not-admin']) === "a\nW\n   N\nz\n", 'filterGuide(): a+b keeps the block when every tag is active');
check(Setup::filterGuide($md, ['workspace', 'admin']) === "a\nz\n", 'filterGuide(): negation drops not-admin for an admin');
check(Setup::filterGuide($md, ['workspace', 'admin', 'sa']) === "a\n   N\nz\n", 'filterGuide(): any comma-separated entry keeps the block');
check(Setup::filterGuide("x\r\n<!-- only: gmail -->\r\nG\r\n<!-- /only -->\r\ny", []) === "x\r\ny", 'filterGuide() handles CRLF checkouts');
foreach (['oauth', 'service-account', 'start-here'] as $g) {
    check(!str_contains(Setup::guide($g), '<!--'), "guide({$g}) drops the markers");
}
check(str_contains(Setup::guide('oauth'), 'Publish app') && str_contains(Setup::guide('oauth'), '**Internal**') && str_contains(Setup::guide('oauth'), 'the 7-day trap'), 'the full OAuth guide keeps both the External and the Internal path');
check(str_contains(Setup::guide('service-account'), 'ask your Workspace administrator') && str_contains(Setup::guide('service-account'), 'how an organisation administrator allows keys'), 'the full service-account guide keeps both admin and not-admin text');

$t = Setup::tables("x\n\n| a | b |\n|---|:--|\n| **c** | d |\n\ny\n", static fn (string $c): string => strtoupper($c));
check(str_contains($t, '<table') && str_contains($t, '<th style="padding:6px 14px') && str_contains($t, '<td style="padding:6px 14px;vertical-align:top;text-align:left;border-bottom:1px solid rgba(127,127,127,.3)">**C**</td>') && !str_contains($t, '|') && str_starts_with($t, "x\n\n<table") && str_ends_with($t, "</table>\n\ny\n"), 'tables(): pipe table → one-line HTML table with padded cells');
$t = Setup::tables((string) file_get_contents(__DIR__ . '/../docs/setup/start-here.md'), static fn (string $c): string => htmlspecialchars($c));
check(substr_count($t, '<table') === 1 && strpos($t, 'OAuth (your Google account)') < strpos($t, '>Service account<'), 'start-here: one comparison table, OAuth column first');

check(Setup::withProject('[a](https://console.cloud.google.com/) [b](https://console.cloud.google.com/apis/library/drive.googleapis.com)', 'my-site-123') === '[a](https://console.cloud.google.com/?project=my-site-123) [b](https://console.cloud.google.com/apis/library/drive.googleapis.com?project=my-site-123)', 'withProject() adds ?project= to console links');
check(Setup::withProject('(https://console.cloud.google.com/x?a=1) (https://console.cloud.google.com/y?) (https://console.cloud.google.com/z#f) (https://console.cloud.google.com/w?project=keep)', 'my-site-123') === '(https://console.cloud.google.com/x?a=1&project=my-site-123) (https://console.cloud.google.com/y?project=my-site-123) (https://console.cloud.google.com/z?project=my-site-123#f) (https://console.cloud.google.com/w?project=keep)', 'withProject() merges &, keeps the fragment and an existing project=');
$other = '[d](https://drive.google.com/) [m](https://myaccount.google.com/connections) https://console.cloud.google.com.evil.example/x';
check(Setup::withProject($other, 'my-site-123') === $other && Setup::withProject('https://console.cloud.google.com/', 'Bad_ID') === 'https://console.cloud.google.com/', 'withProject() leaves other links alone and ignores a bad ID');

$profileError = static function (array $q): ?string {
    try {
        Setup::guideProfile($q);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};
foreach ([
    'no kind' => [],
    'an unknown kind' => ['kind' => 'Gmail'],
    'kind as an array' => ['kind' => ['gmail']],
    'an unknown method' => ['kind' => 'gmail', 'method' => 'jwt'],
    'an unknown shared_drive' => ['kind' => 'workspace', 'shared_drive' => 'maybe'],
    'an unknown admin' => ['kind' => 'workspace', 'admin' => '1'],
    'a project with uppercase' => ['kind' => 'gmail', 'project' => 'My-Project'],
    'a project with markup' => ['kind' => 'gmail', 'project' => '<script>x</script>'],
    'a too-short project' => ['kind' => 'gmail', 'project' => 'abc'],
    'a project ending in a hyphen' => ['kind' => 'gmail', 'project' => 'my-project-'],
] as $what => $q) {
    $err = $profileError($q);
    check($err !== null && !str_contains($err, '<') && !str_contains($err, 'My-Project'), "guideProfile() refuses {$what} without echoing it");
}
check(Setup::guideProfile(['kind' => 'workspace', 'shared_drive' => 'yes', 'utm' => 'x']) === ['kind' => 'workspace', 'method' => 'oauth', 'shared_drive' => 'yes', 'admin' => 'no', 'project' => ''], 'guideProfile(): OAuth by default even with a Shared Drive, admin defaults to no, unknown keys ignored');
check(Setup::guideProfile(['kind' => 'gmail', 'method' => 'sa', 'shared_drive' => 'yes', 'admin' => 'yes', 'project' => 'my-site-123']) === ['kind' => 'gmail', 'method' => 'sa', 'shared_drive' => '', 'admin' => '', 'project' => 'my-site-123'], 'guideProfile(): Workspace answers are dropped for a personal account');

$prof = Setup::guideProfile(['kind' => 'workspace', 'method' => 'sa', 'shared_drive' => 'yes', 'admin' => 'yes']);
check(Setup::guideTags($prof, []) === ['workspace', 'sa', 'shared-drive', 'admin', 'backup', 'gallery'], 'guideTags(): nothing declared → both purposes');
check(Setup::guideTags(Setup::guideProfile(['kind' => 'gmail']), [$decl[0]]) === ['gmail', 'oauth', 'gallery'], 'guideTags(): drive.readonly → gallery only');
check(Setup::guideTags(Setup::guideProfile(['kind' => 'workspace', 'project' => 'my-site-123']), [['plugin' => 'x', 'account' => 'a', 'scopes' => [Drive::SCOPE_FULL]]]) === ['workspace', 'oauth', 'not-admin', 'project', 'backup'], 'guideTags(): drive → backup; not-admin and project');

// Without Grav, guided() shows the markdown escaped in <pre>; decode it to read the text.
$guided = static fn (array $q): string => html_entity_decode(Setup::guided($q), ENT_QUOTES);
$paths = [
    'gmail + OAuth' => [['kind' => 'gmail', 'method' => 'oauth'], ['OAuth** account for a personal Google account', 'Publish app', 'the 7-day trap', "Google hasn't verified this app", 'Click **Connect**'], ['Internal', 'On Google Workspace?']],
    'Workspace + SA + Shared Drive + admin' => [['kind' => 'workspace', 'method' => 'sa', 'shared_drive' => 'yes', 'admin' => 'yes'], ['**service account** for a Google Workspace account', 'writes into a Shared Drive', 'how an organisation administrator allows keys', 'Content manager', 'Viewer', 'key creation is disabled'], ['ask your Workspace administrator', 'With a personal Google account', 'Publish app']],
    'Workspace + SA, not admin' => [['kind' => 'workspace', 'method' => 'sa', 'shared_drive' => 'no', 'admin' => 'no'], ['ask your Workspace administrator', 'iam.disableServiceAccountKeyCreation', 'can only read folders'], ['how an organisation administrator allows keys']],
    'Workspace + OAuth' => [['kind' => 'workspace'], ['**Internal** (Google Workspace)', 'An **Internal** app', 'On Google Workspace?'], ['the 7-day trap', 'Publish app', "hasn't verified", 'Test users']],
];
foreach ($paths as $what => [$q, $has, $hasNot]) {
    $g = $guided($q);
    check(!str_contains($g, '<!--') && !str_contains($g, '{{') && str_contains($g, '### Then') && str_contains($g, '](#accounts_tab)'), "guided({$what}): markers gone, placeholders filled, ends with Then");
    foreach ($has as $phrase) {
        check(str_contains($g, $phrase), "guided({$what}) says \"{$phrase}\"");
    }
    foreach ($hasNot as $phrase) {
        check(!str_contains($g, $phrase), "guided({$what}) leaves out \"{$phrase}\"");
    }
}
$g = $guided(['kind' => 'gmail', 'project' => 'my-site-123']);
check(str_contains($g, 'https://console.cloud.google.com/?project=my-site-123') && str_contains($g, 'auth/audience?project=my-site-123') && str_contains($g, 'already open the project you named') && str_contains($g, '(https://myaccount.google.com/connections)'), 'guided() with a project deep-links every console link, and only those');
check(!str_contains($guided(['kind' => 'gmail']), 'already open the project'), 'guided() without a project leaves out the project line');
foreach (['oauth', 'service-account'] as $full) {
    check(!str_contains(Setup::guide($full), 'already open the project'), "the full {$full} guide never claims its links open a project (no project there)");
}
check(!str_contains(Setup::guided(['kind' => '<b>x</b>']), '<b>'), 'guided() refuses a bad profile without echoing it');
check(Setup::checklist() !== '' && Setup::whoUsesWhat() !== '', 'checklist() and whoUsesWhat() survive Grav not being booted');
check(str_contains(Setup::consumerNotice('gdrive-images'), 'Set up Google Drive access'), 'consumerNotice() always points at the setup page');

// --- Every reason the library raises, and every Google reason the guide promises, has its Troubleshooting anchor.
$trouble = (string) file_get_contents(__DIR__ . '/../docs/setup/troubleshooting.md');
$src = implode("\n", array_map('file_get_contents', [...(glob(__DIR__ . '/../classes/*.php') ?: []), __DIR__ . '/../gdrive.php']));
preg_match_all("/new DriveException\\([^;]*?,\\s*'([A-Za-z_]+)'/s", $src, $m);
$ours = array_values(array_unique($m[1]));
check(count($ours) >= 9, 'found the library\'s own reason codes: ' . implode(', ', $ours));
$google = ['storageQuotaExceeded', 'notFound', 'accessNotConfigured', 'redirect_uri_mismatch', 'invalid_grant', 'rateLimitExceeded', 'userRateLimitExceeded', 'insufficientPermissions', 'insufficientFilePermissions', 'invalid_client', 'unauthorized_client', 'access_denied', 'admin_policy_enforced', 'org_internal', 'PERMISSION_DENIED', 'UNAUTHENTICATED', 'forbidden', 'authError', 'backendError', 'internalError'];
foreach ([...$ours, ...$google] as $reason) {
    $anchor = (new DriveException('', $reason))->anchor();
    check(str_contains($trouble, "<a id=\"{$anchor}\"></a>"), "troubleshooting.md has an anchor for {$reason} (#{$anchor})");
}
preg_match_all('/\]\(troubleshooting\.md#([a-z0-9-]+)\)|\]\(#(?!troubleshooting--)([a-z0-9-]+)\)|#troubleshooting--([a-z0-9-]+)/', implode("\n", array_map('file_get_contents', [...(glob(__DIR__ . '/../docs/setup/*.md') ?: []), __DIR__ . '/../classes/Setup.php', __DIR__ . '/../admin-next/fields/gdrive-accounts.js'])), $links);
foreach (array_unique(array_filter([...$links[1], ...$links[2], ...$links[3]])) as $anchor) {
    check(str_contains($trouble, "<a id=\"{$anchor}\"></a>"), "link #{$anchor} has its Troubleshooting anchor");
}

foreach (array_merge(glob($tmp . '/oauth-state/*') ?: [], glob($tmp . '/*') ?: []) as $f) {
    is_dir($f) ? @rmdir($f) : @unlink($f);
}
@rmdir($tmp);

// --- Version drift: GPM installs blueprints.yaml's version; the plugin reports its constant.
$blueprints = (string) file_get_contents(__DIR__ . '/../blueprints.yaml');
preg_match('/^version:\s*(\S+)/m', $blueprints, $bv);
preg_match("/const VERSION = '([^']+)'/", (string) file_get_contents(__DIR__ . '/../gdrive.php'), $pv);
check(($bv[1] ?? '') === ($pv[1] ?? 'missing'), sprintf('GdrivePlugin::VERSION (%s) matches blueprints.yaml (%s)', $pv[1] ?? 'missing', $bv[1] ?? 'missing'));

// --- Admin2 renders custom fields inside its own <form>: a nested <form> is dropped by the
// parser (the 0.1.0 crash), and an untyped <button> would submit the settings form.
foreach (glob(__DIR__ . '/../admin-next/fields/*.js') ?: [] as $js) {
    $src = (string) file_get_contents($js);
    check(stripos($src, '<form') === false, basename($js) . ' has no <form> (Admin2 nests fields in its own form)');
    preg_match_all('/<button\b[^>]*>/', $src, $buttons);
    check(array_filter($buttons[0], static fn (string $b): bool => !str_contains($b, 'type="button"')) === [], basename($js) . ' types every <button> as type="button"');
    check(preg_match('/new\s+CustomEvent\(\s*[\'"]change/', $src) === 0, basename($js) . ' never dispatches change (display-only fields)');
    check(str_starts_with(trim((string) preg_replace('~^/\*.*?\*/~s', '', $src)), 'const TAG = window.__GRAV_FIELD_TAG;') && str_ends_with(trim($src), ');') && str_contains($src, 'customElements.define(TAG,'), basename($js) . ' takes its tag from window.__GRAV_FIELD_TAG');
}

echo "smoke: OK\n";
