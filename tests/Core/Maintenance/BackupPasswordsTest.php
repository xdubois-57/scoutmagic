<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\BackupPasswords;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;

/**
 * One generated password per archive, kept in secrets.enc (issue #619,
 * IT-03).
 */
final class BackupPasswordsTest extends TestCase
{
    private string $storage;
    private SecretManager $secrets;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/backup-passwords-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/config', 0700, true);
        $this->secrets = new SecretManager($this->storage . '/keys/master.key', $this->storage . '/config/secrets.enc');
        $this->secrets->generateMasterKey();
        $this->secrets->writeSecrets(['site_name' => 'Test']);
    }

    protected function tearDown(): void
    {
        foreach (['/config/secrets.enc', '/config/secrets.enc.lock', '/keys/master.key'] as $file) {
            @unlink($this->storage . $file);
        }
        @rmdir($this->storage . '/config');
        @rmdir($this->storage . '/keys');
        @rmdir($this->storage);
    }

    public function testAnIssuedPasswordIsKeptUnderItsArchiveAndReadableForCopying(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);

        $password = $passwords->issue(42);

        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/', $password);
        $this->assertSame($password, $passwords->passwordFor(42));
        $this->assertSame($password, $this->secrets->readSecrets()['backup_password_42']);
        // Nothing else in the file was touched.
        $this->assertSame('Test', $this->secrets->readSecrets()['site_name']);
    }

    /** A password that could not be kept is never handed out to encrypt with. */
    public function testAPasswordThatCannotBeKeptIsNotIssued(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);
        unlink($this->storage . '/keys/master.key');

        $this->expectException(\RuntimeException::class);
        $passwords->issue(3);
    }

    /** One per archive: revealing one says nothing about another. */
    public function testEachArchiveHasItsOwnPassword(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);

        $this->assertNotSame($passwords->issue(1), $passwords->issue(2));
        $this->assertSame([1, 2], $passwords->keptIds());
    }

    public function testForgettingRemovesOnlyThatArchivesEntry(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);
        $passwords->issue(1);
        $kept = $passwords->issue(2);

        $passwords->forget(1);
        $passwords->forget(99);

        $this->assertNull($passwords->passwordFor(1));
        $this->assertSame($kept, $passwords->passwordFor(2));
        $this->assertArrayNotHasKey('backup_password_1', $this->secrets->readSecrets());
    }

    /** A password typed before IT-03, carried by a queued task, is kept the same way. */
    public function testAPasswordFromAnOlderTaskCanBeKept(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);

        $passwords->store(7, 'typed by hand');

        $this->assertSame('typed by hand', $passwords->passwordFor(7));
    }

    /**
     * The full reset's password is revealed before the reset, possibly
     * twice: the same one both times, or one of the two opens nothing.
     */
    public function testTheFullResetPasswordIsGeneratedOnceAndStable(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);

        $this->assertNull($passwords->revealedFullResetPassword());
        $first = $passwords->fullResetPassword();

        $this->assertSame($first, $passwords->fullResetPassword());
        $this->assertSame($first, $passwords->revealedFullResetPassword());
        $this->assertSame([], $passwords->keptIds(), 'It is not an archive password.');
    }

    /** A safety copy is encrypted where the password can be both used and kept. */
    public function testASafetyCopyGetsAPasswordOnlyWhereItCanBeUsedAndKept(): void
    {
        $canEncrypt = $this->createStub(\Core\Maintenance\BackupServiceInterface::class);
        $canEncrypt->method('supportsZipEncryption')->willReturn(true);
        $cannot = $this->createStub(\Core\Maintenance\BackupServiceInterface::class);
        $cannot->method('supportsZipEncryption')->willReturn(false);

        $this->assertNotNull(BackupPasswords::forStorage($this->storage)->forSafetyCopy($canEncrypt));
        $this->assertNull(BackupPasswords::forStorage($this->storage)->forSafetyCopy($cannot));
        $this->assertNull(BackupPasswords::forStorage($this->storage . '/nowhere')->forSafetyCopy($canEncrypt));
    }

    public function testKeepingNoPasswordStoresNothing(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage);

        $passwords->keepFor(5, null);
        $passwords->keepFor(6, 'kept');

        $this->assertSame([6], $passwords->keptIds());
    }

    /** An installation without secrets yet has nothing kept, and nothing to forget. */
    public function testAnUninitialisedStoreHasNothingAndForgetsQuietly(): void
    {
        $passwords = BackupPasswords::forStorage($this->storage . '/nowhere');

        $this->assertNull($passwords->passwordFor(1));
        $this->assertSame([], $passwords->keptIds());
        $passwords->forget(1);
        $this->addToAssertionCount(1);
    }
}
