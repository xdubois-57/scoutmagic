<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * The main upload path (Core\File\UploadHandler) detects an upload's MIME type
 * from its real content and never trusts the client-declared $_FILES['type'].
 * Four secondary upload handlers used to fall back to that client value when
 * finfo returned a falsy result (audit M9) — a spoofable input that bypassed
 * their MIME allowlists. This source-level guard keeps that fallback from being
 * reintroduced: a detection failure must resolve to a sentinel the allowlist
 * rejects, never to $file['type'].
 */
class UploadMimeTrustAuditTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function uploadHandlingFiles(): array
    {
        return [
            'section documents' => ['core/Http/Controller/SectionDocumentController.php'],
            'finance receipts' => ['modules/finance/src/Controller/ReceiptController.php'],
            'finance movements' => ['modules/finance/src/Controller/MovementController.php'],
        ];
    }

    /**
     * @dataProvider uploadHandlingFiles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uploadHandlingFiles')]
    public function testMimeIsNeverDerivedFromTheClientDeclaredType(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        $this->assertNotFalse($source);

        // The bug shape: a MIME value that Elvis-falls-back to the client
        // type, e.g. `$finfo->buffer($content) ?: $file['type']`. Any
        // occurrence of `?: ...$file['type']` (or ['type'] ?? ...) on a MIME
        // assignment line is the regression.
        $this->assertDoesNotMatchRegularExpression(
            "/\\?:[^;\\n]*\\\$file\\['type'\\]/",
            $source,
            "MIME type in {$relativePath} falls back to the client-declared \$file['type'] (audit M9) — "
                . 'detection failure must resolve to a non-allowlisted sentinel instead.'
        );
    }
}
