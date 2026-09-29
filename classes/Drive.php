<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

/**
 * Thin Google Drive v3 client. Returns Drive's raw JSON arrays and lets the
 * caller pick `fields`; no resource objects and no caching, each plugin
 * caches its own way. Shared Drives always work: supportsAllDrives=true on
 * every call, includeItemsFromAllDrives=true on every /files listing.
 */
final class Drive
{
    public const SCOPE_FILE = 'https://www.googleapis.com/auth/drive.file';
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/drive.readonly';
    public const SCOPE_FULL = 'https://www.googleapis.com/auth/drive';
    public const FOLDER = 'application/vnd.google-apps.folder';
    public const API = 'https://www.googleapis.com/drive/v3';
    public const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';

    /** @var callable(string, string, array): array{int, string, array<string, string>} */
    private $http;

    /**
     * @param string[] $scopes the scopes every token for this client must cover
     * @param callable(string, string, array): array{int, string, array<string, string>} $http
     */
    public function __construct(private Credentials $creds, private array $scopes, callable $http)
    {
        $this->http = $http;
    }

    /** Authenticated call to API . $path; returns the decoded body ([] for an empty 204). Non-2xx throws. */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $opts = $json === null ? [] : ['headers' => ['Content-Type: application/json; charset=UTF-8'], 'body' => (string) json_encode($json)];
        [$status, $body] = $this->send($method, self::API . $path, $query, $opts);
        $data = $body === '' ? [] : json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw DriveException::fromResponse($status, $body, "{$method} {$path}");
        }

        return $data;
    }

    /**
     * Every item of a list endpoint, following nextPageToken. The items are
     * under the path's last segment: /files → files, /drives → drives.
     */
    public function paginate(string $path, array $query): \Generator
    {
        if ($path === '/files') {
            $query += ['includeItemsFromAllDrives' => 'true'];
        }
        $key = basename($path);
        do {
            $data = $this->request('GET', $path, $query);
            foreach ((array) ($data[$key] ?? []) as $item) {
                yield $item;
            }
            $query['pageToken'] = $data['nextPageToken'] ?? null;
        } while (is_string($query['pageToken']) && $query['pageToken'] !== '');
    }

    /**
     * Non-trashed children of a folder that are folders or have a MIME in $mimes.
     *
     * @param string[] $mimes
     * @param string $fields per-file fields, e.g. 'id,name,mimeType,md5Checksum'
     */
    public function children(string $folderId, array $mimes, string $fields): array
    {
        $types = array_map(static fn (string $m): string => 'mimeType=' . self::quote($m), [self::FOLDER, ...$mimes]);
        $q = self::quote($folderId) . ' in parents and trashed=false and (' . implode(' or ', $types) . ')';

        return $this->list($q, $fields, ['orderBy' => 'name']);
    }

    /** Non-trashed children of $parentId tagged appProperties[$key] = $value. */
    public function findByAppProperty(string $parentId, string $key, string $value, string $fields): array
    {
        $q = self::quote($parentId) . ' in parents and trashed=false and appProperties has { key=' . self::quote($key) . ' and value=' . self::quote($value) . ' }';

        return $this->list($q, $fields);
    }

    /** Id of the non-trashed folder named $name in $parentId, created if missing. */
    public function ensureFolder(string $name, string $parentId = 'root'): string
    {
        $q = self::quote($parentId) . ' in parents and trashed=false and mimeType=' . self::quote(self::FOLDER) . ' and name=' . self::quote($name);
        $found = $this->list($q, 'id');
        if ($found !== []) {
            return (string) $found[0]['id'];
        }

        return (string) $this->request('POST', '/files', ['fields' => 'id'], ['name' => $name, 'mimeType' => self::FOLDER, 'parents' => [$parentId]])['id'];
    }

    /** Streams the file's bytes to $dest. Anything but a 200 deletes $dest and throws. */
    public function download(string $id, string $dest): void
    {
        $sink = @fopen($dest, 'wb');
        if ($sink === false) {
            throw new DriveException("gdrive: cannot open {$dest} for writing", 'io');
        }
        try {
            [$status] = $this->send('GET', self::API . '/files/' . rawurlencode($id), ['alt' => 'media'], ['sink' => $sink]);
        } finally {
            fclose($sink);
        }
        if ($status !== 200) {
            $body = (string) @file_get_contents($dest, false, null, 0, 4096); // the error JSON landed in the sink
            @unlink($dest);
            throw DriveException::fromResponse($status, $body, "download of {$id}");
        }
    }

    /**
     * Uploads a local file into $parentId and returns the new file resource
     * ($fields). A resumable session plus ONE streamed PUT, so the file never
     * has to fit in memory.
     *
     * ponytail: resumable upload restarts from zero on failure; resume from the
     * stored session URI (valid about a week) if backups outgrow about 1 GB or
     * the host drops long uploads.
     *
     * @param array<string, string> $appProperties
     */
    public function upload(string $localPath, string $parentId, string $name, array $appProperties = [], string $fields = 'id,name,md5Checksum,size'): array
    {
        $size = @filesize($localPath);
        if ($size === false) {
            throw new DriveException("gdrive: cannot read {$localPath}", 'io');
        }
        $type = str_ends_with(strtolower($name), '.zip') ? 'application/zip' : 'application/octet-stream';
        $meta = ['name' => $name, 'parents' => [$parentId]] + ($appProperties === [] ? [] : ['appProperties' => $appProperties]);

        [$status, $body, $headers] = $this->send('POST', self::UPLOAD_API . '/files', ['uploadType' => 'resumable', 'fields' => $fields], [
            'headers' => ['Content-Type: application/json; charset=UTF-8', "X-Upload-Content-Type: {$type}", "X-Upload-Content-Length: {$size}"],
            'body' => (string) json_encode($meta),
        ]);
        $session = $headers['location'] ?? '';
        if ($status !== 200 || !str_starts_with($session, 'https://')) {
            throw DriveException::fromResponse($status, $body, "upload session for {$name}");
        }

        $in = @fopen($localPath, 'rb');
        if ($in === false) {
            throw new DriveException("gdrive: cannot open {$localPath}", 'io');
        }
        try {
            // The session URI carries the upload id and every query parameter already.
            [$status, $body] = ($this->http)('PUT', $session, [
                'headers' => ['Content-Type: ' . $type, 'Authorization: Bearer ' . $this->creds->token($this->scopes)],
                'infile' => $in,
                'infile_size' => $size,
            ]);
        } finally {
            fclose($in);
        }
        $file = json_decode($body, true);
        if (($status !== 200 && $status !== 201) || !is_array($file)) {
            throw DriveException::fromResponse($status, $body, "upload of {$name}");
        }

        return $file;
    }

    /** Moves a file to the trash (a 30-day undo), never a permanent delete. */
    public function trash(string $id): void
    {
        $this->request('PATCH', '/files/' . rawurlencode($id), ['fields' => 'id'], ['trashed' => true]);
    }

    public function about(string $fields = 'user(emailAddress,displayName),storageQuota'): array
    {
        return $this->request('GET', '/about', ['fields' => $fields]);
    }

    /** Every /files match for a query, all pages. */
    private function list(string $q, string $fields, array $query = []): array
    {
        return iterator_to_array($this->paginate('/files', ['q' => $q, 'fields' => "nextPageToken,files({$fields})", 'pageSize' => 1000] + $query), false);
    }

    /**
     * Authenticated request; on a 401 drops the cached token and retries once
     * (truncating a sink first, so the retry doesn't append to the 401's error body).
     *
     * @return array{int, string, array<string, string>}
     */
    private function send(string $method, string $url, array $query, array $opts): array
    {
        $url .= '?' . http_build_query(['supportsAllDrives' => 'true'] + array_filter($query, static fn ($v): bool => $v !== null));
        for ($retried = false; ; $retried = true) {
            $call = $opts;
            $call['headers'] = [...($opts['headers'] ?? []), 'Authorization: Bearer ' . $this->creds->token($this->scopes)];
            $response = ($this->http)($method, $url, $call);
            if ($response[0] !== 401 || $retried) {
                return $response;
            }
            $this->creds->forget($this->scopes);
            if (isset($opts['sink'])) {
                ftruncate($opts['sink'], 0);
                rewind($opts['sink']);
            }
        }
    }

    /** A Drive query string literal. */
    private static function quote(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
