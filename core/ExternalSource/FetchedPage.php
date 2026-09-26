<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * What one fetch of a registered page produced, redirects already
 * followed.
 *
 * `status` 0 means no HTTP answer at all — unknown domain, refused
 * connection, timeout, TLS failure — and `error` then says which, as far
 * as the transport could tell.
 */
final class FetchedPage
{
    /**
     * @param string|null $redirectedTo the last address the server sent us
     *        to, when at least one redirect was followed
     * @param bool $movedPermanently at least one hop answered 301 or 308 —
     *        the server says the old address is retired, which a 302 to a
     *        sign-in page does not
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $redirectedTo = null,
        public readonly ?string $error = null,
        public readonly bool $movedPermanently = false,
    ) {
    }

    public static function unreachable(string $error): self
    {
        return new self(0, '', null, $error);
    }
}
