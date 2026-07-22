<?php

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
        public readonly array $scoutYearIds,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $sentAt,
        public readonly ?int $createdBy
    ) {
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
