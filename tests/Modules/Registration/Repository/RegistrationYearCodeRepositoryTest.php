<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Modules\Registration\Repository\RegistrationYearCodeRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
class RegistrationYearCodeRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationYearCodeRepository $repository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->repository = new RegistrationYearCodeRepository($this->pdo);
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    public function testNoCodeIsInvalidByDefault(): void
    {
        $this->assertFalse($this->repository->isValidActiveCode($this->scoutYearId, 'ANYTHING'));
    }

    public function testRegenerateProducesAnActiveCodeAcceptedIgnoringCaseAndSeparators(): void
    {
        $code = $this->repository->regenerate($this->scoutYearId);

        $this->assertTrue($this->repository->isValidActiveCode($this->scoutYearId, $code));
        $this->assertTrue($this->repository->isValidActiveCode($this->scoutYearId, strtolower($code)));
        $this->assertTrue($this->repository->isValidActiveCode($this->scoutYearId, str_replace('-', '', $code)));
        $this->assertFalse($this->repository->isValidActiveCode($this->scoutYearId, 'ZZZZ-ZZZZ'));
    }

    public function testRegenerateInvalidatesThePreviousCodeImmediately(): void
    {
        $firstCode = $this->repository->regenerate($this->scoutYearId);
        $secondCode = $this->repository->regenerate($this->scoutYearId);

        $this->assertNotSame($firstCode, $secondCode);
        $this->assertFalse($this->repository->isValidActiveCode($this->scoutYearId, $firstCode));
        $this->assertTrue($this->repository->isValidActiveCode($this->scoutYearId, $secondCode));
    }

    public function testDeactivateStopsTheCodeFromMatchingButKeepsItVisible(): void
    {
        $code = $this->repository->regenerate($this->scoutYearId);
        $this->repository->deactivate($this->scoutYearId);

        $this->assertFalse($this->repository->isValidActiveCode($this->scoutYearId, $code));
        $stored = $this->repository->findForYear($this->scoutYearId);
        $this->assertNotNull($stored);
        $this->assertSame($code, $stored->code);
        $this->assertFalse($stored->isActive);
    }

    public function testCodeIsScopedToItsOwnScoutYear(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $code = $this->repository->regenerate($this->scoutYearId);

        $this->assertFalse($this->repository->isValidActiveCode($otherYearId, $code));
    }
}
