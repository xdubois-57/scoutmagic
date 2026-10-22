<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

/**
 * The bytes of a signature, as the site keeps and applies it (issue #926):
 * a PNG that {@see SignatureImage} re-encoded, or that a repository read
 * back after such a re-encoding.
 *
 * **A type rather than a string**, so that a signature cannot be confused
 * with any other bytes on its way to a document or to storage: a method
 * that takes a `SignaturePng` cannot be handed the raw upload by mistake.
 * The constructor checks the PNG header and nothing more — the work of
 * decoding and re-encoding is {@see SignatureImage}'s, and a value of this
 * type is only ever built there or from what it stored.
 *
 * **It never shows its bytes by accident.** `var_dump()`, `print_r()` and
 * an exception trace print what `__debugInfo()` returns, which is the size
 * and never the image: a signature reaching a log is a signature anyone
 * reading that log could apply.
 */
final readonly class SignaturePng
{
    private const PNG_HEADER = "\x89PNG\r\n\x1a\n";

    private string $bytes;

    /**
     * @throws \InvalidArgumentException when the bytes are not a PNG — a
     *         programming error, never a visitor's: what a visitor sends is
     *         refused earlier, by SignatureImage, with a SignatureException
     */
    public function __construct(#[\SensitiveParameter] string $bytes)
    {
        if (!str_starts_with($bytes, self::PNG_HEADER)) {
            throw new \InvalidArgumentException('A SignaturePng holds PNG bytes only.');
        }

        $this->bytes = $bytes;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function byteLength(): int
    {
        return strlen($this->bytes);
    }

    /**
     * @return array{byteLength: int}
     */
    public function __debugInfo(): array
    {
        return ['byteLength' => $this->byteLength()];
    }
}
