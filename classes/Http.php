<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

/**
 * The transport seam. Everything that talks to Google takes a callable
 *   fn(string $method, string $url, array $opts): array{int, string, array<string, string>}
 * returning [status, body, lowercase response headers], so tests (ours and
 * dependent plugins') swap in a fake. $opts: headers (string[]), body
 * (string), infile (resource) + infile_size (int) for a streamed PUT, sink
 * (resource) to stream the response body to instead of returning it.
 */
final class Http
{
    private const RETRYABLE = [429, 500, 502, 503, 504];

    /**
     * The real transport. A curl failure (DNS, reset, timeout) throws DriveException reason `transport`.
     *
     * @param array{headers?: string[], body?: string, infile?: resource, infile_size?: int, sink?: resource} $opts
     * @return array{int, string, array<string, string>}
     */
    public static function curl(string $method, string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new DriveException('gdrive: curl_init failed', 'transport');
        }
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            // Uploads can be large backups; anything else that runs this long is stuck.
            CURLOPT_TIMEOUT => isset($opts['infile']) ? 3600 : 300,
            // ...and a transfer that stalls for a minute is dead whatever its size.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // a 100 Continue precedes the real response
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if (isset($opts['infile'])) {
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $opts['infile'],
                CURLOPT_INFILESIZE => (int) ($opts['infile_size'] ?? 0),
            ]);
        } elseif (isset($opts['body']) || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body'] ?? '');
        }
        if (isset($opts['sink'])) {
            curl_setopt($ch, CURLOPT_FILE, $opts['sink']);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new DriveException("gdrive: {$method} {$url}: {$error}", 'transport');
        }

        return [$status, is_string($body) ? $body : '', $headers];
    }

    /**
     * Wraps a transport to retry 429/5xx and transport errors with exponential
     * backoff plus jitter. Streamed requests (sink or infile) are passed through
     * once: a half-consumed stream can't be replayed, so their caller decides.
     *
     * @param callable(string, string, array): array{int, string, array<string, string>} $http
     * @param callable(float): void|null $sleep seconds; tests pass a no-op
     * @return callable(string, string, array): array{int, string, array<string, string>}
     */
    public static function withRetry(callable $http, int $tries = 4, ?callable $sleep = null): callable
    {
        $sleep ??= static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };

        return static function (string $method, string $url, array $opts = []) use ($http, $tries, $sleep): array {
            $once = isset($opts['sink']) || isset($opts['infile']);
            for ($attempt = 1; ; $attempt++) {
                try {
                    $response = $http($method, $url, $opts);
                    if ($once || $attempt >= $tries || !in_array($response[0], self::RETRYABLE, true)) {
                        return $response;
                    }
                } catch (DriveException $e) {
                    if ($once || $attempt >= $tries || $e->reason !== 'transport') {
                        throw $e;
                    }
                }
                $sleep(min(32, 2 ** ($attempt - 1)) + mt_rand(0, 1000) / 1000);
            }
        };
    }
}
