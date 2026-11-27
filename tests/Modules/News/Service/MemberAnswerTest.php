<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\News\Service\MemberAnswer;
use PHPUnit\Framework\TestCase;

/**
 * The stored shape of a « Membres » answer: Desk ids, as JSON, and
 * nothing a reader could mistake for something else (issue #1381).
 */
final class MemberAnswerTest extends TestCase
{
    public function testOneIdAndSeveralIdsHaveTheSameShape(): void
    {
        $this->assertSame('["D-1001"]', MemberAnswer::encode(['D-1001']));
        $this->assertSame('["D-1001","D-1003"]', MemberAnswer::encode(['D-1001', 'D-1003']));
        $this->assertSame(['D-1001', 'D-1003'], MemberAnswer::decode('["D-1001","D-1003"]'));
    }

    public function testNoChoiceIsTheEmptyString(): void
    {
        $this->assertSame('', MemberAnswer::encode([]));
        $this->assertSame('', MemberAnswer::encode(['']));
        $this->assertSame([], MemberAnswer::decode(''));
        $this->assertSame([], MemberAnswer::decode(null));
    }

    /**
     * The separator a manual checkbox answer uses, a quote, a bracket, an
     * accent: whatever a code holds comes back exactly as it went in, and
     * as many codes as went in.
     */
    public function testAnyCharacterRoundTripsWithoutAmbiguity(): void
    {
        $ids = ['A, B', 'C"D', '[E]', 'É\\F', '0123', '123'];

        $this->assertSame($ids, MemberAnswer::decode(MemberAnswer::encode($ids)));
    }

    public function testTheSameIdTwiceIsOneChoice(): void
    {
        $this->assertSame(['D-1001'], MemberAnswer::decode(MemberAnswer::encode(['D-1001', 'D-1001'])));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notAnAnswer(): array
    {
        return [
            'plain text' => ['Castor (Zoé Lambert)'],
            'comma-joined' => ['D-1001, D-1002'],
            'a JSON object' => ['{"a":"D-1001"}'],
            'a number' => ['[1001]'],
            'nested' => ['[["D-1001"]]'],
            'an empty code' => ['["D-1001",""]'],
            'broken JSON' => ['["D-1001"'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAnAnswer')]
    public function testAnythingElseReadsAsNoChoiceNeverAsAGuess(string $stored): void
    {
        $this->assertSame([], MemberAnswer::decode($stored));
    }
}
