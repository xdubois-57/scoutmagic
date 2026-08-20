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

    public function testFromEncodedKeysDecodesBase64ToRawBytesForFullStrength(): void
    {
        // secrets.enc holds base64(random_bytes(32)); fromEncodedKeys must
        // decode it back to the raw 32 bytes so it round-trips with a service
        // built directly on those raw bytes (audit M1).
        $rawKey = random_bytes(32);
        $rawBlind = random_bytes(32);

        $fromRaw = new EncryptionService($rawKey, $rawBlind);
        $fromEncoded = EncryptionService::fromEncodedKeys(base64_encode($rawKey), base64_encode($rawBlind));

        // A ciphertext produced by one must decrypt under the other.
        $this->assertSame('shared-secret-check', $fromEncoded->decrypt($fromRaw->encrypt('shared-secret-check')));
        $this->assertSame(
            $fromRaw->blindIndex('member@example.test'),
            $fromEncoded->blindIndex('member@example.test'),
            'decoded blind-index key must match the raw one'
        );
    }

    public function testPassingTheBase64StringDirectlyProducesADifferentWeakerKey(): void
    {
        // Demonstrates the M1 bug: passing the 44-char base64 string straight
        // to OpenSSL (as the old boot code did) truncates to 32 chars = 24
        // real bytes, a DIFFERENT key than the correctly-decoded one — so a
        // ciphertext from the decoded service does not decrypt under the
        // buggy one.
        $rawKey = random_bytes(32);
        $rawBlind = random_bytes(32);
        $encoded = base64_encode($rawKey);

        $correct = EncryptionService::fromEncodedKeys($encoded, base64_encode($rawBlind));
        $buggy = new EncryptionService($encoded, base64_encode($rawBlind));

        $cipher = $correct->encrypt('secret');
        $this->expectException(DecryptionException::class);
        $buggy->decrypt($cipher);
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

    // --- AAD context binding (audit: ciphertext relocation) ---

    public function testDecryptWithTheSameContextRoundTrips(): void
    {
        $encrypted = $this->service->encrypt('secret-key-material', 'llm_providers.api_key');

        $this->assertSame('secret-key-material', $this->service->decrypt($encrypted, 'llm_providers.api_key'));
    }

    public function testDecryptWithADifferentContextFails(): void
    {
        // The relocation attack: a ciphertext copied into another column
        // (here: an API key moved into a user-visible label column) must
        // fail authentication, not decrypt where it can be read back.
        $encrypted = $this->service->encrypt('secret-key-material', 'llm_providers.api_key');

        $this->expectException(DecryptionException::class);
        $this->service->decrypt($encrypted, 'finance_transactions.label');
    }

    public function testDecryptWithoutContextFailsForAContextBoundCiphertext(): void
    {
        $encrypted = $this->service->encrypt('secret', 'user_accounts.email');

        $this->expectException(DecryptionException::class);
        $this->service->decrypt($encrypted);
    }

    public function testDecryptWithAContextFailsForAContextlessCiphertext(): void
    {
        $encrypted = $this->service->encrypt('secret');

        $this->expectException(DecryptionException::class);
        $this->service->decrypt($encrypted, 'user_accounts.email');
    }

    public function testEmptyContextKeepsTheLegacyBehaviour(): void
    {
        $encrypted = $this->service->encrypt('legacy');

        $this->assertSame('legacy', $this->service->decrypt($encrypted));
    }

    // --- Blind-index domain separation ---

    public function testBlindIndexWithDifferentPurposesIsUnlinkable(): void
    {
        // The same plaintext under two purposes must yield unrelated
        // indexes, so a DB reader cannot link a value across unrelated
        // columns.
        $email = $this->service->blindIndex('alice@example.com', 'email');
        $newsContact = $this->service->blindIndex('alice@example.com', 'news_contact_email');

        $this->assertNotSame($email, $newsContact);
    }

    public function testBlindIndexPurposeIsNotAPlainConcatenation(): void
    {
        // The NUL separator means purpose/value splits can never collide —
        // and a purposed index never equals the legacy unlabelled index of
        // any crafted string.
        $this->assertNotSame(
            $this->service->blindIndex('b', 'a'),
            $this->service->blindIndex('a:b')
        );
        $this->assertNotSame(
            $this->service->blindIndex('b', 'a'),
            $this->service->blindIndex('ab')
        );
    }

    public function testBlindIndexWithPurposeIsDeterministic(): void
    {
        $this->assertSame(
            $this->service->blindIndex('value', 'purpose'),
            $this->service->blindIndex('value', 'purpose')
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->service->blindIndex('value', 'purpose'));
    }
}
