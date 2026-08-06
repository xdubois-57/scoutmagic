<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Notification\VapidKeyPairFactory;
use PHPUnit\Framework\TestCase;

class VapidKeyPairFactoryTest extends TestCase
{
    public function testCreateValidProducesAKeyPairThatPassesItsOwnValidation(): void
    {
        $keys = VapidKeyPairFactory::createValid();

        $this->assertTrue(VapidKeyPairFactory::isValid($keys['publicKey'], $keys['privateKey']));
    }

    public function testCreateValidProducesADifferentKeyPairEachCall(): void
    {
        $first = VapidKeyPairFactory::createValid();
        $second = VapidKeyPairFactory::createValid();

        $this->assertNotSame($first['publicKey'], $second['publicKey']);
    }

    public function testIsValidReturnsFalseForEmptyStrings(): void
    {
        $this->assertFalse(VapidKeyPairFactory::isValid('', ''));
    }

    public function testIsValidReturnsFalseForGarbageKeys(): void
    {
        $this->assertFalse(VapidKeyPairFactory::isValid('not-a-key', 'also-not-a-key'));
    }

    /**
     * Regression: this is exactly the failure mode observed in the wild —
     * a public key that decodes to fewer than the required 65 bytes.
     * base64url-encoding 40 raw bytes (not 65) reproduces it directly,
     * without depending on the host's own OpenSSL behavior to trigger it.
     */
    public function testIsValidReturnsFalseForAPublicKeyShorterThanSixtyFiveBytes(): void
    {
        $tooShort = rtrim(strtr(base64_encode(str_repeat('a', 40)), '+/', '-_'), '=');
        $validPrivate = VapidKeyPairFactory::createValid()['privateKey'];

        $this->assertFalse(VapidKeyPairFactory::isValid($tooShort, $validPrivate));
    }
}
