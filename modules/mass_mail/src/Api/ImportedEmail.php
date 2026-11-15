<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * One email of a former site, as {@see EmailHistoryImportInterface::import()}
 * takes it. Nothing in it is invented: a recipient, a member or an
 * attachment the former site did not know is simply absent.
 *
 * - `$importSource` is the provenance (installation and source id), the
 *   module's own key against a duplicate, at most 400 bytes.
 * - `$bodyHtml` is HTML as the former site sent it; the module sanitizes it.
 *   A mail merge comes as ONE email, its template text (decision D3).
 * - `$memberIds` are the members the email concerned with no known address
 *   of theirs; a recipient names its own.
 */
final class ImportedEmail
{
    /**
     * @param list<ImportedRecipient> $recipients
     * @param list<int> $memberIds members.id
     * @param list<ImportedAttachment> $attachments
     */
    public function __construct(
        public readonly string $importSource,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly \DateTimeImmutable $sentAt,
        public readonly string $senderAddress,
        public readonly ?string $senderName = null,
        public readonly ?string $audienceName = null,
        public readonly array $recipients = [],
        public readonly array $memberIds = [],
        public readonly array $attachments = [],
        public readonly ?int $importedBy = null
    ) {
        if ($importSource === '' || strlen($importSource) > 400) {
            throw new \InvalidArgumentException('An import source is 1 to 400 bytes.');
        }
        if (trim($subject) === '' || mb_strlen($subject) > 255) {
            throw new \InvalidArgumentException('A subject is 1 to 255 characters.');
        }
        if (filter_var($senderAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The sender address is not an address.');
        }
    }
}
