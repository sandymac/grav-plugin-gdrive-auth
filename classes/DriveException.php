<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

/**
 * Every failure the library raises. `reason` is Google's machine-readable
 * error (`error.errors[0].reason`, `error.status`, or OAuth's `error`) or one
 * of our own codes (scope_not_granted, not_connected, bad_credential,
 * bad_state, unknown_account, transport, io), so callers branch on it and the
 * settings page links straight to the matching Troubleshooting row.
 */
final class DriveException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = '',
        public readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Troubleshooting anchor: storageQuotaExceeded → storage-quota-exceeded, invalid_grant → invalid-grant. */
    public function anchor(): string
    {
        return strtolower(trim((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|[^A-Za-z0-9]+/', '-', $this->reason), '-'));
    }

    /** Parses a non-2xx Google response; $what names the call for the message ("GET /files"). */
    public static function fromResponse(int $status, string $body, string $what): self
    {
        $data = json_decode($body, true);
        $reason = $detail = '';
        if (is_array($data) && is_array($data['error'] ?? null)) {
            $reason = (string) ($data['error']['errors'][0]['reason'] ?? $data['error']['status'] ?? '');
            $detail = (string) ($data['error']['message'] ?? '');
        } elseif (is_array($data) && is_string($data['error'] ?? null)) {
            $reason = $data['error'];
            $detail = (string) ($data['error_description'] ?? '');
        }
        $message = "gdrive: {$what} failed (HTTP {$status})" . ($reason !== '' ? " {$reason}" : '') . ($detail !== '' ? ": {$detail}" : '');

        return new self($message, $reason, $status);
    }
}
