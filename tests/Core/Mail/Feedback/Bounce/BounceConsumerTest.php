<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceConsumer;
use Core\Mail\Feedback\Bounce\BounceService;
use Core\Mail\Feedback\Bounce\BounceStateRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Mime\BulkMailDetector;
use Modules\InboundMail\Mime\MimeMessageParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The consumer, and the two facts about `inbound_mail` that this whole
 * iteration rests on (roadmap IT-05).
 *
 * Both are true today and neither is written down anywhere that would
 * fail if it changed. They are pinned here because breaking either one
 * makes the site stop recognising bounces **silently**: no error, no red
 * test, just an address that keeps being written to for ever.
 *
 * @group database
 */
#[Group('database')]
class BounceConsumerTest extends TestCase
{
    private \PDO $pdo;
    private BounceStateRepository $states;
    private BounceConsumer $consumer;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->states = new BounceStateRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->consumer = new BounceConsumer(new BounceService($this->states));

        // The unit wrote to this address. Without that receipt the bounce
        // below is refused, which is the point of
        // `testAForgedBounceForAnAddressWeNeverWroteToIsRefused`.
        //
        // **Relative to now, and it has to be.** The path under test does
        // not pin its clock: `BounceConsumer::analyze()` calls
        // `BounceService::record()` without a `$now`, so the repository
        // compares this receipt against the real wall clock and refuses
        // one older than `RECEIPT_MAX_AGE` (a month). A literal date here
        // was a time bomb — every test in this class would have started
        // failing a month after it was written, and
        // `testAForgedBounceForAnAddressWeNeverWroteToIsRefused` would
        // have gone on PASSING for the wrong reason, pinning the age gate
        // instead of the missing-receipt rule it exists for. A test that
        // quietly changes what it proves is worse than one that breaks.
        DatabaseTestHelper::markAddressOnFile($this->pdo, 'parent@exemple.be');
        $this->states->recordSend('parent@exemple.be', new \DateTimeImmutable('-1 hour'));
    }

    /**
     * A real bounce, as a mail server builds one: `multipart/report` with
     * a human-readable part, the structured report, and the original
     * message.
     */
    private static function bounceMessage(): string
    {
        return "Content-Type: multipart/report; report-type=delivery-status; boundary=\"XYZ\"\r\n"
            . "\r\n"
            . "--XYZ\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "This is the mail system at host mail.exemple.be.\r\n"
            . "I'm sorry to have to inform you that your message could not be delivered.\r\n"
            . "\r\n"
            . "--XYZ\r\n"
            . "Content-Type: message/delivery-status\r\n"
            . "\r\n"
            . "Reporting-MTA: dns; mail.exemple.be\r\n"
            . "\r\n"
            . "Final-Recipient: rfc822; parent@exemple.be\r\n"
            . "Action: failed\r\n"
            . "Status: 5.1.1\r\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 User unknown\r\n"
            . "\r\n"
            . "--XYZ\r\n"
            . "Content-Type: message/rfc822\r\n"
            . "\r\n"
            . "From: info@unite.be\r\n"
            . "To: parent@exemple.be\r\n"
            . "Subject: Reunion de samedi\r\n"
            . "\r\n"
            . "--XYZ--\r\n";
    }

    /**
     * **The load-bearing fact.** `message/delivery-status` carries no
     * filename and no `Content-Disposition`, so `MimeMessageParser` treats
     * it as text and appends it to the body — separated by the blank line
     * it joins parts with, which is the very boundary the report parser
     * splits on.
     *
     * If that part ever became an attachment instead, the consumer would
     * go blind: `Api\CandidateAttachment` carries a name, a type and a
     * size, and no bytes at all.
     */
    public function testTheDeliveryStatusPartArrivesInTheBodyWhereTheConsumerLooks(): void
    {
        $parsed = (new MimeMessageParser(new BulkMailDetector()))->parse(self::bounceMessage(), 1, 'INBOX');

        $this->assertStringContainsString('Final-Recipient: rfc822; parent@exemple.be', $parsed->bodyText);
        $this->assertStringContainsString('Action: failed', $parsed->bodyText);
        $this->assertStringContainsString('Status: 5.1.1', $parsed->bodyText);
    }

    /**
     * **The second load-bearing fact.** A bounce comes from
     * `mailer-daemon` and carries `Auto-Submitted`, so `BulkMailDetector`
     * flags very nearly every one. Its docblock says the flag only hides
     * a message from a default view — that it is stored like any other and
     * offered to every consumer like any other.
     *
     * That claim is the reason this iteration can work at all, so it is
     * checked rather than trusted: a flag that suppressed analysis would
     * have made every bounce invisible to this consumer.
     */
    public function testABounceIsFlaggedAutomaticAndThatChangesNothingForTheConsumer(): void
    {
        $parsed = (new MimeMessageParser(new BulkMailDetector()))->parse(
            "From: MAILER-DAEMON@exemple.be\r\n"
            . "Auto-Submitted: auto-replied\r\n"
            . self::bounceMessage(),
            1,
            'INBOX'
        );

        $this->assertTrue($parsed->isBulk, 'a bounce is automatic mail, and is flagged as such.');

        $this->consumer->analyze($this->candidateFrom($parsed->bodyText));

        $this->assertNotNull(
            $this->states->find('parent@exemple.be'),
            'the flag must not stand between a bounce and the consumer that reads it.'
        );
    }

    /**
     * Exactly ONE failure out of a whole bounce, and the count is the
     * assertion that matters: the report carries the original message
     * quoted in full, `To: parent@exemple.be` and all, and a parser that
     * read that as a second recipient would block the address in half the
     * bounces it should take.
     *
     * Two independent checks discard the quoted part — it carries no
     * `Final-Recipient` field and no `Action: failed` — so neither can be
     * isolated by breaking the other. That redundancy is why there is one
     * test here rather than two: a second one would assert the same fact
     * and prove no more of it.
     */
    public function testAWholeBounceIsRecordedOnceAgainstTheAddressThatFailed(): void
    {
        $parsed = (new MimeMessageParser(new BulkMailDetector()))->parse(self::bounceMessage(), 1, 'INBOX');

        $this->consumer->analyze($this->candidateFrom($parsed->bodyText));

        $state = $this->states->find('parent@exemple.be');
        $this->assertSame(1, $state?->failures);
        $this->assertSame('5.1.1', $state?->statusCode);
    }

    /**
     * **The security boundary, and the reason this iteration has one.**
     * A watched mailbox is a mailbox anybody can write to, and a bounce
     * report is written by whoever sent it. Without a receipt of an
     * outbound send, somebody able to deliver mail to the unit could
     * forge two permanent failures naming any address they liked and have
     * it suspended site-wide — with a notification to that member.
     *
     * `describeEvidence()` has always claimed the report designates « une
     * adresse de cette unité ». This is what makes the claim true.
     */
    public function testAForgedBounceForAnAddressWeNeverWroteToIsRefused(): void
    {
        $forged = str_replace('parent@exemple.be', 'victime@exemple.be', self::bounceMessage());
        $parsed = (new MimeMessageParser(new BulkMailDetector()))->parse($forged, 1, 'INBOX');

        $this->consumer->analyze($this->candidateFrom($parsed->bodyText));

        $this->assertNull(
            $this->states->find('victime@exemple.be'),
            'a report about a message this site cannot show it sent must record nothing.'
        );
    }

    /**
     * **One message, two strikes — the shape that turned the two-failure
     * rule into a one-message rule.** Nothing in a delivery-status body
     * stops the same failed recipient appearing twice, and it costs an
     * attacker one extra paragraph to write it. Counted naively, the
     * address is blocked and its owner notified by a single message.
     *
     * What refuses it is the same rule that refuses the forged report
     * above: a report counts only when a message has gone out since the
     * last one that counted, and no send happened between two paragraphs
     * of one email.
     */
    public function testOneMessageNamingTheSameAddressTwiceCountsOnce(): void
    {
        $this->consumer->analyze($this->candidateFrom(
            "Final-Recipient: rfc822; parent@exemple.be\nAction: failed\nStatus: 5.1.1\n"
            . "\n"
            . "Final-Recipient: rfc822; parent@exemple.be\nAction: failed\nStatus: 5.1.1\n"
        ));

        $state = $this->states->find('parent@exemple.be');
        $this->assertSame(1, $state?->failures, 'two paragraphs are not two failures.');
        $this->assertFalse($state?->isBlocked(), 'one message must never be enough to block.');
    }

    /**
     * **And the same message read twice counts once.**
     * `Modules\InboundMail\Service\MailboxSyncService` calls
     * `analyzeAll()` BEFORE its Message-ID check — deliberately, and its
     * own comment says a re-read is expected after a UIDVALIDITY reset or
     * when a message lands in two watched folders. So the second read
     * reaches `analyze()` in full, and without this rule a single genuine
     * bounce would block an address in half the failures it should take.
     */
    public function testTheSameBounceReadTwiceCountsOnce(): void
    {
        $parsed = (new MimeMessageParser(new BulkMailDetector()))->parse(self::bounceMessage(), 1, 'INBOX');

        $this->consumer->analyze($this->candidateFrom($parsed->bodyText));
        $this->consumer->analyze($this->candidateFrom($parsed->bodyText));

        $this->assertSame(
            1,
            $this->states->find('parent@exemple.be')?->failures,
            'a folder re-read must not cost the member a strike.'
        );
    }

    /**
     * **An old receipt buys one answer, not an endless supply.** Knowing
     * one address the unit has ever mailed — any parent's, from any
     * mailing — would otherwise be enough to forge a way to a block, one
     * message at a time. A second send is what re-opens the door.
     */
    public function testASecondForgedReportNeedsASecondRealSend(): void
    {
        $parsed = (new MimeMessageParser(new BulkMailDetector()))->parse(self::bounceMessage(), 1, 'INBOX');

        foreach (range(1, 5) as $ignored) {
            $this->consumer->analyze($this->candidateFrom($parsed->bodyText));
        }

        $this->assertSame(1, $this->states->find('parent@exemple.be')?->failures);
        $this->assertFalse($this->states->find('parent@exemple.be')?->isBlocked());
    }

    /**
     * An ordinary message that happens to reach the box records nothing.
     * The consumer is offered every message in a box opened to it, so
     * « ce n'en est pas un » is its most frequent answer by far.
     */
    public function testAnOrdinaryMessageRecordsNothing(): void
    {
        $this->consumer->analyze($this->candidateFrom(
            "Bonjour,\n\nEst-ce que la réunion de samedi est maintenue ?\n\nMerci !\n"
        ));

        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_bounce_states');
        $this->assertNotFalse($statement);
        $this->assertSame(0, (int) $statement->fetchColumn());
    }

    /**
     * Re-reading a stored message would double-count: the body is still
     * there, so a second pass records the same failure again and blocks an
     * address in half the bounces it should take.
     */
    public function testTheConsumerDeclaresNothingToDoOnASecondPass(): void
    {
        $this->assertTrue($this->consumer->analyzeStored(
            $this->createMock(\Modules\InboundMail\Api\InboundMessage::class)
        )->isEmpty());
    }

    public function testItShowsNoMessageToAnybody(): void
    {
        $this->assertSame(0, $this->consumer->triageAudienceCount());
        $this->assertFalse($this->consumer->canRead('anything', [], 'superadmin'));
    }

    private function candidateFrom(string $bodyText): CandidateMessage
    {
        return new CandidateMessage(
            mailboxId: 1,
            subject: 'Undelivered Mail Returned to Sender',
            fromEmail: 'MAILER-DAEMON@exemple.be',
            fromName: null,
            messageId: '<bounce-1@exemple.be>',
            inReplyTo: null,
            references: [],
            toEmails: ['info@unite.be'],
            sentAt: new \DateTimeImmutable('2026-09-15 08:00:00'),
            bodyText: $bodyText,
            bodyHtml: ''
        );
    }
}
