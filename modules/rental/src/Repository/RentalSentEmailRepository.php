<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\Rental\Mail\SentEmail;

/**
 * What the site sent the renter about each booking (#720,
 * `rental_booking_sent_emails`). Recipient, subject and both bodies are
 * encrypted, each under its own context; the tracking link reaches this
 * class already masked (`RentalBookingMailService::deliver()`).
 */
class RentalSentEmailRepository
{
    private const CTX_RECIPIENT = 'rental_booking_sent_emails.recipient';
    private const CTX_SUBJECT = 'rental_booking_sent_emails.subject';
    private const CTX_TEXT = 'rental_booking_sent_emails.body_text';
    private const CTX_HTML = 'rental_booking_sent_emails.body_html';

    public function __construct(private \PDO $pdo, private EncryptionService $encryption)
    {
    }

    /**
     * @param list<int> $documentIds
     * @return int the new row's id
     */
    public function record(
        int $bookingId,
        string $kind,
        string $recipient,
        string $subject,
        string $bodyText,
        string $bodyHtml,
        array $documentIds,
        string $messageId,
        string $status,
        \DateTimeImmutable $sentAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_booking_sent_emails
                (booking_id, kind, recipient_encrypted, subject_encrypted, body_text_encrypted,
                 body_html_encrypted, document_ids, message_id, status, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $bookingId,
            $kind,
            $this->encryption->encrypt($recipient, self::CTX_RECIPIENT),
            $this->encryption->encrypt($subject, self::CTX_SUBJECT),
            $this->encryption->encrypt($bodyText, self::CTX_TEXT),
            $this->encryption->encrypt($bodyHtml, self::CTX_HTML),
            implode(',', array_map('intval', $documentIds)),
            $messageId,
            $status,
            $sentAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Every e-mail sent about this booking, oldest first.
     *
     * @return list<SentEmail>
     */
    public function findForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rental_booking_sent_emails WHERE booking_id = ? ORDER BY sent_at ASC, id ASC'
        );
        $stmt->execute([$bookingId]);

        return array_map(fn(array $row): SentEmail => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?SentEmail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_booking_sent_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The Message-IDs of what the site sent about this booking — what tells
     * a copy of one of them, found in the mailbox, from a message somebody
     * wrote by hand.
     *
     * @return list<string>
     */
    public function messageIdsForBooking(int $bookingId): array
    {
        $stmt = $this->pdo->prepare('SELECT message_id FROM rental_booking_sent_emails WHERE booking_id = ?');
        $stmt->execute([$bookingId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SentEmail
    {
        $documentIds = (string) $row['document_ids'] === ''
            ? []
            : array_map('intval', explode(',', (string) $row['document_ids']));

        return new SentEmail(
            (int) $row['id'],
            (int) $row['booking_id'],
            (string) $row['kind'],
            $this->encryption->decrypt((string) $row['recipient_encrypted'], self::CTX_RECIPIENT),
            $this->encryption->decrypt((string) $row['subject_encrypted'], self::CTX_SUBJECT),
            $this->encryption->decrypt((string) $row['body_text_encrypted'], self::CTX_TEXT),
            $this->encryption->decrypt((string) $row['body_html_encrypted'], self::CTX_HTML),
            $documentIds,
            (string) $row['message_id'],
            (string) $row['status'],
            DateInput::requireFromStorage((string) $row['sent_at'], 'rental_booking_sent_emails.sent_at')
        );
    }
}
