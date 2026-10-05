<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

/**
 * One e-mail the site sent the renter about a booking, as the « Courrier »
 * page shows it (#720, `rental_booking_sent_emails`): decrypted, with the
 * tracking link still masked.
 */
final class SentEmail
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * A failed e-mail one « Renvoyer » has claimed and is sending now
     * (`RentalSentEmailRepository::claimForRetry()`). Only ever for the
     * length of a request; a claim older than this is a request that died
     * before recording, and counts as failed again.
     */
    public const STATUS_SENDING = 'sending';
    public const STALE_CLAIM_MINUTES = 15;

    /**
     * Where the tracking link stood in the stored text: a credential is
     * never kept, and « Renvoyer » puts the booking's current link back.
     * Chosen to appear in no e-mail anybody writes.
     */
    public const MASKED_LINK = '[[lien-de-suivi]]';

    /** What the page shows in its place. */
    public const MASKED_LINK_LABEL = '[lien de suivi masqué]';

    /**
     * @param list<int> $documentIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly string $kind,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly string $bodyHtml,
        public readonly array $documentIds,
        public readonly string $messageId,
        public readonly string $status,
        public readonly \DateTimeImmutable $sentAt
    ) {
    }

    /**
     * Did not go out — including a « Renvoyer » that claimed it and never
     * came back to say how it ended.
     */
    public function failed(?\DateTimeImmutable $now = null): bool
    {
        if ($this->status === self::STATUS_FAILED) {
            return true;
        }

        return $this->status === self::STATUS_SENDING
            && $this->sentAt < ($now ?? new \DateTimeImmutable())->modify('-' . self::STALE_CLAIM_MINUTES . ' minutes');
    }

    /** Being sent again right now, by another request. */
    public function beingResent(?\DateTimeImmutable $now = null): bool
    {
        return $this->status === self::STATUS_SENDING && !$this->failed($now);
    }

    public function carriesTheTrackingLink(): bool
    {
        return str_contains($this->bodyText, self::MASKED_LINK) || str_contains($this->bodyHtml, self::MASKED_LINK);
    }

    /** The text as the page shows it: the link said to be masked. */
    public function displayText(): string
    {
        return str_replace(self::MASKED_LINK, self::MASKED_LINK_LABEL, $this->bodyText);
    }
}
