<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

use Core\Mail\Probe\MailProbeRepository;
use Core\Mail\Probe\MailProbeSender;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;

/**
 * Recognises a bounce and records it against the address that failed
 * (roadmap IT-05).
 *
 * **The core implementing a module's contract, and only its `Api\`** —
 * the same allowed arrow as {@see \Core\Mail\Feedback\ReturnPathConsumer}
 * (§7.5, `Tests\Architecture\ModuleBoundariesTest`). The composition root
 * builds it only inside the branch that runs when `inbound_mail` is
 * enabled, so with the module off nothing here is ever loaded and the
 * Rebonds sub-page is simply absent. That is the whole of D2.
 *
 * **The report arrives in the body, and that is not an accident of this
 * class.** A bounce is `multipart/report`, whose `message/delivery-status`
 * part carries no filename and no `Content-Disposition`, so
 * `Mime\MimeMessageParser` appends it to the text body — separated from
 * the human-readable part by the blank line it joins parts with, which is
 * exactly the boundary {@see DeliveryStatusReport::parseAll()} splits on.
 * `Api\CandidateAttachment` carries metadata and no bytes, so if that part
 * ever became an attachment instead, this consumer would go silently
 * blind. `Tests\Core\Mail\Feedback\Bounce\BounceConsumerTest` pins it.
 *
 * **It claims nothing.** A bounce is recorded against an address and then
 * answered `nothing()`: it is associated with no business object, so the
 * unit's ordinary unassociated-mail retention removes it on schedule and
 * nobody's triage list grows a row for a machine writing to a machine.
 */
final class BounceConsumer implements MessageConsumerInterface
{
    public const CONSUMER_ID = 'core_mail_bounce';

    public function __construct(
        private BounceService $bounces,
        /**
         * Required, deliberately, although a default would have spared two
         * call sites. This consumer is registered from BOTH entry points —
         * public/index.php and public/scheduler-bootstrap.php — and a
         * defaulted dependency turns a site somebody forgets into a
         * feature that quietly does nothing. ARCHITECTURE.md §8.17 records
         * what that costs: a handler missing from the real cron entry point
         * failed every background backup and nothing said so. A fatal on
         * startup is the cheaper failure.
         */
        private MailProbeRepository $probes
    )
    {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Courrier sortant — rebonds';
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        // **One instant for the whole message.** Both writes below date the
        // same event — an address refused us, once.
        //
        // Passing it to `record()` is **not a defence**, and saying so is
        // the point: that method defaults to « now » itself, so removing
        // the argument changes nothing any test can see, and mutation says
        // as much. It is here so that one instant reads as one instant
        // rather than as two calls that happen to agree — which is what
        // the bug found in review on #562 looked like from the outside,
        // right up to the moment the two clocks turned out to be genuinely
        // different. `traceToProbe()` below is where the argument carries
        // its weight, and it has a test.
        $now = new \DateTimeImmutable();

        $accepted = [];
        foreach (DeliveryStatusReport::parseAll($message->bodyText) as $report) {
            // **The return value is the anti-forgery verdict, not a
            // courtesy.** `record()` answers null when no `mail_send_receipts`
            // row shows this site ever wrote to the reported address — « the
            // report is somebody's word about a message we cannot show we
            // sent ». Anything built on a refused report is built on that
            // word. Throwing the verdict away is what made the tracing below
            // forgeable (found in review on #562).
            if ($this->bounces->record($report, $now) !== null) {
                $accepted[] = $report;
            }
        }

        $this->traceToProbe($message, $accepted, $now);

        return AnalysisResult::nothing();
    }

    /**
     * Give a probe the reason its message came back (issue #419).
     *
     * A manual probe carries `SM-XXXXXX` in its subject, and a bounce that
     * quotes the message it rejected quotes that subject with it — so the
     * link is already in the body this method has just parsed. Nothing else
     * was missing: `MailProbeSender::codeIn()` has always been able to read
     * the code, and `mail_probes` has carried an index on it from the start.
     *
     * **Every step here may come up empty, and none of them is an error.**
     * A server that rejects before citing the original sends no code; a code
     * that belongs to no probe is a coincidence in someone else's subject; a
     * probe that already carries a rejection keeps its first one. The page
     * says « jamais reçu » in all three cases, which stays true — it is what
     * the operator saw when they went and looked.
     *
     * The `SM-` prefix is distinct from IT-03's `RET-` on purpose, so there
     * is no ambiguity to resolve between the two mechanisms.
     *
     * **Two things have to hold before anything is written, and neither was
     * there until review caught it on #562.** A probe's code travels in the
     * clear in its own subject, so quoting it proves nothing: anybody who can
     * read the probe can post a hand-written `multipart/report` to the site's
     * bounce mailbox. And `recordBounce()` is first-write-wins, so a forged
     * reason could never be corrected afterwards.
     *
     * So: the report must have PASSED the receipt gate — `$reports` here is
     * the accepted list, never the parsed one — and its recipient must be the
     * address this probe actually went to. The second is not only against
     * forgery: a digest bounce carrying two messages passes the gate for both,
     * and only one of them can be the probe.
     *
     * **The date is OURS, never the bouncing server's.**
     * `$message->sentAt` is `MimeMessageParser::parseDate()` on the far
     * end's own `Date:` header, kept with whatever UTC offset it carried —
     * and `recordBounce()` writes a naive `DATETIME`, a column
     * {@see \Core\Config\AppClock} pins to `Europe/Brussels` like every
     * other one here. `mail_probes.sent_at` is stamped from this site's
     * clock, so the two would sit on different ones: a probe sent at 23:00
     * Brussels and refused two seconds later by a server writing
     * `Date: … 14:00:07 -0700` would read as refused nine hours BEFORE it
     * left. That destroys the one thing the pair is shown for — the
     * interval between the send and the refusal — and `recordBounce()` is
     * first-write-wins, so the skew could never be corrected afterwards.
     * A hostile header is the same hole with a worse number in it.
     *
     * Reception time is not the instant the far end refused, and that is
     * the honest cost: it is later by however long the mailbox went
     * unpolled. But it is later by minutes on the same clock, where the
     * header is wrong by hours on another — and `BounceService::record()`
     * above has always used reception time, so this is also the two halves
     * of one event finally agreeing. The far end's claimed time is dropped,
     * like its diagnostic text and for a related reason: it is its word.
     *
     * @param list<DeliveryStatusReport> $reports the ones `BounceService`
     *                                            accepted, never the parsed ones
     */
    private function traceToProbe(CandidateMessage $message, array $reports, \DateTimeImmutable $now): void
    {
        if ($reports === []) {
            return;
        }

        // A fast path and not a guard, which is worth saying because it
        // reads like one: `findByCode('')` would answer null a line later
        // and the outcome would be identical. It is here because this method
        // runs on EVERY bounce the site receives, and almost none of them
        // quote a probe — one avoided round trip per bounce, not a defence
        // against anything. (Mutation says as much: removing it breaks no
        // test, and no test was added to pretend otherwise.)
        $code = MailProbeSender::codeIn($message->bodyText);
        if ($code === null) {
            return;
        }

        $probe = $this->probes->findByCode($code);
        if ($probe === null) {
            return;
        }

        // The report about THIS probe's address, and no other. A probe goes
        // to exactly one address, so a bounce carrying several is a bounce
        // about a message that was not only the probe — and a bounce about
        // one other address is not the probe's reason at all, however
        // faithfully it quotes the code.
        //
        // `DeliveryStatusReport::$recipient` is lower-cased without its
        // `rfc822;` prefix; `MailProbe::$destination` is what the operator
        // typed, so it is folded here rather than trusted to match.
        foreach ($reports as $report) {
            if (strcasecmp($report->recipient, trim($probe->destination)) !== 0) {
                continue;
            }

            $this->probes->recordBounce($probe->id, $report->category, $report->statusCode, $now);

            return;
        }
    }

    /**
     * Everything this consumer reads is on the candidate, and re-reading a
     * stored message would double-count: the body is still there, so a
     * second pass would record the same failure twice and block an address
     * in half the bounces it should take.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
    }

    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
    }

    /**
     * This consumer owns no business object, so there is nothing of « its
     * own » for anybody to open. A refusal is the only honest answer.
     */
    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        return false;
    }

    public function describeReference(string $businessReference): ?string
    {
        return null;
    }

    public function describeEvidence(): array
    {
        return ['rapport de non-remise (RFC 3464) désignant une adresse de cette unité'];
    }

    public function triageAudienceLabel(): string
    {
        return 'personne : un rebond est enregistré contre une adresse, puis le message est oublié';
    }

    public function triageAudienceCount(): int
    {
        // Nobody is shown these messages. Inflating the figure would make
        // every other consumer's count read as noise — the rule is on
        // MessageConsumerInterface::triageAudienceCount(), which is where
        // it says why the figure has to be exact or is worse than absent.
        return 0;
    }
}
