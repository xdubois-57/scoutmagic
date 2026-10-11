<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Document\SignerName;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * How the person who signs a contract or validates an inventory is named on
 * it (issue #825): by their account, which is what a tenant can make sense
 * of, and never by a totem.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SignerNameTest extends TestCase
{
    private UserAccountRepository $accounts;
    private SignerName $signerName;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->accounts = new UserAccountRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->signerName = new SignerName($this->accounts);
    }

    public function testTheAccountsFirstAndLastNameAreTheName(): void
    {
        $account = $this->accounts->create('marie@test.be');
        $this->accounts->updateProfile($account->id, 'Marie', 'Dupont');

        $this->assertSame('Marie Dupont', $this->signerName->forAccount($account->id));
    }

    public function testSurroundingSpacesDoNotReachTheDocument(): void
    {
        $account = $this->accounts->create('marie@test.be');
        $this->accounts->updateProfile($account->id, '  Marie ', ' Dupont  ');

        $this->assertSame('Marie Dupont', $this->signerName->forAccount($account->id));
    }

    public function testAnAccountWithOnlyOneNameIsNamedByIt(): void
    {
        $account = $this->accounts->create('marie@test.be');
        $this->accounts->updateProfile($account->id, 'Marie', '');

        $this->assertSame('Marie', $this->signerName->forAccount($account->id));
    }

    /** Null is the answer — the caller says « un gestionnaire » — never a guess. */
    public function testAnAccountWithNoNameHasNoName(): void
    {
        $account = $this->accounts->create('anonyme@test.be');

        $this->assertNull($this->signerName->forAccount($account->id));
    }

    public function testNobodyAndAnUnknownAccountHaveNoName(): void
    {
        $this->assertNull($this->signerName->forAccount(null));
        $this->assertNull($this->signerName->forAccount(987654));
    }
}
