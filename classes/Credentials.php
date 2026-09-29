<?php

declare(strict_types=1);

namespace Grav\Plugin\Gdrive;

/** Something that can mint a Google access token: a service account or a connected OAuth user. */
interface Credentials
{
    /**
     * A bearer token covering every scope in $scopes (cached until shortly before expiry).
     *
     * @param string[] $scopes
     */
    public function token(array $scopes): string;

    /** The Google identity: the service account's client_email, or the connected user's email ('' if unknown). */
    public function email(): string;

    /**
     * Drops the cached token for $scopes, so the next token() fetches a fresh one (Drive calls this on a 401).
     *
     * @param string[] $scopes
     */
    public function forget(array $scopes): void;
}
