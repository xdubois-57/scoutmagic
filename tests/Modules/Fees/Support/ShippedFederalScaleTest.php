<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Support;

use Core\ExternalSource\ExternalSourceChecker;
use Core\ExternalSource\ExternalSources;
use Core\ExternalSource\FederalScale;
use Core\ExternalSource\FetchedPage;
use Core\ExternalSource\PageFetcherInterface;
use Modules\Fees\Support\ShippedFederalScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The federal scale shipped with the site (issue #355): the real file, read
 * strictly, and what `scripts/check-external-sources.php` makes of it.
 *
 * Loading the REAL file here is what makes a bad edit to
 * `modules/fees/data/federal-scale.json` fail the build rather than a page.
 */
final class ShippedFederalScaleTest extends TestCase
{
    private const VALID = [
        'year' => '2026-2027',
        'source_url' => 'https://www.lesscouts.be/fr/cotisations',
        'verified_on' => '2026-11-24',
        'amount_cents' => ['normal' => 5750, 'couple' => 4600, 'family' => 3900, 'iama' => 2500, 'guest' => 1825],
        'guest_period' => ['start' => '01/06', 'end' => '31/08'],
    ];

    public function testTheShippedFileIsTheCompleteSeasonVerifiedInNovember(): void
    {
        $scale = ShippedFederalScale::load();

        $this->assertSame('2026-2027', $scale->year);
        $this->assertSame(ExternalSources::FEES_PAGE, $scale->sourceUrl);
        $this->assertSame('2026-11-24', $scale->verifiedOn);
        $this->assertSame(self::VALID['amount_cents'], $scale->amountCents);
    }

    /** What the script hands the checker as its reference. */
    public function testItBecomesTheFederalScaleTheCheckComparesWith(): void
    {
        $this->assertEquals(
            new FederalScale(5750, 4600, 3900, 2500, 1825, '01/06', '31/08', '2026-2027'),
            ShippedFederalScale::load()->toFederalScale()
        );
    }

    /**
     * The script's own wiring: it must hand the command the shipped scale,
     * not nothing — the comparison is off otherwise and the report only says
     * « not compared » in a note nobody reads on a green run. What the
     * command then does with it is tested in ExternalSourcesCommandTest.
     */
    public function testTheCheckScriptPassesTheShippedScaleAsItsReference(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 4) . '/scripts/check-external-sources.php');

        $this->assertStringContainsString('ShippedFederalScale::load()->toFederalScale()', $script);
        $this->assertStringNotContainsString('referenceScale: null', $script);
    }

    /**
     * The fees page as it read on 24 November 2026 conforms to the shipped
     * scale; the same page a season on diverges, naming both.
     */
    public function testTheRecordedPageConformsAndAChangedOneDiverges(): void
    {
        $reference = ShippedFederalScale::load()->toFederalScale();
        $source = ExternalSources::byId(ExternalSources::FEES_PAGE_ID);
        $this->assertNotNull($source);

        $conform = self::checker('fees-conform.html', $reference)->check($source);
        $this->assertTrue($conform->isConform(), implode("\n", $conform->divergences));

        $changed = self::checker('fees-changed-amounts.html', $reference)->check($source);
        $this->assertFalse($changed->isConform());
        $this->assertStringContainsString('le barème livré annonce 2026-2027', implode("\n", $changed->divergences));
    }

    public function testAValidDocumentIsRead(): void
    {
        $scale = ShippedFederalScale::fromJson((string) json_encode(self::VALID));

        $this->assertSame('2026-2027', $scale->year);
        $this->assertSame(4600, $scale->amountCents['couple']);
    }

    public function testTheNewAmountsAndBoundsAreReadRatherThanDefaulted(): void
    {
        $data = self::VALID;
        $data['amount_cents']['iama'] = 2600;
        $data['amount_cents']['guest'] = 1900;
        $data['guest_period'] = ['start' => '12/06', 'end' => '15/09'];
        $scale = ShippedFederalScale::fromJson((string) json_encode($data));

        self::assertSame([2600, 1900, '12/06', '15/09'], [
            $scale->amountCents['iama'], $scale->amountCents['guest'],
            $scale->guestFromDayMonth, $scale->guestUntilDayMonth,
        ]);
    }

    public function testALeapDayInTheEndingYearIsValid(): void
    {
        $data = array_replace(self::VALID, [
            'year' => '2027-2028', 'guest_period' => ['start' => '29/02', 'end' => '31/08'],
        ]);
        $scale = ShippedFederalScale::fromJson((string) json_encode($data));

        self::assertSame(['2027-2028', '29/02', '31/08'], [
            $scale->year, $scale->guestFromDayMonth, $scale->guestUntilDayMonth,
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedProvider(): array
    {
        $with = static fn (array $changes): string => (string) json_encode(array_replace(self::VALID, $changes));
        $without = static function (string $key): string {
            $data = self::VALID;
            unset($data[$key]);

            return (string) json_encode($data);
        };

        return [
            'not JSON' => ['{"year": '],
            'a list' => ['[1, 2, 3]'],
            'a missing key' => [$without('verified_on')],
            'an unknown key' => [$with(['comment' => 'x'])],
            'a year that is not a scout year' => [$with(['year' => '2026-2028'])],
            'a year in another shape' => [$with(['year' => '2026/27'])],
            'an http source' => [$with(['source_url' => 'http://www.lesscouts.be/fr/cotisations'])],
            'a source that is not a URL' => [$with(['source_url' => 'lesscouts.be'])],
            'an impossible date' => [$with(['verified_on' => '2026-02-30'])],
            'a date in another format' => [$with(['verified_on' => '24/09/2026'])],
            'an amount in euros' => [$with(['amount_cents' => array_replace(self::VALID['amount_cents'], ['normal' => 57.5])])],
            'an amount as a string' => [$with(['amount_cents' => array_replace(self::VALID['amount_cents'], ['normal' => '5750'])])],
            'a missing category' => [$with(['amount_cents' => ['normal' => 5750, 'couple' => 4600]])],
            'an extra category' => [$with(['amount_cents' => array_replace(self::VALID['amount_cents'], ['solidarity' => 1000])])],
            'an implausible amount' => [$with(['amount_cents' => array_replace(self::VALID['amount_cents'], ['normal' => 999999])])],
            'missing IAmA' => [$with(['amount_cents' => array_diff_key(self::VALID['amount_cents'], ['iama' => true])])],
            'missing guests' => [$with(['amount_cents' => array_diff_key(self::VALID['amount_cents'], ['guest' => true])])],
            'missing period' => [$without('guest_period')],
            'period is not an object' => [$with(['guest_period' => null])],
            'missing start' => [$with(['guest_period' => ['end' => '31/08']])],
            'missing end with another key' => [$with(['guest_period' => ['start' => '01/06', 'other' => '31/08']])],
            'extra period key' => [$with(['guest_period' => ['start' => '01/06', 'end' => '31/08', 'year' => 2027]])],
            'numeric date' => [$with(['guest_period' => ['start' => 106, 'end' => '31/08']])],
            'non canonical date' => [$with(['guest_period' => ['start' => '1/6', 'end' => '31/08']])],
            'impossible period date' => [$with(['guest_period' => ['start' => '31/06', 'end' => '31/08']])],
            'reversed period' => [$with(['guest_period' => ['start' => '01/09', 'end' => '31/08']])],
            'leap day outside the ending year' => [$with(['guest_period' => ['start' => '29/02', 'end' => '31/08']])],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testAMalformedDocumentIsRefusedWhole(string $json): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ShippedFederalScale::fromJson($json);
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ShippedFederalScale::load(sys_get_temp_dir() . '/no-such-federal-scale-' . uniqid() . '.json');
    }

    private static function checker(string $fixture, FederalScale $reference): ExternalSourceChecker
    {
        $body = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/external-sources/' . $fixture);

        return new ExternalSourceChecker(new class (new FetchedPage(200, $body)) implements PageFetcherInterface {
            public function __construct(private readonly FetchedPage $page)
            {
            }

            public function fetch(string $url): FetchedPage
            {
                return $this->page;
            }
        }, $reference);
    }
}
