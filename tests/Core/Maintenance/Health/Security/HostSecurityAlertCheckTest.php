<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Health\Security;

use Core\Alert\OperationalAlert;
use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAlertService;
use Core\Maintenance\Health\Security\Advisory;
use Core\Maintenance\Health\Security\AdvisoryLookup;
use Core\Maintenance\Health\Security\BranchLookup;
use Core\Maintenance\Health\Security\ComponentAssessment;
use Core\Maintenance\Health\Security\ComponentFamily;
use Core\Maintenance\Health\Security\HostSecurityAlertCheck;
use Core\Maintenance\Health\Security\HostSecurityEvaluator;
use Core\Maintenance\Health\Security\HostSecuritySnapshot;
use Core\Maintenance\Health\Security\ObservedComponent;
use Core\Maintenance\Health\Security\ReleaseBranch;
use Core\Notification\NotificationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Lot 5 of issue #1358: the notification condition, announced once per
 * component and context, again when the set of advisories grows, kept
 * through an inconclusive run and re-armed by a verified fix.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HostSecurityAlertCheckTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private OperationalAlertRepository $repository;

    /** @var list<array{type: string, payload: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->repository = new OperationalAlertRepository(DatabaseTestHelper::createTestDatabase());
    }

    public function testAnUpdateNeededWithAHighAdvisoryNotifiesWithVersionAdvisoriesAndLink(): void
    {
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8, 'CVE-2026-0002' => 7.0]));

        $this->assertCount(1, $this->sent);
        $this->assertSame(OperationalAlertService::TYPE_DEFAULT, $this->sent[0]['type']);
        $payload = $this->sent[0]['payload'];
        $this->assertStringContainsString('PHP du site 8.4.1', $payload['title']);
        $this->assertStringContainsString('CVE-2026-0001 (9,8 (CVSS 3.1, nvd@nist.gov))', $payload['body']);
        $this->assertStringContainsString('CVE-2026-0002', $payload['body']);
        $this->assertStringContainsString('PHP 8.4.28', $payload['body'], 'the action names the fixed version');
        $this->assertSame('/config/maintenance', $payload['url']);
        $this->assertSame(
            OperationalAlert::STATE_TRIGGERED,
            $this->repository->findOrArmed(HostSecurityAlertCheck::KEY_PREFIX . 'php_web')->state
        );
    }

    /** A trimmed snapshot lists no detail: the notification names the identifiers instead. */
    public function testATrimmedSnapshotStillNamesTheAdvisories(): void
    {
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8])->trimmed(0, 50));

        $this->assertStringContainsString('Avis : CVE-2026-0001.', $this->sent[0]['payload']['body'] ?? '');
    }

    public function testADelayWithoutAHighAdvisoryNeverNotifies(): void
    {
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 6.9]));

        $this->assertSame([], $this->sent);
    }

    /** Several copies of one advisory, and the same set next week: one notification. */
    public function testTheSameAdvisoriesAreNeverAnnouncedTwice(): void
    {
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8]));
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8]));

        $this->assertCount(1, $this->sent);
    }

    /**
     * The maintainer's decision 3: a new advisory at 7 or above on a
     * component already in alert is announced — once.
     */
    public function testANewHighAdvisoryOnAComponentAlreadyInAlertIsAnnouncedOnce(): void
    {
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8]));
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8, 'CVE-2026-0003' => 7.5]));
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8, 'CVE-2026-0003' => 7.5]));

        $this->assertCount(2, $this->sent);
        $this->assertSame(
            ['CVE-2026-0001', 'CVE-2026-0003'],
            $this->repository->findOrArmed(HostSecurityAlertCheck::KEY_PREFIX . 'php_web')->notifiedSubjects
        );
        // A set that only SHRINKS is not new: nothing to announce.
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0003' => 7.5]));
        $this->assertCount(2, $this->sent);
    }

    /** Each component and context has its own key: two problems, two notifications. */
    public function testAnotherComponentInAlertIsAnnouncedOnItsOwn(): void
    {
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8]));
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8], cron: true));

        $this->assertCount(2, $this->sent);
    }

    public function testAnInconclusiveRunKeepsTheAlertAndAVerifiedFixRearmsIt(): void
    {
        $key = HostSecurityAlertCheck::KEY_PREFIX . 'php_web';
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8]));

        // The sources fail: nothing is concluded, the alert stands.
        $this->evaluateAlerts(new HostSecuritySnapshot(self::NOW, (new HostSecurityEvaluator())->evaluate(
            [new ObservedComponent('php_web', ComponentFamily::PHP, ObservedComponent::PRESENT, '8.4.1', self::NOW)],
            [],
            []
        ), true));
        $this->assertTrue($this->repository->findOrArmed($key)->isTriggered());

        // The component missing from the snapshot altogether: same.
        $this->evaluateAlerts(new HostSecuritySnapshot(self::NOW, [], true));
        $this->assertTrue($this->repository->findOrArmed($key)->isTriggered());

        // The host updated: verified current, re-armed silently.
        $this->evaluateAlerts($this->snapshot('8.4.28', []));
        $alert = $this->repository->findOrArmed($key);
        $this->assertFalse($alert->isTriggered());
        $this->assertSame([], $alert->notifiedSubjects);
        $this->assertCount(1, $this->sent);

        // ...and the same advisory coming back later is a new alert.
        $this->evaluateAlerts($this->snapshot('8.4.1', ['CVE-2026-0001' => 9.8]));
        $this->assertCount(2, $this->sent);
    }

    private function evaluateAlerts(HostSecuritySnapshot $snapshot): void
    {
        (new OperationalAlertService($this->repository, $this->recordingNotifications()))
            ->run(HostSecurityAlertCheck::forSnapshot($snapshot));
    }

    private function recordingNotifications(): NotificationService
    {
        $mock = $this->createStub(NotificationService::class);
        $mock->method('recipientsForType')->willReturn([['userAccountId' => 1, 'memberId' => null]]);
        $mock->method('dispatch')->willReturnCallback(function (string $typeId, array $recipients, array $payload): void {
            $this->sent[] = ['type' => $typeId, 'payload' => $payload];
        });

        return $mock;
    }

    /**
     * @param array<string, float> $advisories id => score
     */
    private function snapshot(string $version, array $advisories, bool $cron = false): HostSecuritySnapshot
    {
        $family = ComponentFamily::get(ComponentFamily::PHP) ?? throw new \LogicException();
        $list = [];
        foreach ($advisories as $id => $score) {
            $list[] = new Advisory($id, $score, '3.1', 'nvd@nist.gov', 'https://nvd.nist.gov/vuln/detail/' . $id);
        }
        $cpe = 'cpe:2.3:a:php:php:' . $version . ':*:*:*:*:*:*:*';

        return new HostSecuritySnapshot(self::NOW, (new HostSecurityEvaluator())->evaluate(
            [new ObservedComponent($cron ? 'php_cron' : 'php_web', $family->id, ObservedComponent::PRESENT, $version, self::NOW)],
            ['php.net' => new BranchLookup(BranchLookup::OK, [new ReleaseBranch('8.4', '8.4.28', true)], self::NOW)],
            [$cpe => new AdvisoryLookup(AdvisoryLookup::OK, $list, count($list), self::NOW)]
        ), true);
    }
}
