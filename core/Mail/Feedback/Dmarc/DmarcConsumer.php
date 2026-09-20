<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

use Core\Journal\JournalService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\PayloadConsumerInterface;

/**
 * Reads the DMARC aggregate reports that arrive in a watched mailbox
 * (roadmap IT-06).
 *
 * **The core implementing a module's contract, and only its `Api\`** — the
 * same allowed arrow as {@see \Core\Mail\Feedback\Bounce\BounceConsumer}
 * and {@see \Core\Mail\Feedback\ReturnPathConsumer} (§7.5,
 * `Tests\Architecture\ModuleBoundariesTest`). The composition root builds
 * it only inside the branch that runs when `inbound_mail` is enabled, so
 * with the module off nothing here is loaded and the DMARC sub-page is
 * simply absent. That is the whole of D2.
 *
 * **The report arrives as a compressed attachment, which is why this is
 * the one consumer that reads bytes.** `Api\CandidateAttachment` carries
 * metadata only, and `Service\AttachmentPolicy` refuses archives outright
 * on the sound grounds that a zip is a container nobody inspected — so a
 * consumer built on either would see every report as `MIME_REJECTED` and
 * read none. {@see \Modules\InboundMail\Api\PayloadConsumerInterface} is
 * the narrow, declared, bounded door that answers that, and this class
 * declares the smallest ceiling that fits a real report.
 *
 * **It claims nothing**, exactly like the bounce consumer: counters are
 * recorded against the unit's own domain and the message is answered
 * `nothing()`, so the ordinary unassociated-mail retention removes it on
 * schedule and nobody's triage list grows a row for a machine writing to
 * a machine.
 */
final class DmarcConsumer implements MessageConsumerInterface, PayloadConsumerInterface
{
    public const CONSUMER_ID = 'core_mail_dmarc';

    /**
     * The types a reporter actually sends.
     *
     * Google gzips, several others zip, and a few post the XML bare. The
     * sniffed type decides — never the `Content-Type` the sender wrote,
     * which costs nothing to lie about.
     */
    public const MIME_TYPES = [
        'application/gzip',
        'application/x-gzip',
        'application/zip',
        'application/xml',
        'text/xml',
    ];

    /**
     * **Two megabytes compressed, which is enormous for what this is.** A
     * daily report for a unit that sends a few thousand messages is a
     * handful of kilobytes; the largest a big provider posts for a busy
     * domain is well under a megabyte. Anything past this is not a report
     * that got big, it is something else wearing a report's name — and
     * whatever it expands to is bounded again by {@see BoundedArchive}.
     */
    public const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private DmarcReportParser $parser,
        private DmarcReportRepository $reports,
        private BoundedArchive $archive,
        private ?JournalService $journal = null
    ) {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Courrier sortant — rapports DMARC';
    }

    public function payloadMimeTypes(): array
    {
        return self::MIME_TYPES;
    }

    public function maxPayloadBytes(): int
    {
        return self::MAX_PAYLOAD_BYTES;
    }

    public function analyzePayloads(CandidateMessage $message, array $payloads): AnalysisResult
    {
        $now = new \DateTimeImmutable();

        foreach ($payloads as $payload) {
            foreach ($this->archive->membersOf($payload->bytes, $payload->mimeType) as $member) {
                $report = $this->parser->parse($member);
                if ($report === null) {
                    continue;
                }

                if ($this->reports->record($report, $now)) {
                    $this->journalReport($report);
                }
            }
        }

        // Claims nothing: see the class docblock.
        return AnalysisResult::nothing();
    }

    /**
     * **No address, no recipient, and no source IP.** An aggregate report
     * names no person by construction (D12), and the journal is read on a
     * screen and carried in a support archive — so what goes in is the
     * shape of what arrived, never its contents. The reporter's name is
     * a company's, which is not personal data and is what makes the line
     * worth reading at all.
     */
    private function journalReport(DmarcReport $report): void
    {
        $this->journal?->log(
            'core',
            'mail_dmarc_report_recorded',
            'info',
            'Rapport DMARC enregistré',
            [
                'organisation' => $report->organisation,
                'domain' => $report->domain,
                'sources' => count($report->records),
                'messages' => $report->totalMessages(),
                'authenticated' => $report->authenticatedMessages(),
            ]
        );
    }

    /**
     * Nothing here: a DMARC report says nothing in its body, and the
     * attachment is read by the payload pass above.
     */
    public function analyze(CandidateMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    /**
     * Nothing either. The report was read on arrival and written down;
     * re-reading a stored message would find no attachment to read, since
     * the archive was never stored — which is the point.
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
        return ['rapport agrégé DMARC (RFC 7489) en pièce jointe compressée'];
    }

    public function triageAudienceLabel(): string
    {
        return 'personne : les compteurs sont enregistrés, puis le message est oublié';
    }

    public function triageAudienceCount(): int
    {
        // Nobody is shown these messages. Inflating the figure would make
        // every other consumer's count read as noise (§7.9).
        return 0;
    }
}
