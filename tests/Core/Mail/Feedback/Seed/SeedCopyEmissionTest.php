<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\DkimManager;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\SeedMailboxes;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMailInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The mailing lane writing to the unit's seed mailboxes (roadmap IT-07).
 *
 * **The whole point is what the copy looks like**, so these tests read the
 * message the transport was actually handed rather than a return value:
 * a copy whose subject or body differed from the campaign's would be
 * measuring the difference instead of the campaign.
 *
 * @group database
 */
#[Group('database')]
class SeedCopyEmissionTest extends TestCase
{
    private string $tempDir = '';
    private \PDO $pdo;
    private SettingService $settings;
    private SeedCopyRepository $copies;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scoutmagic-seed-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            SeedMailboxes::SETTING_ENABLED,
            '0',
            'boolean',
            'Copies témoins',
            '',
            null,
            null,
            null,
            false,
            58
        );
        $this->copies = new SeedCopyRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $entry) {
            is_dir($entry) ? null : unlink($entry);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    /**
     * A transport that keeps every message, since a run emits the campaign
     * and then its copies.
     *
     * @return MailTransportInterface&object{sent: list<PHPMailer>}
     */
    private function recordingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            /** @var list<PHPMailer> */
            public array $sent = [];

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->sent[] = $mail;
            }
        };
    }

    /**
     * An `inbound_mail` that answers with the seed boxes and nothing else
     * — the one method this feature asks it (D10).
     *
     * @param list<string> $addresses
     */
    private function inboundMailWith(array $addresses): InboundMailInterface
    {
        $stub = $this->createStub(InboundMailInterface::class);
        $stub->method('probeAddressesFor')->willReturn($addresses);

        return $stub;
    }

    /**
     * @param list<string> $seedAddresses
     */
    private function serviceWith(
        MailTransportInterface $transport,
        array $seedAddresses,
        bool $enabled = true
    ): MailService {
        $this->settings->setInternal(SeedMailboxes::SETTING_ENABLED, $enabled ? '1' : '0');

        $seeds = new SeedMailboxes($this->copies, $this->settings);
        $seeds->useInboundMail($this->inboundMailWith($seedAddresses));

        return new MailService(
            mode: 'local',
            fromAddress: 'noreply@unite.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport,
            seedMailboxes: $seeds
        );
    }

    private function sendCampaign(
        MailService $service,
        string $to = 'parent@exemple.be',
        ?string $run = 'mass_mail:42'
    ): void {
        $service->send(
            to: $to,
            subject: 'Le camp de cet été',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            purpose: MailPurpose::Bulk,
            bulkRunReference: $run
        );
    }

    public function testEachSeedBoxGetsOneCopyOfTheMailing(): void
    {
        $transport = $this->recordingTransport();
        $this->sendCampaign($this->serviceWith($transport, ['t1@gmail.com', 't2@outlook.com']));

        // The campaign, then one copy per box.
        $this->assertCount(3, $transport->sent);
        $recipients = array_map(
            static fn(PHPMailer $mail): string => $mail->getToAddresses()[0][0],
            $transport->sent
        );
        $this->assertSame(['parent@exemple.be', 't1@gmail.com', 't2@outlook.com'], $recipients);
    }

    /**
     * **The copy IS the campaign**, and that is the measurement. A subject
     * decorated with a tracking token — the way the manual probe rightly
     * decorates its own — would measure the token's effect on filtering
     * rather than the mailing's.
     */
    public function testTheCopyCarriesTheCampaignsOwnSubjectAndBody(): void
    {
        $transport = $this->recordingTransport();
        $this->sendCampaign($this->serviceWith($transport, ['t1@gmail.com']));

        $campaign = $transport->sent[0];
        $copy = $transport->sent[1];

        $this->assertSame($campaign->Subject, $copy->Subject);
        // Prefix included: the unit's short tag is part of what a filter
        // sees, so a copy without it would not be the same message.
        $this->assertSame('[25SV] Le camp de cet été', $copy->Subject);
        $this->assertSame($campaign->Body, $copy->Body);
    }

    /** The correlation rides in a header, which is not what a filter weighs. */
    public function testTheCopyCarriesTheRunReferenceInAHeader(): void
    {
        $transport = $this->recordingTransport();
        $this->sendCampaign($this->serviceWith($transport, ['t1@gmail.com']));

        $raw = $transport->sent[1]->createHeader();
        $this->assertStringContainsString(SeedMailboxes::HEADER . ': mass_mail:42', $raw);
        // And the campaign itself carries none of it.
        $this->assertStringNotContainsString(SeedMailboxes::HEADER, $transport->sent[0]->createHeader());
    }

    /**
     * **One set of copies per run, not per recipient.** A mailing calls
     * `send()` once per member; without the claim, five hundred members
     * would mean five hundred copies in every seed box, and « une ligne
     * par campagne » would be five hundred lines.
     */
    public function testAMailingOfManyRecipientsStillEmitsOneCopyPerBox(): void
    {
        $transport = $this->recordingTransport();
        $service = $this->serviceWith($transport, ['t1@gmail.com']);

        $this->sendCampaign($service, 'un@exemple.be');
        $this->sendCampaign($service, 'deux@exemple.be');
        $this->sendCampaign($service, 'trois@exemple.be');

        // Three campaign messages, one copy.
        $this->assertCount(4, $transport->sent);
        $this->assertCount(1, $this->copies->forRun('mass_mail:42'));
    }

    /** The next mailing is a new measurement. */
    public function testTheNextRunIsCopiedAgain(): void
    {
        $transport = $this->recordingTransport();
        $service = $this->serviceWith($transport, ['t1@gmail.com']);

        $this->sendCampaign($service, run: 'mass_mail:42');
        $this->sendCampaign($service, run: 'mass_mail:43');

        $this->assertCount(4, $transport->sent);
        $this->assertCount(1, $this->copies->forRun('mass_mail:42'));
        $this->assertCount(1, $this->copies->forRun('mass_mail:43'));
    }

    /**
     * **A seed copy must not spawn its own seed copies**, which would be
     * an infinite mailing rather than a measurement. The guard is that the
     * copy goes out with no run reference at all.
     *
     * **The boxes CHANGE between calls, and that is the whole test.** A
     * fixed list cannot fail it: `claim()` already refuses a second claim
     * for the same run and box, so a copy that wrongly kept
     * `mass_mail:42` would claim nothing and the count would stay at
     * three with the guard gone. Handing back a different box on the
     * second reading means a recursive emission finds something
     * unclaimed — and the count moves.
     */
    public function testACopyDoesNotSpawnItsOwnCopies(): void
    {
        $transport = $this->recordingTransport();

        $stub = $this->createStub(InboundMailInterface::class);
        $stub->method('probeAddressesFor')->willReturnOnConsecutiveCalls(
            ['t1@gmail.com', 't2@outlook.com'],
            // What a recursive emission would be handed: boxes this run
            // has not claimed yet, so nothing else stops the copies.
            ['t3@laposte.net', 't4@yahoo.fr'],
            ['t5@free.fr', 't6@orange.fr']
        );

        $this->settings->setInternal(SeedMailboxes::SETTING_ENABLED, '1');
        $seeds = new SeedMailboxes($this->copies, $this->settings);
        $seeds->useInboundMail($stub);

        $this->sendCampaign(new MailService(
            mode: 'local',
            fromAddress: 'noreply@unite.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport,
            seedMailboxes: $seeds
        ));

        $this->assertCount(3, $transport->sent, 'one mailing, two copies, and no copy of a copy.');
        $this->assertCount(
            2,
            $this->copies->forRun('mass_mail:42'),
            'a third claim would mean a copy went looking for boxes of its own.'
        );
    }

    public function testNothingIsCopiedWhenTheUnitHasNotTurnedItOn(): void
    {
        $transport = $this->recordingTransport();
        $this->sendCampaign($this->serviceWith($transport, ['t1@gmail.com'], enabled: false));

        $this->assertCount(1, $transport->sent);
        $this->assertCount(0, $this->copies->forRun('mass_mail:42'));
    }

    /** An ordinary message is not a mailing, and is never copied. */
    public function testAnOrdinaryMessageIsNeverCopied(): void
    {
        $transport = $this->recordingTransport();
        $this->serviceWith($transport, ['t1@gmail.com'])->send(
            to: 'parent@exemple.be',
            subject: 'Votre attestation',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertCount(1, $transport->sent);
    }

    /**
     * A mailing whose sender passes no run reference is not copied
     * either: without one there is no campaign to attribute the
     * measurement to, and guessing would put every mailing in one row.
     */
    public function testAMailingWithoutARunReferenceIsNotCopied(): void
    {
        $transport = $this->recordingTransport();
        $this->sendCampaign($this->serviceWith($transport, ['t1@gmail.com']), run: null);

        $this->assertCount(1, $transport->sent);
    }

    /**
     * **A diagnostic may never cost the thing it measures.** A seed box
     * that refuses the copy must leave the campaign untouched — and its
     * row stays `pending`, which the sweep turns into « jamais arrivé »,
     * the truthful answer since nothing arrived.
     */
    public function testASeedBoxThatRefusesTheCopyDoesNotBreakTheCampaign(): void
    {
        $transport = new class implements MailTransportInterface {
            /** @var list<PHPMailer> */
            public array $sent = [];

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                if (($mail->getToAddresses()[0][0] ?? '') === 't1@gmail.com') {
                    throw new \RuntimeException('SMTP Error: recipient refused.');
                }

                $this->sent[] = $mail;
            }
        };

        $this->sendCampaign($this->serviceWith($transport, ['t1@gmail.com', 't2@outlook.com']));

        // The campaign left, and so did the box that works.
        $this->assertCount(2, $transport->sent);
        $this->assertSame('parent@exemple.be', $transport->sent[0]->getToAddresses()[0][0]);
        $this->assertSame('t2@outlook.com', $transport->sent[1]->getToAddresses()[0][0]);
    }
}
