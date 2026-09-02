<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

final class FormResponse
{
    public function __construct(
        public readonly int $id,
        public readonly int $formId,
        public readonly ?int $userAccountId,
        public readonly ?int $memberYearId,
        public readonly string $contactEmail,
        public readonly ?string $structuredCommunication,
        public readonly ?int $receivableId,
        public readonly string $submittedAt,
        public readonly ?string $updatedAt,
        /**
         * The ticket's reference, CANONICAL — ten characters, no dash.
         * Null on a response to a form that issues none, and on one made
         * before the flag was raised and not yet backfilled.
         * Service\TicketService::format() puts the dashes back.
         */
        public readonly ?string $ticketReference = null,
        /**
         * When the holder came in, or null. Global, not a counter: a
         * response holding four seats is used or not used, once.
         */
        public readonly ?string $ticketUsedAt = null
    ) {
    }

    public function hasTicket(): bool
    {
        return $this->ticketReference !== null && $this->ticketReference !== '';
    }

    public function isTicketUsed(): bool
    {
        return $this->ticketUsedAt !== null;
    }
}
