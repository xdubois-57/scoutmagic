<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * mass_mail_recipients — the frozen, per-member snapshot of a mailing
 * list at the moment an email left draft/test for 'sending' (Service\
 * MassMailService::startSending()). email_address_encrypted is personal
 * data → only this repository ever decrypts it (module spec: "déchiffrée
 * uniquement dans le Repository"); no blind index, since nothing ever
 * searches this table by address.
 */
class RecipientRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Whether $emailAddress is valid decides the caller's $status/
     * $errorMessage before calling this — this method only ever persists
     * what it's told (no validation here, see Service\MassMailService::
     * startSending()).
     */
    public function create(int $emailId, int $memberId, int $scoutYearId, ?string $emailAddress, string $status, ?string $errorMessage): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_recipients (email_id, member_id, scout_year_id, email_address_encrypted, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailId,
            $memberId,
            $scoutYearId,
            $emailAddress !== null ? $this->encryption->encrypt($emailAddress) : null,
            $status,
            $errorMessage,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Recipient
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_recipients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every recipient for one email, for the tracking page — search/status
     * filtering happens client-side (JS) over this already-bounded set (a
     * mailing list's own size), not a second paginated endpoint.
     *
     * @return Recipient[]
     */
    public function findByEmailId(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_recipients WHERE email_id = ? ORDER BY id ASC');
        $stmt->execute([$emailId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The oldest 'pending' recipients across every email, site-wide — the
     * sending speed (module spec's batch_size setting) is global, never
     * per-email, so this deliberately never filters by email_id. Used by
     * Task\SendBatchHandler.
     *
     * @return Recipient[]
     */
    public function findOldestPending(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM mass_mail_recipients WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT " . max(0, $limit)
        );
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function recordSendSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mass_mail_recipients SET status = 'sent', sent_at = CURRENT_TIMESTAMP, error_message = NULL, attempts = attempts + 1 WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    /**
     * $errorMessage must never contain the recipient's address or any
     * other personal data (module spec, enforced by the caller — Task\
     * SendBatchHandler only ever passes a fixed technical reason).
     */
    public function recordSendFailure(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mass_mail_recipients SET status = 'error', error_message = ?, attempts = attempts + 1 WHERE id = ?"
        );
        $stmt->execute([$errorMessage, $id]);
    }

    /**
     * The tracking page's "Renvoyer" action (available for both 'error'
     * and already-'sent' recipients) — back to 'pending' so Task\
     * SendBatchHandler's next batch picks it up again; sent_at is left
     * untouched as a historical record of the previous send, since the
     * tracking UI only ever displays it while status is 'sent'.
     */
    public function resend(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mass_mail_recipients SET status = 'pending', error_message = NULL, attempts = attempts + 1 WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    /**
     * @return array{pending: int, sent: int, error: int, total: int}
     */
    public function countGroupedByStatus(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS c FROM mass_mail_recipients WHERE email_id = ? GROUP BY status');
        $stmt->execute([$emailId]);

        $counts = ['pending' => 0, 'sent' => 0, 'error' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return [
            'pending' => $counts['pending'],
            'sent' => $counts['sent'],
            'error' => $counts['error'],
            'total' => $counts['pending'] + $counts['sent'] + $counts['error'],
        ];
    }

    public function hasPending(int $emailId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM mass_mail_recipients WHERE email_id = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$emailId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Backs Api\MassMailQueryInterface::getRecentEmailsForMember() — the
     * espace des animés member page's optional "Emails reçus" section
     * (ARCHITECTURE.md §7.5). Only ever 'sent' recipients — a still-
     * pending or errored row was never actually delivered to this member.
     *
     * @return array<int, array{subject: string, sent_at: string, section_name: string}>
     */
    public function findRecentSentForMember(int $memberId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.subject AS subject, r.sent_at AS sent_at, s.name AS section_name
             FROM mass_mail_recipients r
             JOIN mass_mail_emails e ON e.id = r.email_id
             JOIN sections s ON s.id = e.section_id
             WHERE r.member_id = ? AND r.status = 'sent'
             ORDER BY r.sent_at DESC
             LIMIT " . max(0, $limit)
        );
        $stmt->execute([$memberId]);

        return array_map(fn(array $row) => [
            'subject' => (string) $row['subject'],
            'sent_at' => (string) $row['sent_at'],
            'section_name' => (string) $row['section_name'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Recipient
    {
        return new Recipient(
            id: (int) $row['id'],
            emailId: (int) $row['email_id'],
            memberId: (int) $row['member_id'],
            scoutYearId: (int) $row['scout_year_id'],
            emailAddress: $row['email_address_encrypted'] !== null ? $this->encryption->decrypt($row['email_address_encrypted']) : null,
            status: (string) $row['status'],
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            sentAt: $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            attempts: (int) $row['attempts'],
            createdAt: (string) $row['created_at']
        );
    }
}
