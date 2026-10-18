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

    /** What {@see self::availability()} answers. */
    public const AVAILABILITY_OPEN = 'open';
    public const AVAILABILITY_FORCE_CLOSED = 'force_closed';
    public const AVAILABILITY_NOT_YET_OPEN = 'not_yet_open';
    public const AVAILABILITY_EXPIRED = 'expired';

    public function __construct(
        public readonly int $id,
        public readonly int $newsArticleId,
        public readonly string $access,
        public readonly string $responseLimit,
        public readonly ?string $opensAt,
        public readonly ?string $closesAt,
        public readonly bool $isForceClosed,
        public readonly string $responseRoleMin,
        /**
         * Where the daily digest of new responses goes — null, or the
         * empty string, meaning nowhere (issue #738). Never derived from
         * the article's author: it is seeded from their address when the
         * form is created and is its own setting from then on.
         */
        public readonly ?string $digestEmail,
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
        public readonly string $createdAt,
        /** Where this form's payments are booked in Finances (issue #838). */
        public readonly ?int $financeCategoryId = null
    ) {
    }

    /**
     * Whether a priced answer raises a receivable: an account to receive
     * the money AND a category to book it under (issue #838). A form
     * saved before categories existed has the first only, and asks for
     * no money until its author picks one.
     */
    public function hasPaymentDestination(): bool
    {
        return $this->financeAccountId !== null && $this->financeCategoryId !== null;
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
     * Why a form takes, or does not take, an answer right now — always
     * computed live from ONE reference date, never stored (module spec: no
     * scheduled task "closes" a form).
     *
     * `isOpen()` used to be the only answer, and a view that wanted a
     * reason had to guess it from the boolean: it read "not open" as "past
     * its closing date" and announced a closing date still months away as
     * having passed (issue #925). The order is the one `isOpen()` always
     * had, so the booleans do not change: a manual closure wins over the
     * dates, then a form not yet open, then one past its closing date. Both
     * bounds are inclusive on their own day.
     *
     * @return self::AVAILABILITY_*
     */
    public function availability(?\DateTimeImmutable $now = null): string
    {
        if ($this->isForceClosed) {
            return self::AVAILABILITY_FORCE_CLOSED;
        }

        $now ??= new \DateTimeImmutable();
        $today = $now->format('Y-m-d');

        if ($this->opensAt !== null && $today < $this->opensAt) {
            return self::AVAILABILITY_NOT_YET_OPEN;
        }
        if ($this->closesAt !== null && $today > $this->closesAt) {
            return self::AVAILABILITY_EXPIRED;
        }

        return self::AVAILABILITY_OPEN;
    }

    /**
     * Effective open/closed state, for the callers that only need a yes or
     * a no — the refusal of a submission among them. {@see self::availability()}
     * says why.
     */
    public function isOpen(?\DateTimeImmutable $now = null): bool
    {
        return $this->availability($now) === self::AVAILABILITY_OPEN;
    }
}
