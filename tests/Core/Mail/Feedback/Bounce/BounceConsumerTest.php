<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceConsumer;
use Core\Mail\Feedback\Bounce\BounceService;
use Core\Mail\Feedback\Bounce\BounceStateRepository;
use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Probe\MailProbeSender;
use Core\Mail\Transport\MailLane;
use Core\Mail\Probe\MailProbeRepository;
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
    private MailProbeRepository $probes;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->states = new BounceStateRepository($this->pdo, $encryption);
        $this->probes = new MailProbeRepository($this->pdo, $encryption);
        $this->consumer = new BounceConsumer(new BounceService($this->states), $this->probes);

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

    /**
     * The same bounce, but the message it quotes back is a probe.
     *
     * That is the whole mechanism: a probe's subject carries `SM-XXXXXX`
     * (MailProbeSender::subjectFor()), a bounce quotes the subject of the
     * message it rejected, so the code is in this body — and
     * `MailProbeSender::codeIn()` has always been able to read it.
     */
    private static function bounceQuoting(string $subject): string
    {
        return str_replace('Subject: Reunion de samedi', 'Subject: ' . $subject, self::bounceMessage());
    }

    private function probeSent(string $code): int
    {
        return $this->probes->record(
            $code,
            'parent@exemple.be',
            null,
            'Relais principal',
            MailLane::Transactional,
            new \DateTimeImmutable('2026-09-15 07:00:00')
        );
    }

    /**
     * **The reason a probe's row can say why, and not only « jamais reçu »**
     * (issue #419).
     *
     * Everything needed was already there and unused: the code in the
     * subject, `codeIn()` to read it, `idx_mail_probes_code` to find the
     * row. The category and the status code are what gets attached —
     * deliberately NOT the diagnostic text, which
     * `DeliveryStatusReport` reads and drops because it quotes the address
     * back, and which this table's own `destination_encrypted` exists to
     * keep out of the clear.
     */
    public function testABounceQuotingAProbeGivesThatProbeItsReason(): void
    {
        $id = $this->probeSent('SM-7K2XPQ');

        $this->consumer->analyze($this->candidateFrom(
            self::bounceQuoting(MailProbeSender::subjectFor('SM-7K2XPQ'))
        ));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe);
        $this->assertNotNull($probe->bounce, 'the bounce quoted this probe and nothing was attached');
        $this->assertSame(BounceCategory::NoSuchAddress, $probe->bounce->category);
        $this->assertSame('5.1.1', $probe->bounce->statusCode);
        $this->assertSame('Adresse inexistante (5.1.1)', $probe->bounce->label());
        // And the address is still blocked: tracing is an extra, never a
        // replacement for what IT-05 is for.
        $this->assertNotNull($this->states->find('parent@exemple.be'));
    }

    /**
     * **A bounce that names no probe is the ordinary case, not a failure.**
     *
     * A server that rejects before citing the message it rejected sends no
     * code at all, and the overwhelming majority of bounces are about
     * ordinary mail rather than probes. The address must still be blocked —
     * which is what makes this test about the coupling rather than about the
     * absence: a tracing step that threw, or that returned early before
     * `record()`, would cost the protection the whole iteration is for.
     */
    public function testABounceNamingNoProbeStillBlocksAndAttachesNothing(): void
    {
        $id = $this->probeSent('SM-7K2XPQ');

        $this->consumer->analyze($this->candidateFrom(self::bounceMessage()));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe);
        $this->assertNull($probe->bounce, 'nothing quoted this probe, so nothing may be attached to it');
        $this->assertNotNull($this->states->find('parent@exemple.be'));
    }

    /**
     * A code that belongs to no probe: someone else's subject happening to
     * contain something shaped like one, or a probe whose row has been
     * purged. Nothing to attach, and nothing to report — the bounce is
     * recorded exactly as it would have been.
     */
    public function testACodeThatMatchesNoProbeIsNotAnError(): void
    {
        $id = $this->probeSent('SM-7K2XPQ');

        $this->consumer->analyze($this->candidateFrom(
            self::bounceQuoting(MailProbeSender::subjectFor('SM-ZZZZZZ'))
        ));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe);
        $this->assertNull($probe->bounce);
        $this->assertNotNull($this->states->find('parent@exemple.be'));
    }

    /**
     * **A REPLY to a probe quotes its code and is not a bounce.**
     *
     * The likeliest one is the operator's own: they send a probe to an
     * address they control, find it, and answer it to be sure the road works
     * both ways. The reply quotes « Vérification de délivrabilité SM-… » in
     * its subject, so the code is there — and there is no delivery-status
     * part anywhere, so there is no category and no status code to attach.
     *
     * This is the case the `$reports === []` guard is for, and the only one:
     * without it the tracing would reach `$reports[0]` on an empty list.
     * Found by mutation — removing that guard passed every other test in
     * this class, because they all feed either a real bounce or a body with
     * no code in it at all.
     */
    public function testAReplyQuotingAProbeIsNotABounceAndAttachesNothing(): void
    {
        $id = $this->probeSent('SM-7K2XPQ');

        $this->consumer->analyze($this->candidateFrom(
            "Bien reçu, merci.\r\n"
            . "\r\n"
            . "> Objet : " . MailProbeSender::subjectFor('SM-7K2XPQ') . "\r\n"
            . "> Ceci est un message de vérification.\r\n"
        ));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe);
        $this->assertNull($probe->bounce, 'a reply is not a rejection and must attach nothing');
        // And no address was blocked on the strength of a thank-you note.
        $this->assertNull($this->states->find('parent@exemple.be'));
    }

    /**
     * **A forged bounce must not be able to write on a probe's line.**
     *
     * Found in review on #562, in code this very pull request added. The
     * anti-forgery gate of this whole file is the return value of
     * `BounceService::record()`: it answers null when no `mail_send_receipts`
     * row shows the site ever wrote to the reported address — « the report is
     * somebody's word about a message we cannot show we sent ». `analyze()`
     * calls it and, until this test, threw that verdict away before tracing.
     *
     * The attack needs nothing privileged. A probe's code travels in the
     * clear in its own subject, so its recipient — or anyone who reads that
     * mailbox — can post a hand-written `multipart/report` to the site's
     * bounce address quoting the code, with a `Final-Recipient` the site
     * never wrote to. And it would stick: `recordBounce()` is first-write-
     * wins, so no genuine bounce could ever correct it, and `bounce_at` comes
     * from the forged message's own date.
     *
     * The address here has no receipt — `setUp()` writes one for
     * `parent@exemple.be` only — so the gate refuses the bounce, and the
     * probe must come out untouched.
     */
    public function testAForgedBounceForAnAddressWithNoReceiptTouchesNoProbe(): void
    {
        // **The probe went to the very address the forged report names**, so
        // the recipient comparison cannot be what refuses this: only the
        // receipt gate can. Written the other way round first, with a
        // mismatched address, this test passed against a consumer that
        // ignored the gate entirely — mutation said so, and it was right.
        $id = $this->probes->record(
            'SM-7K2XPQ',
            'jamais-ecrit@exemple.be',
            null,
            'Relais principal',
            MailLane::Transactional,
            new \DateTimeImmutable('2026-09-15 07:00:00')
        );

        $forged = str_replace(
            'parent@exemple.be',
            'jamais-ecrit@exemple.be',
            self::bounceQuoting(MailProbeSender::subjectFor('SM-7K2XPQ'))
        );
        $this->consumer->analyze($this->candidateFrom($forged));

        // The gate did its job on the address itself…
        $this->assertNull(
            $this->states->find('jamais-ecrit@exemple.be'),
            'no receipt, so no bounce state — this is the gate this test is about'
        );
        // …and the probe must not have been written on either.
        $probe = $this->probes->find($id);
        $this->assertNotNull($probe);
        $this->assertNull(
            $probe->bounce,
            'a report the receipt gate refused may not leave a reason on a probe'
        );
    }

    /**
     * **A genuine bounce for somebody else's address is not this probe's
     * reason either.**
     *
     * The second half of the same finding. This report passes the receipt
     * gate — the unit really did write to that address — so filtering on the
     * gate alone would let it through. But the address is not the one the
     * probe went to, and a probe goes to exactly one address: quoting the
     * code proves the bounce mentions the probe, never that it is about it.
     *
     * Reachable without an attacker: a digest bounce that carries two
     * messages, or a mailing whose subject happened to quote a code an
     * operator pasted somewhere.
     */
    public function testABounceForAnotherAddressIsNotAttachedToTheProbe(): void
    {
        // The probe went somewhere else entirely.
        $id = $this->probes->record(
            'SM-7K2XPQ',
            'sonde@exemple.be',
            null,
            'Relais principal',
            MailLane::Transactional,
            new \DateTimeImmutable('2026-09-15 07:00:00')
        );

        $this->consumer->analyze($this->candidateFrom(
            self::bounceQuoting(MailProbeSender::subjectFor('SM-7K2XPQ'))
        ));

        // The bounce itself is recorded — it is a real failure for a real
        // address the unit wrote to.
        $this->assertNotNull($this->states->find('parent@exemple.be'));
        // It just says nothing about this probe.
        $this->assertNull(
            $this->probes->find($id)?->bounce,
            'the probe went to sonde@exemple.be; this bounce is about parent@exemple.be'
        );
    }

    /**
     * The two sides of the comparison do not come from the same place, and
     * the fold is what bridges them.
     *
     * `DeliveryStatusReport::$recipient` is lower-cased and stripped of its
     * `rfc822;` prefix by the parser. `MailProbe::$destination` is what an
     * administrator typed into a form — with whatever capitals and whatever
     * stray space their keyboard produced. A byte comparison between the two
     * would refuse a perfectly genuine bounce for a probe whose address was
     * typed `Parent@Exemple.BE`, and the operator would never learn why.
     */
    public function testTheAddressesAreComparedWithoutCaseOrStraySpace(): void
    {
        $id = $this->probes->record(
            'SM-7K2XPQ',
            ' Parent@Exemple.BE ',
            null,
            'Relais principal',
            MailLane::Transactional,
            new \DateTimeImmutable('2026-09-15 07:00:00')
        );

        $this->consumer->analyze($this->candidateFrom(
            self::bounceQuoting(MailProbeSender::subjectFor('SM-7K2XPQ'))
        ));

        $this->assertNotNull(
            $this->probes->find($id)?->bounce,
            'the report says parent@exemple.be and the probe says " Parent@Exemple.BE " — the same address'
        );
    }

    /**
     * The FIRST rejection stays.
     *
     * A mailbox that bounces once bounces again, and the later ones are
     * about other messages that quoted nothing. Overwriting would move the
     * probe's date forward every time somebody else's mail failed, and the
     * row would end up dated by an event that had nothing to do with it.
     */
    public function testASecondBounceDoesNotRewriteTheFirstReason(): void
    {
        $id = $this->probeSent('SM-7K2XPQ');
        $quoting = self::bounceQuoting(MailProbeSender::subjectFor('SM-7K2XPQ'));

        $this->consumer->analyze($this->candidateFrom($quoting));
        $first = $this->probes->find($id)?->bounce?->at;
        $this->assertNotNull($first);

        // A later bounce, same probe code quoted, a different verdict from
        // the far end — a full mailbox this time.
        $this->consumer->analyze($this->candidateFrom(str_replace(
            ['Status: 5.1.1', 'Diagnostic-Code: smtp; 550 5.1.1 User unknown'],
            ['Status: 5.2.2', 'Diagnostic-Code: smtp; 552 5.2.2 Mailbox full'],
            $quoting
        )));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe?->bounce);
        $this->assertSame(
            BounceCategory::NoSuchAddress,
            $probe->bounce->category,
            'the first rejection is the one that explains this probe'
        );
        $this->assertSame('5.1.1', $probe->bounce->statusCode);
    }

    /**
     * **The refusal is dated by this site, never by the server that sent
     * it** (found in review on #562).
     *
     * `$message->sentAt` is the far end's own `Date:` header with whatever
     * UTC offset it carried, and `recordBounce()` writes a naive `DATETIME`
     * on a clock `AppClock` pins to `Europe/Brussels`. So the header below —
     * years in the past, in a distant offset, which is what a
     * badly-configured or hostile MTA sends — used to become the probe's
     * bounce date, and the send/refusal interval the page shows became
     * nonsense. First-write-wins meant it could never be corrected either.
     *
     * The assertion is a window rather than an equality, because the value
     * is « now »: what it pins is that the stored instant came from the
     * clock this test runs on and not from the message.
     */
    public function testTheBounceIsDatedByThisSiteAndNotByTheServerThatSentIt(): void
    {
        $id = $this->probeSent('SM-7K2XPQ');
        $before = new \DateTimeImmutable();

        $this->consumer->analyze($this->candidateFrom(
            self::bounceQuoting(MailProbeSender::subjectFor('SM-7K2XPQ')),
            new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('-0700'))
        ));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe?->bounce);
        $this->assertGreaterThanOrEqual(
            $before->format('Y-m-d H:i:s'),
            $probe->bounce->at->format('Y-m-d H:i:s'),
            'the refusal is dated from reception, so it cannot precede the moment this test began'
        );
        $this->assertLessThanOrEqual(
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $probe->bounce->at->format('Y-m-d H:i:s')
        );
        $this->assertStringNotContainsString(
            '2020',
            $probe->bounce->at->format('Y-m-d H:i:s'),
            'the far end\'s own Date: header must not be what the page shows'
        );
    }

    private function candidateFrom(string $bodyText, ?\DateTimeImmutable $sentAt = null): CandidateMessage
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
            // What `MimeMessageParser::parseDate()` made of the far end's
            // own `Date:` header — its offset included, since nothing
            // normalises it. Overridable so a test can hand over the header
            // a hostile or badly-configured server would send.
            sentAt: $sentAt ?? new \DateTimeImmutable('2026-09-15 08:00:00'),
            bodyText: $bodyText,
            bodyHtml: ''
        );
    }
}
