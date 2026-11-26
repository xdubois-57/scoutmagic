<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Maintenance\Health\Security\Advisory;
use Core\Maintenance\Health\Security\AdvisoryLookup;
use Core\Maintenance\Health\Security\ComponentAssessment;
use Core\Maintenance\Health\Security\ComponentFamily;
use Core\Maintenance\Health\Security\HostSecurityService;
use Core\Maintenance\Health\Security\HostSecurityStore;
use Core\Maintenance\Health\Security\ObservedComponent;
use Core\Maintenance\Health\Security\ReleaseBranch;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One run of the check against synthetic sources: the cache, a failing
 * source, the NVD's request budget, and the snapshot it stores.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HostSecurityServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const PHP_CPE = 'cpe:2.3:a:php:php:8.4.1:*:*:*:*:*:*:*';

    private HostSecurityStore $store;
    private SettingService $settings;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->store = new HostSecurityStore($this->settings);
        $this->store->register();
    }

    public function testARunStoresADatedSnapshotThePageCanReadBack(): void
    {
        $source = $this->source([self::PHP_CPE => [new Advisory('CVE-2026-0001', 9.8, '3.1', 'nvd@nist.gov', 'u')]]);

        $snapshot = $this->service($source)->refresh([$this->php('php_web', '8.4.1')], self::NOW);

        $this->assertTrue($snapshot->complete);
        $stored = (new HostSecurityStore(new SettingService(new SettingRepository($this->pdo))))->latest();
        $this->assertNotNull($stored);
        $this->assertSame(self::NOW, $stored->checkedAt);
        $this->assertSame(ComponentAssessment::STATE_VULNERABLE, $stored->find('php_web')?->state);
        $this->assertSame(['CVE-2026-0001'], $stored->find('php_web')?->highAdvisoryIds);
    }

    /** The web and the cron sharing a version are one request, not two. */
    public function testEachSourceIsAskedOncePerRunWhateverTheContexts(): void
    {
        $source = $this->source([]);

        $this->service($source)->refresh([$this->php('php_web', '8.4.1'), $this->php('php_cron', '8.4.1')], self::NOW);

        $this->assertSame(['php.net', 'nvd:' . self::PHP_CPE], $source->requests);
    }

    /**
     * A second run within the week is served from the cache — what lets the
     * one-minute continuation ask only for what is missing.
     */
    public function testAFreshCacheIsNotFetchedAgain(): void
    {
        $source = $this->source([]);
        $this->service($source)->refresh([$this->php('php_web', '8.4.1')], self::NOW);
        $source->requests = [];

        $this->service($source)->refresh([$this->php('php_web', '8.4.1')], self::NOW + 60);

        $this->assertSame([], $source->requests);
    }

    /**
     * The NVD without a key takes five requests per thirty seconds: a run
     * asks four, marks the rest pending and says it is incomplete.
     */
    public function testTheNvdIsAskedAtMostFourTimesAndTheRestWaitsForTheNextPass(): void
    {
        $source = $this->source([]);
        $components = [];
        foreach (['8.4.1', '8.4.2', '8.4.3', '8.4.4', '8.4.5', '8.4.6'] as $i => $version) {
            $components[] = $this->php($i % 2 === 0 ? 'php_web' : 'php_cron', $version);
        }

        $first = $this->service($source)->refresh($components, self::NOW);

        $this->assertCount(HostSecurityService::NVD_REQUESTS_PER_RUN, $source->nvdRequests());
        $this->assertFalse($first->complete);
        $this->assertContains(AdvisoryLookup::PENDING, array_map(static fn(ComponentAssessment $a): string => $a->advisoryStatus, $first->assessments));

        $source->requests = [];
        $second = $this->service($source)->refresh($components, self::NOW + 60);

        $this->assertCount(2, $source->nvdRequests(), 'only what the first pass left');
        $this->assertTrue($second->complete);
    }

    /**
     * A source that fails keeps its last answer, dated: a vulnerable
     * component stays vulnerable rather than turning « À jour ».
     */
    public function testAFailingSourceKeepsTheLastUsableAnswerDated(): void
    {
        $source = $this->source([self::PHP_CPE => [new Advisory('CVE-2026-0001', 9.8, '3.1', null, 'u')]]);
        $this->service($source)->refresh([$this->php('php_web', '8.4.1')], self::NOW);
        $source->failing = ['php.net' => true, 'nvd' => true];

        $later = $this->service($source)->refresh([$this->php('php_web', '8.4.1')], self::NOW + 7 * 86400);

        $php = $later->find('php_web');
        $this->assertSame(ComponentAssessment::STATE_VULNERABLE, $php?->state);
        $this->assertSame(AdvisoryLookup::STALE, $php->advisoryStatus);
        $this->assertSame(self::NOW, $php->advisoriesCheckedAt);
        $this->assertNotEmpty(array_filter($php->notes, static fn(string $n): bool => str_contains($n, 'n\'a pas répondu depuis')));
    }

    public function testASourceSilentForLongerThanAMonthIsNoLongerBelieved(): void
    {
        $source = $this->source([self::PHP_CPE => [new Advisory('CVE-2026-0001', 9.8, '3.1', null, 'u')]]);
        $this->service($source)->refresh([$this->php('php_web', '8.4.1')], self::NOW);
        $source->failing = ['php.net' => true, 'nvd' => true];

        $later = $this->service($source)->refresh(
            [$this->php('php_web', '8.4.1')],
            self::NOW + HostSecurityService::MAX_STALE_SECONDS + 1
        );

        $this->assertSame(ComponentAssessment::STATE_UNVERIFIABLE, $later->find('php_web')?->state);
        $this->assertFalse($later->find('php_web')->conclusive, 'an unanswered source must not re-arm an alert');
    }

    /** What is kept per CPE name is capped, highest first; the total is still told. */
    public function testOnlyTheGravestAdvisoriesAreKeptAndTheRestCounted(): void
    {
        $many = [];
        for ($i = 1; $i <= 30; $i++) {
            $many[] = new Advisory(sprintf('CVE-2026-%04d', $i), $i / 3, '3.1', null, 'u');
        }

        $snapshot = $this->service($this->source([self::PHP_CPE => $many]))->refresh([$this->php('php_web', '8.4.1')], self::NOW);

        $php = $snapshot->find('php_web');
        $this->assertSame(30, $php?->advisoryCount);
        $this->assertSame('CVE-2026-0030', $php->advisories[0]->id);
        $this->assertCount(ComponentAssessment::DISPLAYED_ADVISORIES, $php->advisories);
        $this->assertCount(count(array_filter($many, static fn(Advisory $a): bool => $a->isHigh())), $php->highAdvisoryIds);
    }

    /** @param array<string, list<Advisory>> $advisories */
    private function source(array $advisories): FakeReferenceSource
    {
        return new FakeReferenceSource(
            [new ReleaseBranch('8.4', '8.4.28', true, '2028-12-31')],
            [],
            $advisories
        );
    }

    private function service(FakeReferenceSource $source): HostSecurityService
    {
        return new HostSecurityService($this->store, $source);
    }

    private function php(string $subject, string $version): ObservedComponent
    {
        return new ObservedComponent($subject, ComponentFamily::PHP, ObservedComponent::PRESENT, $version, self::NOW);
    }
}
