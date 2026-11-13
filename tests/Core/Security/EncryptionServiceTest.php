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

    public function testPassingTheBase64StringDirectlyIsRefused(): void
    {
        // The M1 bug: the 44-character base64 string handed straight to
        // OpenSSL was silently cut to 32 characters = 24 real bytes, a weaker
        // key than the decoded one. It used to be demonstrable as a decrypt
        // failure; it is now refused where the wrong key would be taken.
        $encoded = base64_encode(random_bytes(32));

        $this->expectException(\InvalidArgumentException::class);
        new EncryptionService($encoded, random_bytes(32));
    }

    /**
     * Anything but exactly 32 raw bytes. OpenSSL zero-pads a short key and
     * truncates a long one without a word, so each of these used to encrypt
     * « successfully » under a key nobody chose — the empty one under 32 NUL
     * bytes, known to everyone.
     *
     * @return array<string, array{string}>
     */
    public static function keysThatAreNotThirtyTwoBytes(): array
    {
        return [
            'empty' => [''],
            'one byte' => ['a'],
            '24 bytes (a truncated 32-character base64 string)' => [str_repeat('a', 24)],
            '31 bytes' => [str_repeat('a', 31)],
            '33 bytes' => [str_repeat('a', 33)],
            '44 bytes (a base64 string left undecoded)' => [str_repeat('a', 44)],
            '64 bytes' => [str_repeat('a', 64)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keysThatAreNotThirtyTwoBytes')]
    public function testTheConstructorRefusesAnEncryptionKeyThatIsNotThirtyTwoBytes(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encryption key');
        new EncryptionService($key, random_bytes(32));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keysThatAreNotThirtyTwoBytes')]
    public function testTheConstructorRefusesABlindIndexKeyThatIsNotThirtyTwoBytes(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blind index key');
        new EncryptionService(random_bytes(32), $key);
    }

    public function testTheConstructorAcceptsExactlyThirtyTwoBytes(): void
    {
        $service = new EncryptionService(str_repeat("\0", 32), str_repeat("\xff", 32));

        $this->assertSame('x', $service->decrypt($service->encrypt('x', 'ctx'), 'ctx'));
    }

    public function testTheRefusalNeverEchoesTheKey(): void
    {
        $key = 'super-secret-key-material-that-is-too-short';

        try {
            new EncryptionService($key, random_bytes(32));
            $this->fail('a key of the wrong length must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString($key, $e->getMessage());
            $this->assertStringNotContainsString(bin2hex($key), $e->getMessage());
            $this->assertStringNotContainsString(base64_encode($key), $e->getMessage());
        }
    }

    /**
     * What `secrets.enc` hands over when a key is absent, empty, not base64
     * or base64 of the wrong length. None of these may be turned into an
     * empty key and used.
     *
     * @return array<string, array{string}>
     */
    public static function encodedKeysThatAreNotThirtyTwoBytes(): array
    {
        return [
            'absent or empty' => [''],
            'not base64' => ['!!!not base64 at all!!!'],
            'base64 with a stray character' => [substr(base64_encode(random_bytes(32)), 0, 43) . '*'],
            'base64 of 24 bytes' => [base64_encode(random_bytes(24))],
            'base64 of 33 bytes' => [base64_encode(random_bytes(33))],
            'base64 of 16 zero bytes' => [base64_encode(str_repeat("\0", 16))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedKeysThatAreNotThirtyTwoBytes')]
    public function testFromEncodedKeysRefusesABadEncryptionKey(string $encoded): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EncryptionService::fromEncodedKeys($encoded, base64_encode(random_bytes(32)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedKeysThatAreNotThirtyTwoBytes')]
    public function testFromEncodedKeysRefusesABadBlindIndexKey(string $encoded): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EncryptionService::fromEncodedKeys(base64_encode(random_bytes(32)), $encoded);
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

    // ── normalizeEmailForIndex() ────────────────────────────────────────

    public function testNormalizeEmailForIndexTrimsAndLowercases(): void
    {
        $this->assertSame('jean@example.org', EncryptionService::normalizeEmailForIndex('  Jean@Example.ORG '));
    }

    public function testNormalizeEmailForIndexLowercasesNonAscii(): void
    {
        // strtolower() leaves the É alone (it is two bytes, neither of
        // which is an ASCII letter), mb_strtolower() does not. Accents are
        // NOT stripped: an address is an identifier, not a search term.
        $this->assertSame('josé@example.org', EncryptionService::normalizeEmailForIndex('JOSÉ@example.org'));
        $this->assertNotSame(strtolower('JOSÉ@example.org'), EncryptionService::normalizeEmailForIndex('JOSÉ@example.org'));
    }

    public function testNormalizeEmailForIndexTreatsNullAsEmpty(): void
    {
        $this->assertSame('', EncryptionService::normalizeEmailForIndex(null));
        $this->assertSame('', EncryptionService::normalizeEmailForIndex('   '));
    }

    /**
     * The failure this helper exists to prevent: a blind index is
     * exact-match and nothing else, so two spellings normalising
     * differently is a lookup that silently reports "not found".
     */
    public function testTwoSpellingsOfOneAddressShareOneBlindIndex(): void
    {
        $this->assertSame(
            $this->service->blindIndex(EncryptionService::normalizeEmailForIndex('Jean@Example.ORG '), 'email'),
            $this->service->blindIndex(EncryptionService::normalizeEmailForIndex('jean@example.org'), 'email')
        );
    }
}
