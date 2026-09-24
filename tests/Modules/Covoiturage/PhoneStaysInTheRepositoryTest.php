<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use PHPUnit\Framework\TestCase;

/**
 * « Téléphone jamais lu hors du Repository » — and no other personal field
 * either (AGENTS.md § Security checklist, point 3): the only files of the
 * module that name an encrypted column or call decrypt()/encrypt() are its
 * two repositories of people.
 */
final class PhoneStaysInTheRepositoryTest extends TestCase
{
    private const ALLOWED = [
        'src/Repository/OfferRepository.php',
        'src/Repository/SeatRequestRepository.php',
    ];

    public function testNoOtherFileNamesAnEncryptedColumnOrDecrypts(): void
    {
        $root = dirname(__DIR__, 3) . '/modules/covoiturage/';
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!in_array($file->getExtension(), ['php', 'twig', 'sql', 'json'], true)) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root));
            if (in_array($relative, self::ALLOWED, true) || $relative === 'schema.sql') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (['phone_encrypted', '_encrypted', '->decrypt(', '->encrypt('] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $relative . ' — ' . $needle;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testTheScanWouldSeeTheRepositoriesItExempts(): void
    {
        // A scan that silently read nothing would pass on nothing.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/covoiturage/src/Repository/OfferRepository.php');
        $this->assertStringContainsString('phone_encrypted', $source);
        $this->assertStringContainsString('->decrypt(', $source);
    }
}
