<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class StructuredCommunicationServiceTest extends TestCase
{
    private \PDO $pdo;
    private StructuredCommunicationService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new StructuredCommunicationService(new ExpectedReceivableRepository($this->pdo, $encryption));
    }

    public function testGenerateReturnsAValidFormat(): void
    {
        $communication = $this->service->generate();

        $this->assertMatchesRegularExpression('/^\+\+\+\d{3}\/\d{4}\/\d{5}\+\+\+$/', $communication);
    }

    public function testGenerateProducesAValidMod97CheckDigit(): void
    {
        $communication = $this->service->generate();

        $digits = preg_replace('/\D/', '', $communication);
        $base = (int) substr($digits, 0, 10);
        $check = (int) substr($digits, 10, 2);

        $expectedCheck = $base % 97;
        if ($expectedCheck === 0) {
            $expectedCheck = 97;
        }

        $this->assertSame($expectedCheck, $check);
    }

    public function testFormatKnownBaseProducesExpectedCheckDigits(): void
    {
        // 1000000000 % 97 == 34 — a fixed known-good example.
        $formatted = StructuredCommunicationService::format('1000000000');

        $this->assertSame('+++100/0000/00034+++', $formatted);
    }

    public function testFormatWhenRemainderIsZeroUses97AsCheckDigits(): void
    {
        // 970000000 is exactly divisible by 97.
        $formatted = StructuredCommunicationService::format('0970000000');

        $this->assertSame('+++097/0000/00097+++', $formatted);
    }

    public function testEachCallGeneratesADistinctCommunication(): void
    {
        $communications = [];
        for ($i = 0; $i < 20; $i++) {
            $communications[] = $this->service->generate();
        }

        $this->assertCount(20, array_unique($communications));
    }
}
