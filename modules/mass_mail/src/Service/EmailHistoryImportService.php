<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Journal\JournalService;
use Core\Member\UnitStaffSectionService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Api\EmailHistoryImportInterface;
use Modules\MassMail\Api\ImportedEmail;
use Modules\MassMail\Api\ImportedEmailResult;
use Modules\MassMail\Api\ImportedRecipient;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Api\SenderSection;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\EmailAttachment;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\ImportedEmailRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\TargetRepository;

/**
 * The implementation behind Api\EmailHistoryImportInterface — an email of a
 * former site written into the history, and nothing sent.
 *
 * It goes through the repositories, never through Service\MassMailService's
 * lifecycle (createDraft → moveToTest → startSending), because every step of
 * that lifecycle leads to a send: the freeze writes 'pending' messages,
 * schedules Task\SendBatchHandler, and the batch notifies the accounts
 * concerned. What it keeps of the module's rules is everything that is not
 * a send — sanitizing, encryption at rest, the file storage, the journal.
 */
class EmailHistoryImportService implements EmailHistoryImportInterface
{
    /** The fixed reason a failed imported message shows: the former site's own text is not kept. */
    public const FAILED_MESSAGE = 'Échec signalé par l\'ancien site.';

    public function __construct(
        private ImportedEmailRepository $importedEmails,
        private DeliveryRepository $deliveries,
        private TargetRepository $targets,
        private EmailAttachmentRepository $attachments,
        private MemberResolutionRepository $memberResolution,
        private UploadHandler $uploadHandler,
        private HtmlSanitizer $htmlSanitizer,
        private JournalService $journal
    ) {
    }

    /**
     * D6: the section whose own address sent it, compared the way this
     * module compares every address (trimmed, lowercased) — and in PHP,
     * not in SQL, so no collation decides it. Two sections sharing that
     * address cannot tell which of them wrote: the Staff d'U then, rather
     * than a guess.
     */
    public function senderSection(string $senderAddress): ?SenderSection
    {
        $key = mb_strtolower(trim($senderAddress));
        $matches = [];
        $staffId = null;
        foreach ($this->importedEmails->findSectionAddresses() as $section) {
            if ($section['desk_code'] === UnitStaffSectionService::DESK_CODE) {
                $staffId = $section['id'];
            }
            if ($key !== '' && $section['email'] !== null && mb_strtolower(trim($section['email'])) === $key) {
                $matches[] = $section['id'];
            }
        }

        if (count($matches) === 1) {
            return new SenderSection($matches[0], true);
        }

        return $staffId !== null ? new SenderSection($staffId, false) : null;
    }

    public function findImported(string $importSource): ?int
    {
        return $this->importedEmails->findIdByImportSource($importSource);
    }

    public function acceptedAttachmentTypes(): array
    {
        return EmailAttachment::ALLOWED_MIMES;
    }

    public function maxAttachmentBytes(): int
    {
        return EmailAttachment::MAX_SIZE_BYTES;
    }

    public function import(ImportedEmail $email): ImportedEmailResult
    {
        $section = $this->senderSection($email->senderAddress);
        if ($section === null) {
            throw new MassMailException(
                'Ce courriel ne peut être rattaché à aucune section : la section « Staff d\'U » n\'existe pas encore.'
            );
        }

        $body = $this->htmlSanitizer->sanitize($email->bodyHtml);
        if (!self::hasContent($body)) {
            throw new MassMailException('Ce courriel n\'a aucun contenu une fois nettoyé.');
        }

        $sentAt = self::localDateTime($email->sentAt);
        $emailId = $this->importedEmails->insert(
            trim($email->subject),
            $body,
            $section->sectionId,
            $sentAt,
            self::localDateTime(new \DateTimeImmutable()),
            $email->importSource,
            trim($email->senderAddress),
            self::optionalText($email->senderName, 255),
            self::optionalText($email->audienceName, 255)
        );

        $targetIds = $this->writeTargets($emailId, $email);
        $deliveryCount = 0;
        foreach ($email->recipients as $recipient) {
            $deliveryId = $this->deliveries->createImported(
                $emailId,
                trim($recipient->address),
                self::deliveryStatus($recipient->status),
                self::localDateTime($recipient->sentAt ?? $email->sentAt),
                self::FAILED_MESSAGE
            );
            $deliveryCount++;

            $concerned = [];
            foreach ($recipient->memberIds as $memberId) {
                if (isset($targetIds[$memberId])) {
                    $concerned[] = $targetIds[$memberId];
                }
            }
            if ($concerned !== []) {
                $this->deliveries->linkTargets($deliveryId, $concerned);
            }
        }

        [$stored, $refused] = $this->writeAttachments($emailId, $email);

        // Which email and what it holds — never the subject (a subject can
        // name somebody), never an address (SECURITY.md §11).
        $this->journal->log(
            'mass_mail',
            'email_imported',
            'info',
            'Email groupé #' . $emailId . ' : importé de l\'ancien site, en lecture seule',
            [
                'email_id' => $emailId,
                'section_id' => $section->sectionId,
                'deliveries' => $deliveryCount,
                'targets' => count($targetIds),
                'attachments' => $stored,
                'attachments_refused' => $refused,
            ],
            $email->importedBy
        );

        return new ImportedEmailResult($emailId, $deliveryCount, count($targetIds), $stored, $refused);
    }

    /**
     * One target per member the email concerned, whether through an
     * address of theirs or not — the member page's history. Its year is the
     * member's most recent profile, as for a mail merge's Tiers: the page
     * of that year is where the entry links.
     *
     * @return array<int, int> target id keyed by member id
     */
    private function writeTargets(int $emailId, ImportedEmail $email): array
    {
        $memberIds = $email->memberIds;
        foreach ($email->recipients as $recipient) {
            array_push($memberIds, ...$recipient->memberIds);
        }
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $profiles = $this->memberResolution->resolveMergeMembers($memberIds);
        $targetIds = [];
        foreach ($memberIds as $memberId) {
            $targetIds[$memberId] = $this->targets->create(
                $emailId,
                $memberId,
                $profiles[$memberId]['scout_year_id'] ?? null
            );
        }

        return $targetIds;
    }

    /**
     * Through Core\File\UploadHandler, exactly as a chief's own attachment:
     * the real type read from the bytes, the size, the disk budget, the
     * images re-encoded without their metadata, and the same access rule
     * (`chief`, module.json's storage.attachments). One the storage refuses
     * is left out and counted; it never costs the email.
     *
     * @return array{0: int, 1: int} stored, refused
     */
    private function writeAttachments(int $emailId, ImportedEmail $email): array
    {
        $stored = 0;
        $refused = 0;
        foreach ($email->attachments as $attachment) {
            $size = @filesize($attachment->path);
            if ($size === false) {
                $refused++;
                continue;
            }
            try {
                $fileId = $this->uploadHandler->handle(
                    [
                        'tmp_name' => $attachment->path,
                        'name' => self::optionalText($attachment->originalName, 255) ?? 'piece-jointe',
                        'size' => $size,
                        'error' => UPLOAD_ERR_OK,
                    ],
                    'mass_mail/attachments',
                    EmailAttachment::ALLOWED_MIMES,
                    EmailAttachment::MAX_SIZE_BYTES,
                    'chief',
                    'mass_mail',
                    $email->importedBy
                );
            } catch (UploadException) {
                $refused++;
                continue;
            }
            $this->attachments->create($emailId, $fileId);
            $stored++;
        }

        return [$stored, $refused];
    }

    private static function deliveryStatus(string $status): string
    {
        return match ($status) {
            ImportedRecipient::ACCEPTED => Delivery::STATUS_SENT,
            ImportedRecipient::FAILED => Delivery::STATUS_ERROR,
            default => Delivery::STATUS_UNKNOWN,
        };
    }

    /**
     * A sanitized body says something when it keeps any text or an image —
     * an email whose whole content was a script is empty.
     */
    public static function hasContent(string $sanitizedHtml): bool
    {
        return trim(html_entity_decode(strip_tags($sanitizedHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}") !== ''
            || stripos($sanitizedHtml, '<img') !== false;
    }

    /**
     * DATETIME columns hold the site's local time: the connection's session
     * time zone is PHP's (Core\Database\Connection).
     */
    private static function localDateTime(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private static function optionalText(?string $value, int $maxCharacters): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $maxCharacters ? mb_substr($value, 0, $maxCharacters) : $value;
    }
}
