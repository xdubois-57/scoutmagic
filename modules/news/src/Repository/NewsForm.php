<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

final class NewsForm
{
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_IDENTIFIED = 'identified';

    public const RESPONSE_LIMIT_UNLIMITED = 'unlimited';
    public const RESPONSE_LIMIT_ONE_PER_ACCOUNT = 'one_per_account';
    public const RESPONSE_LIMIT_ONE_PER_MEMBER = 'one_per_member';

    public function __construct(
        public readonly int $id,
        public readonly int $newsArticleId,
        public readonly string $access,
        public readonly string $responseLimit,
        public readonly ?string $opensAt,
        public readonly ?string $closesAt,
        public readonly bool $isForceClosed,
        public readonly string $responseRoleMin,
        public readonly bool $dailyDigestEnabled,
        /**
         * The form delivers a ticket. Independent of price: an event can
         * be ticketed and free — see schema.sql.
         */
        public readonly bool $issuesTicket,
        /** `Y-m-d`, the EVENT's date — never closes_at, which closes the registrations. */
        public readonly ?string $eventDate,
        public readonly ?string $eventLocation,
        public readonly ?string $lastDigestSentAt,
        public readonly ?int $financeAccountId,
        public readonly string $createdAt
    ) {
    }

    /**
     * Whether there is an event to describe on the ticket, in the e-mail
     * and at the door.
     *
     * **The date decides, and the location rides along.** A date with no
     * address still tells a reader when to be somewhere and is all the
     * ICS needs; an address with no date places nothing and cannot
     * produce a calendar entry at all. So a form carrying only a
     * location is in the degraded mode — the ticket names the article,
     * and nothing more.
     */
    public function hasEventDetails(): bool
    {
        return $this->eventDate !== null && $this->eventDate !== '';
    }

    /**
     * Effective open/closed state — always computed live, never stored
     * (module spec: no scheduled task "closes" a form).
     */
    public function isOpen(?\DateTimeImmutable $now = null): bool
    {
        if ($this->isForceClosed) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $today = $now->format('Y-m-d');

        if ($this->opensAt !== null && $today < $this->opensAt) {
            return false;
        }
        if ($this->closesAt !== null && $today > $this->closesAt) {
            return false;
        }

        return true;
    }
}
