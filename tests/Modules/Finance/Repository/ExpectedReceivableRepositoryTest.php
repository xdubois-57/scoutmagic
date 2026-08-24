<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * Finding the receivable a payment's communication refers to.
 *
 * The column holds the issued form, `+++NNN/NNNN/NNNNN+++`, while a human
 * checking a payment types whatever their bank showed them. Every case
 * below is about that gap: a raw comparison finds nothing for most real
 * inputs, and finds it silently — the answer is "unknown", not an error,
 * so the person concludes the payment was never expected.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ExpectedReceivableRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ExpectedReceivableRepository $repository;
    private string $communication;
    private int $receivableId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new ExpectedReceivableRepository($this->pdo, $encryption);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $accountId = (int) $this->pdo->lastInsertId();

        $this->communication = StructuredCommunicationService::format('1234567890');
        $this->receivableId = $this->repository->create('news', 42, $accountId, 2500, $this->communication, 'Camp 2026');
    }

    public function testItFindsAReceivableByItsIssuedForm(): void
    {
        $found = $this->repository->findByCommunication($this->communication);

        $this->assertNotNull($found);
        $this->assertSame($this->receivableId, $found->id);
        $this->assertSame(2500, $found->amountDueCents);
    }

    public function testItFindsTheSameReceivableFromWhateverTheBankShowed(): void
    {
        $digits = preg_replace('/\D/', '', $this->communication) ?? '';

        foreach ([
            'twelve bare digits' => $digits,
            'the ***…*** variant' => '***' . substr($digits, 0, 3) . '/' . substr($digits, 3, 4) . '/' . substr($digits, 7, 5) . '***',
            'spaced out' => trim(chunk_split($digits, 4, ' ')),
            'pasted with stray spaces' => '  ' . $this->communication . ' ',
        ] as $shape => $input) {
            $found = $this->repository->findByCommunication($input);

            $this->assertNotNull($found, "should have matched {$shape}");
            $this->assertSame($this->receivableId, $found->id, $shape);
        }
    }

    public function testAnUnknownCommunicationIsNullAndNotAnError(): void
    {
        // Well-formed, simply never issued here — the honest answer for
        // the payment checker is "we are not waiting for this".
        $found = $this->repository->findByCommunication(StructuredCommunicationService::format('9876543210'));

        $this->assertNull($found);
    }

    public function testSomethingThatIsNotTwelveDigitsCannotMatchAndDoesNotQuery(): void
    {
        // No issued communication has any other length, so there is
        // nothing a query could find; the short-circuit keeps a free-text
        // payment note out of the WHERE clause entirely.
        $this->assertNull($this->repository->findByCommunication(''));
        $this->assertNull($this->repository->findByCommunication('12345'));
        $this->assertNull($this->repository->findByCommunication('Merci pour le camp'));
    }

    public function testItDoesNotMatchOnAPrefix(): void
    {
        // An exact match, never a LIKE: a communication that merely
        // starts the same is a different receivable's.
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Autre', 'bank')");
        $otherAccountId = (int) $this->pdo->lastInsertId();
        $neighbour = StructuredCommunicationService::format('1234567891');
        $this->repository->create('news', 43, $otherAccountId, 100, $neighbour, 'Autre');

        $found = $this->repository->findByCommunication($neighbour);

        $this->assertNotNull($found);
        $this->assertNotSame($this->receivableId, $found->id);
    }
}
