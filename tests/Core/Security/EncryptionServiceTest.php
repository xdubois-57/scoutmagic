<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\DecryptionException;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;
    private string $encryptionKey;
    private string $blindIndexKey;

    protected function setUp(): void
    {
        $this->encryptionKey = random_bytes(32);
        $this->blindIndexKey = random_bytes(32);
        $this->service = new EncryptionService($this->encryptionKey, $this->blindIndexKey);
    }

    public function testEncryptDecryptRoundTripsShortString(): void
    {
        $plaintext = 'Hello';
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptDecryptRoundTripsEmptyString(): void
    {
        $plaintext = '';
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptDecryptRoundTripsLongString(): void
    {
        $plaintext = str_repeat('This is a long string for testing encryption. ', 100);
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testEncryptDecryptRoundTripsUnicode(): void
    {
        $plaintext = 'Héllo wörld! 日本語テスト 🎉';
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testDecryptWithWrongKeyThrowsDecryptionException(): void
    {
        $encrypted = $this->service->encrypt('secret data');

        $wrongKey = random_bytes(32);
        $wrongService = new EncryptionService($wrongKey, $this->blindIndexKey);

        $this->expectException(DecryptionException::class);
        $wrongService->decrypt($encrypted);
    }

    public function testDecryptWithCorruptedDataThrowsDecryptionException(): void
    {
        $this->expectException(DecryptionException::class);
        $this->service->decrypt('corrupted data that is long enough to pass length check!');
    }

    public function testDecryptWithTooShortDataThrowsDecryptionException(): void
    {
        $this->expectException(DecryptionException::class);
        $this->service->decrypt('short');
    }

    public function testEncryptProducesDifferentCiphertextForSamePlaintext(): void
    {
        $plaintext = 'same input';
        $encrypted1 = $this->service->encrypt($plaintext);
        $encrypted2 = $this->service->encrypt($plaintext);

        // Same plaintext should produce different ciphertext (random IV)
        $this->assertNotSame($encrypted1, $encrypted2);

        // But both should decrypt to the same value
        $this->assertSame($plaintext, $this->service->decrypt($encrypted1));
        $this->assertSame($plaintext, $this->service->decrypt($encrypted2));
    }

    public function testBlindIndexProducesConsistentOutputForSameInput(): void
    {
        $value = 'test@example.com';
        $index1 = $this->service->blindIndex($value);
        $index2 = $this->service->blindIndex($value);

        $this->assertSame($index1, $index2);
    }

    public function testBlindIndexProducesDifferentOutputForDifferentInputs(): void
    {
        $index1 = $this->service->blindIndex('alice@example.com');
        $index2 = $this->service->blindIndex('bob@example.com');

        $this->assertNotSame($index1, $index2);
    }

    public function testBlindIndexReturns64CharHexString(): void
    {
        $index = $this->service->blindIndex('test@example.com');

        $this->assertSame(64, strlen($index));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $index);
    }

    public function testBlindIndexWithDifferentKeyProducesDifferentResult(): void
    {
        $value = 'test@example.com';
        $index1 = $this->service->blindIndex($value);

        $differentKey = random_bytes(32);
        $otherService = new EncryptionService($this->encryptionKey, $differentKey);
        $index2 = $otherService->blindIndex($value);

        $this->assertNotSame($index1, $index2);
    }
}
