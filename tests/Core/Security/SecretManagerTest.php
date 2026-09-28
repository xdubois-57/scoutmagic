<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\DecryptionException;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;

class SecretManagerTest extends TestCase
{
    private string $tempDir;
    private string $masterKeyPath;
    private string $secretsPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/secret_manager_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->masterKeyPath = $this->tempDir . '/keys/master.key';
        $this->secretsPath = $this->tempDir . '/config/secrets.enc';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testIsInitializedReturnsFalseWhenSecretsFileDoesNotExist(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);

        $this->assertFalse($manager->isInitialized());
    }

    public function testIsInitializedReturnsFalseWhenOnlyMasterKeyExists(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $this->assertFalse($manager->isInitialized());
    }

    public function testIsInitializedReturnsTrueWhenBothFilesExist(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();
        $manager->writeSecrets(['db_host' => 'localhost']);

        $this->assertTrue($manager->isInitialized());
    }

    public function testGenerateMasterKeyCreates32ByteFile(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $this->assertFileExists($this->masterKeyPath);
        $this->assertSame(32, strlen(file_get_contents($this->masterKeyPath)));
    }

    public function testGenerateMasterKeyThrowsWhenFileAlreadyExists(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key already exists');

        $manager->generateMasterKey();
    }

    public function testWriteSecretsThenReadSecretsRoundTrips(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $secrets = [
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_name' => 'test_db',
            'db_user' => 'root',
            'db_password' => 'secret_password_123!@#',
            'smtp_host' => 'mail.example.com',
        ];

        $manager->writeSecrets($secrets);
        $readBack = $manager->readSecrets();

        $this->assertSame($secrets, $readBack);
    }

    /**
     * The file is replaced by a rename, never truncated in place: nothing
     * but the secrets file (and the lock beside it) is left in the directory.
     */
    public function testWriteSecretsLeavesNoTemporaryFileBehind(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $manager->writeSecrets(['a' => '1']);
        $manager->writeSecrets(['a' => '2']);

        $this->assertSame(['secrets.enc'], array_values(array_diff(scandir(dirname($this->secretsPath)), ['.', '..'])));
        $this->assertSame(['a' => '2'], $manager->readSecrets());
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($this->secretsPath)), -4));
        }
    }

    public function testUpdateSecretsChangesOneEntryAndKeepsTheOthers(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();
        $manager->writeSecrets(['smtp_password' => 'kept', 'old' => 'x']);

        $manager->updateSecrets(static function (array $secrets): array {
            unset($secrets['old']);
            $secrets['backup_password_1'] = 'new';

            return $secrets;
        });

        $this->assertSame(['smtp_password' => 'kept', 'backup_password_1' => 'new'], $manager->readSecrets());
    }

    /**
     * The change runs under an exclusive lock that another process would
     * wait for — flock() locks per open file, so a second handle in this
     * same process stands in for that other process.
     */
    public function testUpdateSecretsHoldsAnExclusiveLockWhileChanging(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();
        $manager->writeSecrets([]);
        $lockPath = $this->secretsPath . '.lock';
        $lockedDuringChange = null;

        $manager->updateSecrets(static function (array $secrets) use ($lockPath, &$lockedDuringChange): array {
            $other = fopen($lockPath, 'c');
            $lockedDuringChange = !flock($other, LOCK_EX | LOCK_NB);
            fclose($other);

            return $secrets;
        });

        $this->assertTrue($lockedDuringChange);
        $after = fopen($lockPath, 'c');
        $this->assertTrue(flock($after, LOCK_EX | LOCK_NB), 'The lock is released afterwards.');
        fclose($after);
    }

    /** A change that throws writes nothing and still releases the lock. */
    public function testUpdateSecretsWritesNothingWhenTheChangeFails(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();
        $manager->writeSecrets(['a' => '1']);

        try {
            $manager->updateSecrets(static fn (array $secrets): array => throw new \LogicException('no'));
            $this->fail('The exception should propagate.');
        } catch (\LogicException) {
        }

        $this->assertSame(['a' => '1'], $manager->readSecrets());
        $lock = fopen($this->secretsPath . '.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function testReadSecretsThrowsWhenMasterKeyIsMissing(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key file not found');

        $manager->readSecrets();
    }

    public function testReadSecretsThrowsWhenSecretsFileIsMissing(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secrets file not found');

        $manager->readSecrets();
    }

    public function testEncryptedFileIsNotReadableAsPlaintext(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();

        $secrets = ['db_password' => 'super_secret_value'];
        $manager->writeSecrets($secrets);

        $rawContent = file_get_contents($this->secretsPath);

        // The file should not contain the plaintext password
        $this->assertStringNotContainsString('super_secret_value', $rawContent);
        // The file should not contain valid JSON
        $decoded = json_decode($rawContent, true);
        $this->assertNull($decoded);
    }

    public function testReadSecretsWithWrongKeyThrows(): void
    {
        $manager = new SecretManager($this->masterKeyPath, $this->secretsPath);
        $manager->generateMasterKey();
        $manager->writeSecrets(['db_host' => 'localhost']);

        // Replace the master key with a different one
        file_put_contents($this->masterKeyPath, random_bytes(32));

        $this->expectException(DecryptionException::class);

        $manager->readSecrets();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
