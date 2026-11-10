<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\BackupException;
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Portable\PortableManifest;
use Core\Maintenance\RestoreArchiveCheck;
use PHPUnit\Framework\TestCase;
use Tests\Support\PortableHints;

/**
 * The read-only check a restore form runs before anything is queued
 * (issue #753). Each refusal names its case — not a backup, no password,
 * wrong password — because each calls for something different.
 */
final class RestoreArchiveCheckTest extends TestCase
{
    private const PASSWORD = 'mot-de-passe-de-l-archive';
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';

    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testAnEncryptedBackupOpensWithItsPassword(): void
    {
        $this->expectNotToPerformAssertions();

        RestoreArchiveCheck::assertUploadOpens($this->ordinaryArchive(self::PASSWORD), self::PASSWORD);
    }

    public function testAnEncryptedBackupWithTheWrongPasswordIsSaidToBeTheWrongPassword(): void
    {
        $this->assertRefused(
            RestoreArchiveCheck::WRONG_PASSWORD,
            fn() => RestoreArchiveCheck::assertUploadOpens($this->ordinaryArchive(self::PASSWORD), 'autre chose')
        );
    }

    public function testAnEncryptedBackupWithoutAPasswordAsksForOne(): void
    {
        $this->assertRefused(
            'Cette sauvegarde est protégée par un mot de passe : saisissez-le.',
            fn() => RestoreArchiveCheck::assertUploadOpens($this->ordinaryArchive(self::PASSWORD), null)
        );
    }

    public function testABackupInClearOpensWhateverWasTyped(): void
    {
        $this->expectNotToPerformAssertions();

        RestoreArchiveCheck::assertUploadOpens($this->ordinaryArchive(null), 'tapé pour rien');
    }

    /** Not « mot de passe incorrect ? »: no password could have helped. */
    public function testAZipWithoutADatabaseIsNotABackupRatherThanAWrongPassword(): void
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('photo.jpg', 'des octets');
        $zip->close();

        $this->assertRefused(
            'Ce fichier n\'est pas une sauvegarde du site : il ne contient pas de base de données (database.sql).',
            fn() => RestoreArchiveCheck::assertUploadOpens($path, self::PASSWORD)
        );
    }

    public function testAFileThatIsNotAZipIsUnreadable(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, 'PK pas vraiment une archive');

        $this->assertRefused(
            'Ce fichier n\'est pas une archive de sauvegarde lisible.',
            fn() => RestoreArchiveCheck::assertUploadOpens($path, self::PASSWORD)
        );
    }

    public function testAPortableArchiveOpensWithItsPassphrase(): void
    {
        $this->expectNotToPerformAssertions();

        RestoreArchiveCheck::assertUploadOpens($this->portableArchive(), self::PASSPHRASE);
    }

    public function testAPortableArchiveWithTheWrongPassphraseIsRefused(): void
    {
        $this->assertRefused(
            'La phrase de passe ne correspond pas à cette archive, ou l\'archive est endommagée.',
            fn() => RestoreArchiveCheck::assertUploadOpens($this->portableArchive(), 'une autre phrase')
        );
    }

    public function testAPortableArchiveWithoutAPassphraseAsksForIt(): void
    {
        $this->assertRefused(
            'Une sauvegarde portable ne se restaure qu\'avec sa phrase de passe.',
            fn() => RestoreArchiveCheck::assertUploadOpens($this->portableArchive(), null)
        );
    }

    /**
     * A full backup of this server from before IT-03: the smallest of its
     * encrypted members proves the password, the gallery is never read.
     */
    public function testAServerArchiveWhosePasswordWasNeverKeptIsCheckedAgainstWhatWasTyped(): void
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach (['storage/gallery/grande.jpg' => str_repeat('x', 200000), 'storage/config/petit.txt' => 'x'] as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $zip->setEncryptionName($name, \ZipArchive::EM_AES_256, self::PASSWORD);
        }
        $zip->close();

        RestoreArchiveCheck::assertServerArchiveOpens($path, self::PASSWORD);
        $this->assertRefused(
            RestoreArchiveCheck::WRONG_PASSWORD,
            fn() => RestoreArchiveCheck::assertServerArchiveOpens($path, 'autre chose')
        );
        $this->assertRefused(
            'Cette sauvegarde est protégée par un mot de passe : saisissez-le.',
            fn() => RestoreArchiveCheck::assertServerArchiveOpens($path, null)
        );
    }

    /** One that cannot be read is the handler's to explain. */
    public function testAServerArchiveThatIsMissingIsLeftToTheHandler(): void
    {
        $this->expectNotToPerformAssertions();

        RestoreArchiveCheck::assertServerArchiveOpens(sys_get_temp_dir() . '/absente_' . bin2hex(random_bytes(4)) . '.zip', null);
    }

    private function assertRefused(string $message, callable $check): void
    {
        try {
            $check();
            $this->fail('The archive was accepted.');
        } catch (BackupException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function ordinaryArchive(?string $password): string
    {
        $path = $this->tempPath();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', str_repeat("INSERT INTO t VALUES (1);\n", 20000));
        if ($password !== null) {
            $zip->setEncryptionName('database.sql', \ZipArchive::EM_AES_256, $password);
        }
        $zip->close();

        return $path;
    }

    /** What PortableArchive::open() reads: the header in clear and the encrypted manifest. */
    private function portableArchive(): string
    {
        $path = $this->tempPath();
        $derivation = PortableKeys::newDerivation();
        $password = PortableKeys::derive(self::PASSPHRASE, $derivation)->archivePassword();

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString(PortableManifest::MEMBER, (string) json_encode([
            'format' => PortableManifest::FORMAT,
            'format_version' => PortableManifest::FORMAT_VERSION,
            'scoutmagic_version' => '2.4.1',
        ]));
        $zip->setEncryptionName(PortableManifest::MEMBER, \ZipArchive::EM_AES_256, $password);
        $zip->setArchiveComment(PortableKeys::comment($derivation, PortableHints::sample('2.4.1')));
        $zip->close();

        return $path;
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/restore_check_' . bin2hex(random_bytes(8)) . '.zip';
        $this->paths[] = $path;

        return $path;
    }
}
