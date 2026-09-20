<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Mail\Feedback\Seed\SeedConsumer;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\SeedVerdict;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\CandidateMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The consumer that turns an arrival into a verdict — and the only one on
 * this site allowed to have a message deleted (roadmap IT-07).
 *
 * **Most of this file is about what it must NOT do.** It reads a header,
 * and a header is a string anybody able to send mail to the box can
 * write; a seed box is an ordinary mailbox whose address is no secret.
 * So the interesting failures are not « a verdict was missed » — they are
 * « a stranger set a verdict » and « the wrong message was deleted ».
 *
 * @group database
 */
#[Group('database')]
class SeedConsumerTest extends TestCase
{
    private \PDO $pdo;
    private SeedCopyRepository $copies;
    private SeedConsumer $consumer;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->copies = new SeedCopyRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->consumer = new SeedConsumer($this->copies);
    }

    private function candidate(
        string $header,
        string $folder = 'Junk',
        string $to = 'temoin@gmail.com',
        string $messageId = '<copie-1@unite.be>'
    ): CandidateMessage {
        return new CandidateMessage(
            mailboxId: 7,
            subject: 'Le camp approche',
            fromEmail: 'unite@exemple.be',
            fromName: 'Unité',
            messageId: $messageId,
            inReplyTo: null,
            references: [],
            toEmails: [$to],
            sentAt: new \DateTimeImmutable(),
            bodyText: 'texte',
            bodyHtml: '<p>texte</p>',
            attachments: [],
            rawHeaders: $header === '' ? '' : 'X-ScoutMagic-Seed: ' . $header . "\r\n",
            folder: $folder
        );
    }

    private function claim(string $reference = 'mass_mail:42', string $address = 'temoin@gmail.com'): void
    {
        $this->copies->claim($reference, $address, new \DateTimeImmutable('-1 hour'));
    }

    private function verdictOf(string $reference = 'mass_mail:42'): SeedVerdict
    {
        return $this->copies->forRun($reference)[0]->verdict;
    }

    // ── the ordinary path ─────────────────────────────────────────────

    public function testACopyOfOursIsRecordedWhereItLanded(): void
    {
        $this->claim();

        $this->consumer->analyze($this->candidate($this->copies->stamp('mass_mail:42')));

        $this->assertSame(SeedVerdict::Spam, $this->verdictOf());
    }

    /** And having recorded it, it is the one consumer that says « remove it ». */
    public function testItAsksForTheMessageItJustRecordedToBeRemoved(): void
    {
        $this->claim();
        $candidate = $this->candidate($this->copies->stamp('mass_mail:42'));

        $this->consumer->analyze($candidate);

        $this->assertTrue($this->consumer->shouldPruneAfterAnalysis($candidate));
    }

    /**
     * **It claims nothing**, and that is deliberate: a claim would create
     * an association, and an association keeps a full copy of every
     * mailing — one per seed box, bodies included.
     */
    public function testItClaimsNothing(): void
    {
        $this->claim();

        $this->assertTrue(
            $this->consumer->analyze($this->candidate($this->copies->stamp('mass_mail:42')))->isEmpty()
        );
    }

    // ── the header is checked, never believed ─────────────────────────

    /**
     * **The finding this file was written for.** A run reference is
     * `mass_mail:<id>`, a plain auto-increment, and the box address is no
     * secret — so a bare reference on the header is something a stranger
     * can guess and send. They would land in the folder of their choosing
     * and have the site record that as a real mailing's verdict, winning
     * the race against the copy still in flight.
     */
    public function testAForgedHeaderRecordsNothing(): void
    {
        $this->claim();

        // What an attacker can write: the reference itself, guessed.
        $this->consumer->analyze($this->candidate('mass_mail:42'));

        $this->assertSame(
            SeedVerdict::Pending,
            $this->verdictOf(),
            'a guessed reference must buy nothing.'
        );
    }

    public function testAForgedHeaderNeverGetsAMessageDeleted(): void
    {
        $this->claim();
        $candidate = $this->candidate('mass_mail:42');

        $this->consumer->analyze($candidate);

        $this->assertFalse($this->consumer->shouldPruneAfterAnalysis($candidate));
    }

    /** A stamp with the right shape and the wrong key buys nothing either. */
    public function testAStampFromSomebodyElsesKeyRecordsNothing(): void
    {
        $this->claim();
        $elsewhere = new SeedCopyRepository(
            $this->pdo,
            new EncryptionService(str_repeat('c', 32), str_repeat('d', 32))
        );

        $this->consumer->analyze($this->candidate($elsewhere->stamp('mass_mail:42')));

        $this->assertSame(SeedVerdict::Pending, $this->verdictOf());
    }

    // ── what may be deleted ───────────────────────────────────────────

    /**
     * **A second arrival for a pair already answered is not deleted.**
     *
     * `recordLanding()` answers false when the verdict is already set,
     * and the first version discarded that answer: the message was then
     * removed on the strength of a row somebody else's copy had filled
     * in.
     */
    public function testAMessageWhoseLandingWasAlreadyRecordedIsNotDeleted(): void
    {
        $this->claim();
        $stamp = $this->copies->stamp('mass_mail:42');
        $this->consumer->analyze($this->candidate($stamp, 'INBOX'));

        $second = $this->candidate($stamp, 'Junk', messageId: '<copie-2@unite.be>');
        $this->consumer->analyze($second);

        $this->assertFalse($this->consumer->shouldPruneAfterAnalysis($second));
        $this->assertSame(SeedVerdict::Inbox, $this->verdictOf(), 'the first answer is the one that was true.');
    }

    /**
     * **A copy that turns up after the sweep gave up on it is still an
     * arrival** — and refusing it cost a body, not merely a verdict.
     *
     * `markMissingBefore()` does not observe anything: after two days it
     * writes « jamais arrivé » on whatever is still pending. A provider
     * that held the message in a queue, or a box polled on a slower
     * schedule, delivers it afterwards — and the first version's write
     * answered false, so this consumer did not recognise the message, so
     * it asked for no deletion, so `MailboxSyncService::store()` kept it
     * with `$keepBody = true`. The full mailing, members' data included,
     * stayed in `inbound_messages` — which the privacy notice this
     * iteration wrote says in as many words it does not.
     */
    public function testACopyFoundAfterTheSweepGaveUpIsRecordedAndRemoved(): void
    {
        $this->claim();
        $this->copies->markMissingBefore(new \DateTimeImmutable('-1 minute'));
        $this->assertSame(SeedVerdict::Missing, $this->verdictOf(), 'the sweep ran first.');

        $late = $this->candidate($this->copies->stamp('mass_mail:42'), 'INBOX');
        $this->consumer->analyze($late);

        $this->assertSame(SeedVerdict::Inbox, $this->verdictOf(), 'an observation beats a surrender.');
        $this->assertTrue(
            $this->consumer->shouldPruneAfterAnalysis($late),
            'and the body has to leave the mailbox rather than be written down.'
        );
    }

    /**
     * The other half of the same rule: a verdict that WAS observed is
     * never overwritten, however late a second copy of the message is
     * read. Only the sweep's surrender gives way.
     */
    public function testAnObservedVerdictIsNotOverwrittenByALaterReading(): void
    {
        $this->claim();
        $stamp = $this->copies->stamp('mass_mail:42');
        $this->consumer->analyze($this->candidate($stamp, 'Junk'));

        $this->consumer->analyze($this->candidate($stamp, 'INBOX', messageId: '<copie-2@unite.be>'));

        $this->assertSame(SeedVerdict::Spam, $this->verdictOf());
    }

    /**
     * **One instance serves a whole sync pass**, so a value left over
     * from the previous message would be this consumer's answer about a
     * message it never looked at.
     */
    public function testARecognitionDoesNotCarryOverToTheNextMessage(): void
    {
        $this->claim();
        $this->consumer->analyze($this->candidate($this->copies->stamp('mass_mail:42')));

        $stranger = $this->candidate('', 'INBOX', 'quelquun@exemple.be', '<autre@exemple.be>');
        $this->consumer->analyze($stranger);

        $this->assertFalse($this->consumer->shouldPruneAfterAnalysis($stranger));
    }

    /**
     * **And an empty `Message-ID` identifies nothing**, so it may not
     * stand in for « this exact message »: every message without the
     * header carries the same empty string, so a stale recognition would
     * match the next one and delete somebody's mail.
     */
    public function testAMessageWithoutAnIdIsNeverDeletedOnAStaleRecognition(): void
    {
        $this->claim();
        $this->consumer->analyze($this->candidate($this->copies->stamp('mass_mail:42'), 'INBOX', messageId: ''));

        $stranger = $this->candidate('', 'INBOX', 'quelquun@exemple.be', '');

        $this->assertFalse($this->consumer->shouldPruneAfterAnalysis($stranger));
    }

    /** A message with no header of ours is simply not ours. */
    public function testAnOrdinaryMessageIsNeitherRecordedNorDeleted(): void
    {
        $this->claim();
        $ordinary = $this->candidate('', 'INBOX', 'quelquun@exemple.be', '<humain@exemple.be>');

        $this->consumer->analyze($ordinary);

        $this->assertSame(SeedVerdict::Pending, $this->verdictOf());
        $this->assertFalse($this->consumer->shouldPruneAfterAnalysis($ordinary));
    }

    /**
     * A valid stamp addressed to a box that is not the one claimed: the
     * row belongs to another mailbox, so nothing here is ours to answer.
     */
    public function testAValidStampAddressedToAnotherBoxRecordsNothing(): void
    {
        $this->claim();

        $this->consumer->analyze(
            $this->candidate($this->copies->stamp('mass_mail:42'), 'Junk', 'autre@outlook.com')
        );

        $this->assertSame(SeedVerdict::Pending, $this->verdictOf());
    }

    /** No folder means the relay did not say, which decides nothing. */
    public function testWithoutAFolderNothingIsDecided(): void
    {
        $this->claim();
        $candidate = new CandidateMessage(
            mailboxId: 7,
            subject: 'Le camp approche',
            fromEmail: 'unite@exemple.be',
            fromName: 'Unité',
            messageId: '<copie-1@unite.be>',
            inReplyTo: null,
            references: [],
            toEmails: ['temoin@gmail.com'],
            sentAt: new \DateTimeImmutable(),
            bodyText: 'texte',
            bodyHtml: '<p>texte</p>',
            attachments: [],
            rawHeaders: 'X-ScoutMagic-Seed: ' . $this->copies->stamp('mass_mail:42') . "\r\n",
            folder: null
        );

        $this->consumer->analyze($candidate);

        $this->assertSame(SeedVerdict::Pending, $this->verdictOf());
        $this->assertFalse($this->consumer->shouldPruneAfterAnalysis($candidate));
    }
}
