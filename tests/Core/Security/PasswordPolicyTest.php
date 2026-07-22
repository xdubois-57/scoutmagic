<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function testFullyCompliantPasswordHasNoViolations(): void
    {
        $this->assertSame([], PasswordPolicy::violations('MySecureP@ss1'));
        $this->assertTrue(PasswordPolicy::isValid('MySecureP@ss1'));
    }

    public function testTooShortPasswordViolatesLength(): void
    {
        $this->assertContains('length', PasswordPolicy::violations('Sh0rt!'));
        $this->assertFalse(PasswordPolicy::isValid('Sh0rt!'));
    }

    public function testMissingUppercaseIsFlagged(): void
    {
        $this->assertContains('uppercase', PasswordPolicy::violations('mysecurepass1!'));
    }

    public function testMissingLowercaseIsFlagged(): void
    {
        $this->assertContains('lowercase', PasswordPolicy::violations('MYSECUREPASS1!'));
    }

    public function testMissingDigitIsFlagged(): void
    {
        $this->assertContains('digit', PasswordPolicy::violations('MySecurePass!'));
    }

    public function testMissingSymbolIsFlagged(): void
    {
        $this->assertContains('symbol', PasswordPolicy::violations('MySecurePass1'));
    }

    public function testEmptyPasswordViolatesEveryRule(): void
    {
        $violations = PasswordPolicy::violations('');
        $this->assertCount(5, $violations);
    }
}
