<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Feedback\Seed\DomainRouting;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Routing by recipient domain is a RECOMMENDATION, never an automatism
 * (D13, roadmap IT-07).
 *
 * **The tests that matter are the refusals to conclude.** With three to
 * five seed boxes and a few mailings a year, the expensive mistake is not
 * missing a problem — it is moving a provider's whole traffic on the
 * strength of one unlucky campaign.
 *
 * @group database
 */
class DomainRoutingTest extends TestCase
{
    private SeedCopyRepository $copies;
    private SettingService $settings;
    private DomainRouting $routing;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->copies = new SeedCopyRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->settings = new SettingService(new SettingRepository($pdo));
        $this->settings->register(
            DomainRouting::SETTING_AUTOMATIC,
            '0',
            'boolean',
            'Routage automatique',
            '',
            null,
            null,
            null,
            false,
            59
        );
        $this->routing = new DomainRouting($this->copies, $this->settings);
    }

    private function record(string $address, string $folder, int $times, int $offsetDays = 0): void
    {
        for ($i = 0; $i < $times; $i++) {
            $sent = new \DateTimeImmutable('-' . ($offsetDays + 1) . ' days');
            $run = 'envoi-' . $address . '-' . $folder . '-' . $i . '-' . $offsetDays;
            $this->copies->claim($run, $address, $sent);
            $this->copies->recordLanding($run, $address, $folder, $sent);
        }
    }

    private function readingFor(string $provider): array
    {
        foreach ($this->routing->readings(new \DateTimeImmutable('-30 days')) as $reading) {
            if ($reading['provider'] === $provider) {
                return $reading;
            }
        }

        $this->fail('No reading for ' . $provider);
    }

    /**
     * **The refusal that D13 exists for.** Two mailings filed as spam is
     * two observations, and a recommendation built on two observations is
     * a recommendation built on noise.
     */
    public function testATinySampleConcludesNothingHoweverBadItLooks(): void
    {
        $this->record('t@orange.fr', 'Junk', 2);

        $reading = $this->readingFor('orange.fr');

        $this->assertFalse($reading['enough']);
        $this->assertFalse($reading['troubled'], 'Two observations must move nothing.');
        $this->assertStringContainsString('Pas assez', $reading['verdict']);
        $this->assertFalse($this->routing->hasRecommendation(new \DateTimeImmutable('-30 days')));
    }

    /** With enough evidence, the same pattern is finally worth saying. */
    public function testAProviderFilingMostMailingsAsSpamIsFlaggedOnceTheSampleIsBigEnough(): void
    {
        $this->record('t@orange.fr', 'Junk', DomainRouting::MINIMUM_RUNS);

        $reading = $this->readingFor('orange.fr');

        $this->assertTrue($reading['enough']);
        $this->assertTrue($reading['troubled']);
        $this->assertTrue($this->routing->hasRecommendation(new \DateTimeImmutable('-30 days')));
    }

    /**
     * **A provider that is fine is still listed.** A screen showing only
     * problems leaves a reader unable to tell « rien d'anormal » from
     * « rien de mesuré », and the second is the one that needs acting on.
     */
    public function testAHealthyProviderIsReportedRatherThanOmitted(): void
    {
        $this->record('t@gmail.com', 'INBOX', DomainRouting::MINIMUM_RUNS);

        $reading = $this->readingFor('gmail.com');

        $this->assertTrue($reading['enough']);
        $this->assertFalse($reading['troubled']);
        $this->assertSame('Rien à signaler', $reading['verdict']);
    }

    /** The occasional miss is ordinary and must not raise anything. */
    public function testAnOccasionalSpamFilingDoesNotRaiseARecommendation(): void
    {
        $this->record('t@gmail.com', 'INBOX', 9);
        $this->record('t@gmail.com', 'Junk', 1);

        $this->assertFalse($this->readingFor('gmail.com')['troubled']);
    }

    /**
     * **A copy nobody has answered yet is not evidence.** Counting it
     * would make the ratio move as the sweep runs rather than as delivery
     * changes — a figure that shifts for reasons nobody can see.
     */
    public function testCopiesStillWaitingAreNotCountedEitherWay(): void
    {
        $this->record('t@gmail.com', 'INBOX', DomainRouting::MINIMUM_RUNS);
        // Sent, never answered.
        $this->copies->claim('en-cours', 't@gmail.com', new \DateTimeImmutable('-1 hour'));

        $reading = $this->readingFor('gmail.com');

        $this->assertSame(DomainRouting::MINIMUM_RUNS, $reading['runs']);
        $this->assertFalse($reading['troubled']);
    }

    /**
     * **And the automatism is off until somebody turns it on** — the
     * second lock D13 asks for, beside the minimum sample.
     */
    public function testTheAutomatismIsOffByDefault(): void
    {
        $this->assertFalse($this->routing->isAutomatic());
    }

    public function testTheAutomatismReadsTheSwitch(): void
    {
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        $this->assertTrue($this->routing->isAutomatic());
    }

    /**
     * The sample is counted in MAILINGS, not in copies: five copies of one
     * mailing to five boxes say one thing five times, and counting them as
     * five observations would be the noise the minimum exists to refuse.
     */
    public function testOneMailingToManyBoxesIsNotManyObservations(): void
    {
        $sent = new \DateTimeImmutable('-1 day');
        foreach (['a@orange.fr', 'b@orange.fr', 'c@orange.fr', 'd@orange.fr', 'e@orange.fr'] as $box) {
            $this->copies->claim('un-seul-envoi', $box, $sent);
            $this->copies->recordLanding('un-seul-envoi', $box, 'Junk', $sent);
        }

        // Five copies, but of ONE mailing.
        $this->assertFalse(
            $this->routing->hasRecommendation(new \DateTimeImmutable('-30 days')),
            'Five boxes on one mailing is one observation, not five.'
        );
    }
}
