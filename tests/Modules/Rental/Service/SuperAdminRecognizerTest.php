<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Service\SuperAdminRecognizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Who is a superadmin for this module (issue #1355, D2): the flag of the
 * account behind an address, whatever session — if any — asks.
 */
#[Group('database')]
final class SuperAdminRecognizerTest extends TestCase
{
    private UserAccountRepository $accounts;

    protected function setUp(): void
    {
        $this->accounts = new UserAccountRepository(
            DatabaseTestHelper::createTestDatabase(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testTheAccountsFlagMakesTheSuperadmin(): void
    {
        $this->accounts->create('super@test.be', true);
        $this->accounts->create('admin@test.be');

        $recognizer = new SuperAdminRecognizer($this->accounts);

        $this->assertTrue($recognizer->isSuperAdmin('super@test.be'));
        $this->assertTrue($recognizer->isSuperAdmin(' Super@Test.be '));
        $this->assertFalse($recognizer->isSuperAdmin('admin@test.be'));
        $this->assertFalse($recognizer->isSuperAdmin('inconnu@test.be'));
        $this->assertFalse($recognizer->isSuperAdmin(''));
        $this->assertFalse($recognizer->isSuperAdmin(null));
    }

    /** A deactivated superadmin cannot log in, and opens nothing here either. */
    public function testADeactivatedSuperadminIsNoSuperadmin(): void
    {
        $account = $this->accounts->create('super@test.be', true);
        $this->accounts->deactivate($account->id);

        $this->assertFalse((new SuperAdminRecognizer($this->accounts))->isSuperAdmin('super@test.be'));
    }
}
