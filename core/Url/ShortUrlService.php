<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Url;

/**
 * Generic short-URL generator/resolver — not tied to any module (see
 * schema/core.sql's short_urls table doc comment). Codes are 6-char
 * base62 (random_bytes-derived), regenerated on the rare collision.
 */
class ShortUrlService
{
    private const CODE_LENGTH = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const MAX_ATTEMPTS = 10;

    public function __construct(private ShortUrlRepository $repository)
    {
    }

    /**
     * Creates a new short code for $targetUrl (an internal path, e.g.
     * "/news/12") and returns the generated code (not the full URL — the
     * caller composes the full URL with the site's own base, since this
     * service has no notion of scheme/host).
     */
    public function createShortUrl(string $targetUrl, ?int $createdBy): string
    {
        $code = $this->generateUniqueCode();
        $this->repository->create($code, $targetUrl, $createdBy);
        return $code;
    }

    public function resolve(string $code): ?string
    {
        return $this->repository->findByCode($code)?->targetUrl;
    }

    private function generateUniqueCode(): string
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = $this->randomCode();
            if (!$this->repository->codeExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique short code after ' . self::MAX_ATTEMPTS . ' attempts.');
    }

    private function randomCode(): string
    {
        $alphabetLength = strlen(self::ALPHABET);

        // random_int() is unbiased over the alphabet; the previous
        // `ord(random_bytes) % 62` made the first 8 characters (A–H) ~25% more
        // likely, since 256 is not a multiple of 62 (audit hardening).
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
