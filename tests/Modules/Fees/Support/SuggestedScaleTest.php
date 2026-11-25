<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Support;

use Core\Security\SessionStore;
use Modules\Fees\Support\SuggestedScale;
use Modules\Fees\Value\FederalScaleLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SuggestedScaleTest extends TestCase
{
    protected function setUp(): void
    {
        SuggestedScale::clear();
    }

    protected function tearDown(): void
    {
        SuggestedScale::clear();
    }

    public static function invalidProposals(): iterable
    {
        yield 'another season' => [['year' => '2025-2026']];
        yield 'noncanonical season' => [['year' => '2026/2027']];
        yield 'HTTP source' => [['url' => 'http://example.invalid/scale']];
        yield 'script source' => [['url' => 'javascript:alert(1)']];
        yield 'source array' => [['url' => []]];
        yield 'missing token' => [['token' => null]];
        yield 'malformed token' => [['token' => 'forged']];
        yield 'unknown tariff' => [['amount_cents' => ['other' => 1000]]];
        yield 'string amount' => [['amount_cents' => ['guest' => '1825']]];
        yield 'below source floor' => [['amount_cents' => ['guest' => 99]]];
        yield 'above source ceiling' => [['amount_cents' => ['guest' => 50001]]];
        yield 'amounts scalar' => [['amount_cents' => '1825']];
        yield 'start array' => [['guest_from' => []]];
        yield 'end array' => [['guest_until' => []]];
        yield 'invalid day' => [['guest_from' => '31/06']];
        yield 'noncanonical day' => [['guest_from' => '1/6']];
        yield 'inverted period' => [['guest_from' => '01/09', 'guest_until' => '31/08']];
        yield 'no usable value' => [['amount_cents' => [], 'guest_from' => null, 'guest_until' => null]];
    }

    #[DataProvider('invalidProposals')]
    public function testMalformedSessionStateNeverBecomesAnApplicableProposal(array $changes): void
    {
        SuggestedScale::set(FederalScaleLookup::found(
            'https://example.invalid/scale', '2026-2027', ['guest' => 1825], '01/06', '31/08'
        ));
        SessionStore::set('_fees_suggested_scale', array_replace(
            SessionStore::get('_fees_suggested_scale'), $changes
        ));
        self::assertNull(SuggestedScale::peek('2026-2027'));
    }

    public function testADateOnlyProposalKeepsAllPricesAndTheOtherBound(): void
    {
        SuggestedScale::set(FederalScaleLookup::found(
            'https://example.invalid/scale', '2026-2027', [], '02/06'
        ));
        $proposal = SuggestedScale::peek('2026-2027');
        $draft = SuggestedScale::apply('2026-2027', $proposal['token'], [
            'scale_year' => '2026-2027', 'amount_guest' => '0',
            'guest_from' => '01/06', 'guest_until' => '15/09',
            'fee_category_guest' => '', 'unrelated' => 'ignore', 'amount_normal' => ['bad shape'],
        ], true);
        self::assertSame([
            'scale_year' => '2026-2027', 'guest_from' => '02/06', 'guest_until' => '15/09',
            'amount_guest' => '0', 'fee_category_guest' => '',
        ], $draft);
        self::assertSame($draft, SuggestedScale::draftFor('2026-2027'));
        self::assertNull(SuggestedScale::draftFor('2025-2026'));
    }

    public function testFailureKeepsTheDraftAndDeclineIsOneShot(): void
    {
        $draft = ['scale_year' => '2026-2027', 'amount_iama' => '', 'amount_guest' => '0'];
        SuggestedScale::rememberDraft('2026-2027', $draft);
        SuggestedScale::set(FederalScaleLookup::unreadable('https://example.invalid/scale'));
        self::assertSame([true, false, $draft, null], [
            SuggestedScale::takeDeclined(), SuggestedScale::takeDeclined(),
            SuggestedScale::draftFor('2026-2027'), SuggestedScale::peek('2026-2027'),
        ]);
        SuggestedScale::rememberDraft('2027-2028', $draft);
        self::assertSame($draft, SuggestedScale::draftFor('2026-2027'));
        self::assertNull(SuggestedScale::draftFor('2027-2028'));
        SuggestedScale::clear();
        self::assertNull(SuggestedScale::draftFor('2026-2027'));
    }
}
