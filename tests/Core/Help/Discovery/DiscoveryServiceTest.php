<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Discovery;

use Core\Config\AppClock;
use Core\Config\SettingService;
use Core\Help\Discovery\DiscoveryService;
use Core\Help\Discovery\SeenTopicRepository;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Help\HelpTopic;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\HelpTopicFileFixtures;

/**
 * The ordering and the exclusions of « Le saviez-vous ? »
 * (ARCHITECTURE.md §8.95), over real topic files through the real
 * registry, parser and role filter.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DiscoveryServiceTest extends TestCase
{
    use HelpTopicFileFixtures;

    private \PDO $pdo;
    private SeenTopicRepository $seenTopics;
    private SettingService $settings;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->seenTopics = new SeenTopicRepository($this->pdo);
        $this->settings = new SettingService(new \Core\Config\SettingRepository($this->pdo));
        $this->accountId = $this->createAccount();
    }

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    public function testTheSwitchSilencesEverything(): void
    {
        $service = $this->serviceOver(['un' => [], 'deux' => []]);
        $this->set(DiscoveryService::SETTING_ENABLED, '0');

        $this->assertSame([], $service->nextTopics(Role::IDENTIFIED, $this->accountId));
        $this->assertFalse($service->isEnabled());
    }

    public function testASnoozeStillRunningSilencesEverything(): void
    {
        $service = $this->serviceOver(['un' => [], 'deux' => []]);
        $this->seenTopics->snooze($this->accountId, AppClock::now()->modify('+2 hours'));

        $this->assertSame([], $service->nextTopics(Role::IDENTIFIED, $this->accountId));
    }

    public function testASnoozeThatHasElapsedHoldsNothingBack(): void
    {
        $service = $this->serviceOver(['un' => [], 'deux' => []]);
        $this->seenTopics->snooze($this->accountId, AppClock::now()->modify('-1 minute'));

        $this->assertNotSame([], $service->nextTopics(Role::IDENTIFIED, $this->accountId));
    }

    /**
     * The role filter is HelpService::listForRole() and nothing else, so
     * a topic above the reader's floor is simply not in the list — and
     * the filter is cumulative, so an `identified` topic is offered to a
     * chief too.
     */
    public function testTopicsAboveTheRoleAreNeverOffered(): void
    {
        $service = $this->serviceOver([
            'pour-tous' => ['role_min' => 'identified'],
            'pour-les-chefs' => ['role_min' => 'chief'],
        ]);

        $this->assertSame(['pour-tous'], $this->ids($service->nextTopics(Role::IDENTIFIED, $this->accountId)));

        $forChief = $this->ids($service->nextTopics(Role::CHIEF, $this->accountId));
        sort($forChief);
        $this->assertSame(['pour-les-chefs', 'pour-tous'], $forChief);
    }

    /**
     * A disabled module's topics are never registered at all — the
     * registry only knows the directories ModuleManager handed it. The
     * service does nothing special about it, and that is the point.
     */
    public function testAnUnregisteredModulesTopicsAreAbsent(): void
    {
        $coreDir = $this->makeTopicDir();
        $this->writeTopic($coreDir, 'un-sujet-core', ['role_min' => 'identified']);

        $disabledModuleDir = $this->makeTopicDir();
        $this->writeTopic($disabledModuleDir, 'sujet-du-module-eteint', ['role_min' => 'identified']);

        $service = $this->serviceOverRegistry(new HelpRegistry($coreDir));

        $this->assertSame(['un-sujet-core'], $this->ids($service->nextTopics(Role::IDENTIFIED, $this->accountId)));
    }

    public function testTopicsMarkedOffAreNeverOffered(): void
    {
        $service = $this->serviceOver([
            'cookies' => ['discovery' => 'off'],
            'publipostage' => ['discovery' => '1'],
        ]);

        $this->assertSame(['publipostage'], $this->ids($service->nextTopics(Role::IDENTIFIED, $this->accountId)));
    }

    public function testTopicsAlreadySeenAreNeverOfferedAgain(): void
    {
        $service = $this->serviceOver(['un' => [], 'deux' => [], 'trois' => []]);
        $this->seenTopics->markSeen($this->accountId, ['deux']);

        $offered = $this->ids($service->nextTopics(Role::IDENTIFIED, $this->accountId));
        sort($offered);
        $this->assertSame(['trois', 'un'], $offered);
    }

    public function testTheBatchIsTruncatedToTheConfiguredSize(): void
    {
        $service = $this->serviceOver(array_fill_keys(
            array_map(static fn (int $i): string => 'sujet-' . $i, range(1, 12)),
            []
        ));
        $this->set(DiscoveryService::SETTING_BATCH_SIZE, '3');

        $this->assertCount(3, $service->nextTopics(Role::IDENTIFIED, $this->accountId));
        $this->assertCount(12, $service->eligibleTopics(Role::IDENTIFIED, $this->accountId));
        $this->assertSame(3, $service->batchSize());
    }

    public function testANonsensicalBatchSizeFallsBackOnTheDefault(): void
    {
        $service = $this->serviceOver(['un' => []]);
        $this->set(DiscoveryService::SETTING_BATCH_SIZE, '0');

        $this->assertSame(DiscoveryService::DEFAULT_BATCH_SIZE, $service->batchSize());
    }

    /**
     * Priority is editorial and the seed only ever breaks ties, so a
     * priority-1 topic leads whatever the draw — checked over a corpus
     * big enough that a lucky seed cannot be the explanation.
     */
    public function testPriorityAlwaysBeatsTheSeed(): void
    {
        $topics = [];
        foreach (range(1, 20) as $i) {
            $topics['normal-' . $i] = [];
        }
        $topics['haute'] = ['discovery' => '1'];
        $topics['basse'] = ['discovery' => '3'];
        $service = $this->serviceOver($topics);

        for ($draw = 0; $draw < 12; $draw++) {
            $ordered = $this->ids($service->eligibleTopics(Role::IDENTIFIED, $this->createAccount()));
            $this->assertSame('haute', $ordered[0]);
            $this->assertSame('basse', $ordered[array_key_last($ordered)]);
        }
    }

    /**
     * The whole reason the order is a computed key rather than a
     * shuffle(): the dialog reappears on every page load until it is
     * closed, so two consecutive requests must serve the same cards.
     */
    public function testTheSameStateGivesExactlyTheSameOrder(): void
    {
        $service = $this->serviceOver($this->manyTopics(30));

        $first = $this->ids($service->eligibleTopics(Role::IDENTIFIED, $this->accountId));
        $second = $this->ids($service->eligibleTopics(Role::IDENTIFIED, $this->accountId));

        $this->assertSame($first, $second);
    }

    public function testMovingTheStateChangesTheOrder(): void
    {
        $service = $this->serviceOver($this->manyTopics(30));

        $before = $this->ids($service->eligibleTopics(Role::IDENTIFIED, $this->accountId));

        // What a close does: some ids consumed, a delay written. Both
        // halves of the seed move.
        $this->seenTopics->markSeen($this->accountId, array_slice($before, 0, 5));
        $this->seenTopics->snooze($this->accountId, AppClock::now()->modify('-1 hour'));

        $after = $this->ids($service->eligibleTopics(Role::IDENTIFIED, $this->accountId));
        $remaining = array_slice($before, 5);

        $this->assertNotSame($remaining, $after, 'A new passage must not replay the previous running order.');
        sort($remaining);
        $sortedAfter = $after;
        sort($sortedAfter);
        $this->assertSame($remaining, $sortedAfter, 'Only the order changes — the eligible set is the same.');
    }

    public function testTwoAccountsOfTheSameRoleDoNotOpenOnTheSameTip(): void
    {
        $service = $this->serviceOver($this->manyTopics(40));

        $firstCards = [];
        for ($account = 0; $account < 8; $account++) {
            $firstCards[] = $this->ids($service->eligibleTopics(Role::IDENTIFIED, $this->createAccount()))[0];
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($firstCards)),
            'Eight accounts of the same role all opening on the same card is a seed that is not being used.'
        );
    }

    public function testTheDelaysComeFromTheirSettings(): void
    {
        $service = $this->serviceOver(['un' => []]);
        $this->set(DiscoveryService::SETTING_INTERVAL_HOURS, '6');
        $this->set(DiscoveryService::SETTING_SNOOZE_DAYS, '3');

        $this->assertSame(
            AppClock::now()->modify('+6 hours')->format('Y-m-d H'),
            $service->nextOrdinaryOpening()->format('Y-m-d H')
        );
        $this->assertSame(
            AppClock::now()->modify('+3 days')->format('Y-m-d'),
            $service->nextOpeningAfterSnooze()->format('Y-m-d')
        );
    }

    public function testDefaultsApplyWhenNothingIsRegistered(): void
    {
        $service = $this->serviceOver(['un' => []]);

        $this->assertTrue($service->isEnabled());
        $this->assertSame(DiscoveryService::DEFAULT_BATCH_SIZE, $service->batchSize());
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function manyTopics(int $count): array
    {
        $topics = [];
        foreach (range(1, $count) as $i) {
            $topics['sujet-' . $i] = [];
        }

        return $topics;
    }

    /**
     * @param array<string, array<string, string>> $topics id => extra front matter
     */
    private function serviceOver(array $topics): DiscoveryService
    {
        $dir = $this->makeTopicDir();
        foreach ($topics as $id => $frontMatter) {
            $this->writeTopic($dir, $id, array_merge(['role_min' => 'identified'], $frontMatter));
        }

        return $this->serviceOverRegistry(new HelpRegistry($dir));
    }

    private function serviceOverRegistry(HelpRegistry $registry): DiscoveryService
    {
        return new DiscoveryService(new HelpService($registry), $this->seenTopics, $this->settings);
    }

    /**
     * @param HelpTopic[] $topics
     * @return string[]
     */
    private function ids(array $topics): array
    {
        return array_map(static fn (HelpTopic $t): string => $t->id, $topics);
    }

    private function set(string $key, string $value): void
    {
        $this->settings->register($key, $value, 'text', 'Test', 'Test');
    }

    private function createAccount(): int
    {
        $email = 'compte' . bin2hex(random_bytes(6)) . '@test.be';
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$email, hash('sha256', $email)]);

        return (int) $this->pdo->lastInsertId();
    }
}
