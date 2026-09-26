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
        $reports = DeliveryStatusReport::parseAll($message->bodyText);
        foreach ($reports as $report) {
            $this->bounces->record($report);
        }

        $this->traceToProbe($message, $reports);

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
     * @param list<DeliveryStatusReport> $reports
     */
    private function traceToProbe(CandidateMessage $message, array $reports): void
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

        // The first failed recipient. A probe goes to one address, so a
        // report with several means this bounce is about a message that was
        // not the probe — and the code was quoted for another reason. Taking
        // the first is still the right answer for the ordinary case, and the
        // wrong one costs a category on one row rather than a blocked
        // address: `record()` above has already handled every recipient on
        // its own terms.
        $first = $reports[0];
        $this->probes->recordBounce($probe->id, $first->category, $first->statusCode, $message->sentAt);
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
