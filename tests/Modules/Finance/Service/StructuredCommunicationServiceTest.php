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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

    // --- isValid(): the other half of format() ---

    public function testAKeyOf97IsValidAndIsWhereANaiveCheckFails(): void
    {
        // THE trap. The check digits run 01–97, never 00: format() maps a
        // remainder of 0 onto 97. A `$base % 97 === $check` comparison
        // rejects this one, and only this one in a hundred — often enough
        // to be reported as a bug, rare enough to survive a hand test.
        $communication = StructuredCommunicationService::format('0000000097');

        $this->assertSame('+++000/0000/09797+++', $communication, 'precondition: this base really does key on 97');
        $this->assertTrue(StructuredCommunicationService::isValid($communication));
    }

    public function testAKeyOf00IsNeverValid(): void
    {
        // The mirror of the case above: 00 is the value the naive
        // computation would produce, and no issued communication carries it.
        $this->assertFalse(StructuredCommunicationService::isValid('+++000/0000/09700+++'));
    }

    public function testEveryCommunicationItIssuesValidates(): void
    {
        // The two halves must agree, whatever the random base.
        for ($i = 0; $i < 50; $i++) {
            $communication = $this->service->generate();
            $this->assertTrue(
                StructuredCommunicationService::isValid($communication),
                "generate() produced {$communication}, which isValid() rejects"
            );
        }
    }

    public function testItAcceptsEveryShapeSomebodyPlausiblyPastes(): void
    {
        $canonical = StructuredCommunicationService::format('1234567890');
        $digits = preg_replace('/\D/', '', $canonical) ?? '';

        $this->assertTrue(StructuredCommunicationService::isValid($canonical), 'canonical +++…+++');
        $this->assertTrue(StructuredCommunicationService::isValid($digits), 'twelve bare digits');
        $this->assertTrue(StructuredCommunicationService::isValid('***' . substr($digits, 0, 3) . '/' . substr($digits, 3, 4) . '/' . substr($digits, 7, 5) . '***'), '***…*** variant');
        $this->assertTrue(StructuredCommunicationService::isValid(' ' . chunk_split($digits, 4, ' ')), 'spaced out');
    }

    public function testItRejectsAWrongKey(): void
    {
        $digits = preg_replace('/\D/', '', StructuredCommunicationService::format('1234567890')) ?? '';
        $wrongKey = (int) substr($digits, 10, 2) === 42 ? '43' : '42';

        $this->assertFalse(StructuredCommunicationService::isValid(substr($digits, 0, 10) . $wrongKey));
    }

    public function testItRejectsAnythingThatIsNotTwelveDigits(): void
    {
        $this->assertFalse(StructuredCommunicationService::isValid(''));
        $this->assertFalse(StructuredCommunicationService::isValid('12345'), 'too short');
        $this->assertFalse(StructuredCommunicationService::isValid('1234567890123'), 'too long');
        $this->assertFalse(StructuredCommunicationService::isValid('Merci pour le camp'), 'a free-text communication');
    }

    // ── extract() ───────────────────────────────────────────────────────

    public function testItExtractsTheCanonicalFormFromABankLabel(): void
    {
        $this->assertSame(
            ['123456789012'],
            StructuredCommunicationService::extract('VIREMENT EUROPEEN +++123/4567/89012+++ DUPONT')
        );
    }

    public function testItExtractsEveryShapeABankPrints(): void
    {
        foreach ([
            '+++123/4567/89012+++',
            '***123/4567/89012***',
            '123/4567/89012',
            '123 4567 89012',
            '123.4567.89012',
            '123456789012',
        ] as $shape) {
            $this->assertSame(['123456789012'], StructuredCommunicationService::extract('COMM ' . $shape . ' FIN'), $shape);
        }
    }

    /**
     * The defect IT-01 removes, seen at the source: flattening a line to
     * its digits invents sequences that appear nowhere in it. Four
     * unrelated numbers reduce to "123456789012", and a substring search
     * called that a communication.
     */
    public function testItInventsNothingAcrossSeparators(): void
    {
        $this->assertSame([], StructuredCommunicationService::extract('Virement 12 dossier 3456 lot 7890 caisse 12'));
    }

    public function testACommunicationCannotStartOrEndInsideALongerRun(): void
    {
        // 123456789012 is in there — its key is wrong for the surrounding
        // windows, and it is not what this fifteen-digit number says.
        $this->assertNotContains('123456789012', StructuredCommunicationService::extract('COMPTE 712345678901234'));
    }

    /**
     * The one concession to a glued communication: a window inside a
     * longer run counts only when its own mod-97 check passes.
     */
    public function testAValidCommunicationGluedToOtherDigitsIsStillFound(): void
    {
        $digits = preg_replace('/\D/', '', StructuredCommunicationService::format('1234567890')) ?? '';

        $this->assertContains($digits, StructuredCommunicationService::extract('REF2026' . $digits));
    }

    public function testEveryTwelveDigitSequenceIsACandidateWhateverItsPosition(): void
    {
        $found = StructuredCommunicationService::extract('REF 987654321098 ET +++123/4567/89012+++');

        $this->assertSame(['987654321098', '123456789012'], $found);
    }

    public function testTheSameCommunicationTwiceIsReportedOnce(): void
    {
        $this->assertSame(
            ['123456789012'],
            StructuredCommunicationService::extract('+++123/4567/89012+++ rappel 123456789012')
        );
    }

    public function testATextWithoutAnyCommunicationYieldsNothing(): void
    {
        $this->assertSame([], StructuredCommunicationService::extract('Achat de materiel — Delhaize'));
        $this->assertSame([], StructuredCommunicationService::extract(''));
    }
}
