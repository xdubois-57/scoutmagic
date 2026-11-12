<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

final class Email
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_TEST = 'test';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';

    /** @var string[] */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_TEST, self::STATUS_SENDING, self::STATUS_SENT];

    /**
     * Identifies which of the four possible lists produced this email's
     * recipients — see schema.sql's own comment on mass_mail_emails for
     * why this is two nullable FK columns rather than one polymorphic id.
     */
    public const LIST_TYPE_DEFAULT_SECTION = 'default_section';
    public const LIST_TYPE_DEFAULT_ACTIVE_MEMBERS = 'default_active_members';
    public const LIST_TYPE_DEFAULT_CHIEFS = 'default_chiefs';
    public const LIST_TYPE_CUSTOM = 'custom';
    /**
     * A predefined, non-editable list contributed by another module's
     * Api\ExternalMailingListProvider (ARCHITECTURE.md §7.5) — list_id/
     * list_section_id both stay NULL for it, same as the other two
     * "always exactly one, nothing to key against" types above.
     */
    public const LIST_TYPE_EXTERNAL = 'external';
    /**
     * A chief-uploaded Excel audience (mass_mail_audiences): one email
     * per file row, each with its own merge variables. $audienceId is
     * set for this type and only this type; $scoutYearIds stays empty
     * (the file, not a scout year, defines who receives it).
     */
    public const LIST_TYPE_MAIL_MERGE = 'mail_merge';

    /**
     * The « Anciens » list — every member who was active in a past scout
     * year and is not active in this one. Computed on the fly like every
     * other default list: there is no table, no junction and no scheduled
     * task, because a departure is not an event the site receives — it is
     * the ABSENCE of a row in the next Desk import, and there is no hook
     * to hang « add to the former members » on. See Service\
     * MailingListService::getDefaultLists().
     */
    public const LIST_TYPE_DEFAULT_FORMER_MEMBERS = 'default_former_members';

    /**
     * An email sent from a former site and imported by the data_migration
     * module (chantier « Migration de données », decision D11). No list
     * behind it: its audience survives only as {@see $originalAudienceName},
     * and it is read-only for ever — {@see isImported()}.
     */
    public const LIST_TYPE_IMPORTED = 'imported';

    /**
     * @param int[] $scoutYearIds One or more scout years this email targets — module addendum
     *                            (e.g. "Montages dias" retrospectives spanning several promotions).
     *                            Lists resolved for each are merged and deduplicated by address.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly int $sectionId,
        public readonly string $listType,
        public readonly ?int $listId,
        public readonly ?int $listSectionId,
        public readonly ?int $audienceId,
        public readonly array $scoutYearIds,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $sentAt,
        public readonly ?int $createdBy,
        // When this email was imported from a former site, null for one
        // written here. The read-only lock of D11: see isImported().
        public readonly ?string $importedAt = null,
        // The former site's name for its mailing list, informative only.
        public readonly ?string $originalAudienceName = null
    ) {
    }

    /**
     * An email imported from a former site: nothing may edit, delete or
     * resend it, nor touch its attachments (Service\MassMailService). The
     * lock is this module's own column, so it holds whether or not the
     * data_migration module that wrote it is still enabled.
     */
    public function isImported(): bool
    {
        return $this->importedAt !== null;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT && !$this->isImported();
    }
}
