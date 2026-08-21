<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportHistoryPeriod;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's whole view state is the query string and nothing else
 * (ARCHITECTURE.md §8.50) — which also makes the query string the one
 * untrusted input the page reads on every visit, from a URL anybody can
 * hand a superadmin.
 */
class SupportDashboardFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
    }

    public function testTheDefaultViewIsActiveInstallationsNewestFirst(): void
    {
        $filters = SupportDashboardFilters::defaults();

        $this->assertSame(SupportDashboardFilters::STATUS_ACTIVE, $filters->status);
        $this->assertSame(SupportDashboardFilters::SORT_LAST_RECEIVED, $filters->sort);
        $this->assertTrue($filters->descending);
        $this->assertSame(1, $filters->page);
        $this->assertSame('', $filters->search);
        $this->assertSame('', $filters->queryString(), 'the default view must have an empty query string');
    }

    /**
     * `?q[]=x` and `?page[]=1` reach fromQuery() as arrays. `q` was cast
     * with `(string)`, which raises "Array to string conversion" in the
     * error log and leaves the literal search term "Array" filtering the
     * table — noise in one place, a nonsense filter in the other.
     *
     * @param array<string, mixed> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('arrayShapedQueries')]
    public function testAnArrayShapedParameterFallsBackToItsDefault(array $query): void
    {
        $filters = SupportDashboardFilters::fromQuery($query);

        $this->assertSame('', $filters->search);
        $this->assertSame(1, $filters->page);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function arrayShapedQueries(): array
    {
        return [
            'array search' => [['q' => ['x']]],
            'array page' => [['page' => ['2']]],
            'both arrays' => [['q' => ['x'], 'page' => ['2']]],
            'nested array' => [['q' => [['deep']]]],
        ];
    }

    public function testAnUnknownStatusOrSortFallsBackRatherThanBeingObeyed(): void
    {
        $filters = SupportDashboardFilters::fromQuery(['status' => 'deleted', 'sort' => 'secret_hash']);

        $this->assertSame(SupportDashboardFilters::STATUS_ACTIVE, $filters->status);
        $this->assertSame(SupportDashboardFilters::SORT_LAST_RECEIVED, $filters->sort);
    }

    public function testAPageBelowOneIsClampedRatherThanUsedAsAnOffset(): void
    {
        $this->assertSame(1, SupportDashboardFilters::fromQuery(['page' => '-5'])->page);
        $this->assertSame(1, SupportDashboardFilters::fromQuery(['page' => '0'])->page);
        $this->assertSame(3, SupportDashboardFilters::fromQuery(['page' => '3'])->page);
    }

    public function testTheSearchTermIsBoundedAndTrimmed(): void
    {
        $filters = SupportDashboardFilters::fromQuery(['q' => '  ' . str_repeat('é', 300) . '  ']);

        $this->assertSame(120, mb_strlen($filters->search));
    }

    public function testTheModuleStateIsATriStateNotABoolean(): void
    {
        $this->assertTrue(SupportDashboardFilters::fromQuery(['module_state' => 'enabled'])->moduleEnabled);
        $this->assertFalse(SupportDashboardFilters::fromQuery(['module_state' => 'disabled'])->moduleEnabled);
        $this->assertNull(SupportDashboardFilters::fromQuery(['module_state' => ''])->moduleEnabled);
        $this->assertNull(SupportDashboardFilters::fromQuery(['module_state' => 'peut-être'])->moduleEnabled);
    }

    /**
     * A link that dropped the filters the user is looking at would send
     * them to a different table than the one they clicked in.
     */
    public function testQueryStringRoundTripsEveryFilterAndAcceptsOverrides(): void
    {
        $query = [
            'q' => 'exemple',
            'status' => 'all',
            'version' => '1.0.33 (dev)',
            'installation_method' => 'layout_b',
            'auto_update' => 'patch',
            'module' => 'calendar',
            'module_state' => 'enabled',
            'sort' => 'active_members',
            'dir' => 'asc',
            'page' => '4',
        ];

        $filters = SupportDashboardFilters::fromQuery($query);
        parse_str(ltrim($filters->queryString(), '?'), $roundTripped);

        $this->assertSame($query, $roundTripped);

        parse_str(ltrim($filters->queryString(['page' => null]), '?'), $withoutPage);
        $this->assertArrayNotHasKey('page', $withoutPage);
    }

    /**
     * The auto-update filter travels as a technical key, never as the
     * French label the page renders: a URL is not a translation artefact,
     * and a filter keyed on displayed text breaks the day the wording
     * improves.
     */
    public function testTheAutoUpdateFilterTravelsAsATechnicalKey(): void
    {
        $filters = SupportDashboardFilters::fromQuery(['auto_update' => 'disabled']);

        $this->assertSame('disabled', $filters->autoUpdate);
        $this->assertStringContainsString('auto_update=disabled', $filters->queryString());
        $this->assertStringNotContainsString('sactiv', $filters->queryString());
    }

    // ---------------------------------------------------------------
    // The history period is its own type, deliberately
    // ---------------------------------------------------------------

    public function testTheHistoryPeriodDefaultsToTwelveMonths(): void
    {
        $this->assertSame(SupportHistoryPeriod::DEFAULT_MONTHS, SupportHistoryPeriod::default()->months);
        $this->assertSame('12', SupportHistoryPeriod::default()->value());
    }

    public function testOnlyTheDeclaredChoicesAndAllAreAccepted(): void
    {
        foreach (SupportHistoryPeriod::CHOICES as $months) {
            $this->assertSame($months, SupportHistoryPeriod::fromQuery(['history' => (string) $months])->months);
        }

        $this->assertNull(SupportHistoryPeriod::fromQuery(['history' => 'all'])->months);
        $this->assertNull(SupportHistoryPeriod::fromQuery(['history' => ' ALL '])->months);
        $this->assertSame(12, SupportHistoryPeriod::fromQuery(['history' => '7'])->months);
        $this->assertSame(12, SupportHistoryPeriod::fromQuery(['history' => ['24']])->months);
    }

    public function testTheHistoryPeriodLabelsItselfInFrench(): void
    {
        $this->assertSame('6 mois', SupportHistoryPeriod::fromQuery(['history' => '6'])->label());
        $this->assertSame("Tout l'historique", SupportHistoryPeriod::fromQuery(['history' => 'all'])->label());
    }

    /**
     * The history takes only its own period. Sharing one object with the
     * current-state filters is exactly how "the history is independent"
     * quietly stops being true.
     */
    public function testTheHistoryPeriodIgnoresEveryCurrentStateFilter(): void
    {
        $query = ['q' => 'exemple', 'status' => 'stale', 'version' => '1.0.30', 'page' => '4'];

        $this->assertSame(
            SupportHistoryPeriod::default()->months,
            SupportHistoryPeriod::fromQuery($query)->months
        );
    }

    public function testTheFiltersQueryStringNeverCarriesTheHistoryPeriod(): void
    {
        $filters = SupportDashboardFilters::fromQuery(['q' => 'exemple', 'history' => '24']);

        $this->assertStringNotContainsString('history', $filters->queryString());
    }
}
