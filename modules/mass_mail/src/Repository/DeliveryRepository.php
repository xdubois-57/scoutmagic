<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;

/**
 * mass_mail_deliveries — the messages of a mailing, one address each
 * (issue #999), frozen when the email leaves test for 'sending'
 * (Service\MassMailService::startSending()) — and mass_mail_delivery_targets,
 * which says which members each one concerns.
 *
 * The address is personal data: only this repository encrypts and decrypts
 * it (SECURITY.md §5). Beside it, the blind index of the lowercased address
 * — the only form under which an address may be compared in SQL, since a
 * ciphertext neither sorts nor matches.
 */
class DeliveryRepository
{
    private const ENCRYPTION_CONTEXT = 'mass_mail_deliveries.email_address';
    private const BLIND_INDEX_PURPOSE = 'mass_mail_deliveries.email';
    private const UNSUBSCRIBE_TOKEN_PURPOSE = 'mass_mail_unsubscribe_token';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Persists what it is told — whether the address may be written to is
     * the freeze's decision (Service\MassMailService), not this method's.
     * A message always has an address; a member left without one is a
     * target with no message, never a message without an address.
     */
    public function create(
        int $emailId,
        string $emailAddress,
        string $status = Delivery::STATUS_PENDING,
        ?string $errorMessage = null,
        ?int $memberEmailId = null,
        ?int $audienceRowId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_deliveries (email_id, audience_row_id, email_address_encrypted, email_blind_index, '
                . 'member_email_id, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailId,
            $audienceRowId,
            $this->encryption->encrypt($emailAddress, self::ENCRYPTION_CONTEXT),
            $this->blindIndex($emailAddress),
            $memberEmailId,
            $status,
            $errorMessage,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * One message of an email imported from a former site, written in the
     * state that site recorded — never 'pending', which Task\SendBatchHandler
     * would pick up and really send. `$sentAt` is the historical moment,
     * kept only for an accepted message, the one status the tracking page
     * dates. No attempt is counted: none was made here.
     *
     * @throws \InvalidArgumentException for any status but sent/error/unknown
     */
    public function createImported(
        int $emailId,
        string $emailAddress,
        string $status,
        ?string $sentAt,
        ?string $errorMessage
    ): int {
        if (!in_array($status, [Delivery::STATUS_SENT, Delivery::STATUS_ERROR, Delivery::STATUS_UNKNOWN], true)) {
            throw new \InvalidArgumentException('An imported message is sent, in error or unknown — never pending.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_deliveries (email_id, email_address_encrypted, email_blind_index, status, '
                . 'error_message, sent_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailId,
            $this->encryption->encrypt($emailAddress, self::ENCRYPTION_CONTEXT),
            $this->blindIndex($emailAddress),
            $status,
            $status === Delivery::STATUS_ERROR ? $errorMessage : null,
            $status === Delivery::STATUS_SENT ? $sentAt : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ties one message to the targets whose address it carries — every
     * target that has it, so a family address shared by two targeted
     * siblings concerns both.
     *
     * @param int[] $targetIds
     */
    public function linkTargets(int $deliveryId, array $targetIds): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_delivery_targets (delivery_id, target_id) VALUES (?, ?)');
        foreach (array_values(array_unique(array_map('intval', $targetIds))) as $targetId) {
            $stmt->execute([$deliveryId, $targetId]);
        }
    }

    /**
     * The blind index this table stores for an address — exposed so a test,
     * or a future lookup, asks for it by the address rather than recomputing
     * the purpose string from memory (the trap of issue #629).
     */
    public function blindIndex(string $emailAddress): string
    {
        return $this->encryption->blindIndex(mb_strtolower(trim($emailAddress)), self::BLIND_INDEX_PURPOSE);
    }

    /**
     * The one-click unsubscribe token of a message — the same on every send
     * of it (issue #1514): « Renvoyer » used to mint a fresh one and
     * overwrite the stored hash, so the link in the first copy stopped
     * working while the help promised it never expires.
     *
     * **Derived, never stored**, the way Modules\Finance\Service\
     * ReceivableQrTokenService derives its QR token: an HMAC of the
     * message's id and its email's id under the installation's blind-index
     * key, domain-separated by its own purpose. 256 bits that cannot be
     * computed without that key, so there is no raw token to keep — a
     * database read yields nothing that opens a link. Both ids are part of
     * the input so that a message id ever reused for another email does
     * not open with an old link.
     */
    public function unsubscribeTokenFor(Delivery $delivery): string
    {
        return $this->derivedUnsubscribeToken($delivery->id, $delivery->emailId);
    }

    /**
     * The one-click unsubscribe endpoint's authentication — never trusts a
     * bare message id in the URL, always re-verifies the token. Null on any
     * mismatch (unknown id, wrong token): Controller\UnsubscribeController
     * answers the same page either way, with nothing to enumerate.
     *
     * Two tokens open a message, both compared in constant time: the
     * derived one (unsubscribeTokenFor()), and — for a message sent before
     * issue #1514 — the random token whose SHA-256 its last send stored in
     * `unsubscribe_token_hash`. Nothing writes that column any more, so the
     * link of a message's last send before the fix keeps working for good,
     * beside the derived link of every send after it.
     */
    public function verifyUnsubscribeToken(int $id, string $rawToken): ?Delivery
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_deliveries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $derived = $this->derivedUnsubscribeToken((int) $row['id'], (int) $row['email_id']);
        $storedHash = $row['unsubscribe_token_hash'] !== null ? (string) $row['unsubscribe_token_hash'] : null;
        // Both compared, whichever matches: no short-circuit that would
        // tell the two kinds apart by timing.
        $matchesDerived = CapabilityToken::equalsConstantTime($derived, $rawToken);
        $matchesLegacy = CapabilityToken::verifyAgainstHash($rawToken, $storedHash);
        if (!$matchesDerived && !$matchesLegacy) {
            return null;
        }

        return $this->hydrate($row);
    }

    private function derivedUnsubscribeToken(int $deliveryId, int $emailId): string
    {
        return $this->encryption->blindIndex($deliveryId . ':' . $emailId, self::UNSUBSCRIBE_TOKEN_PURPOSE);
    }

    public function findById(int $id): ?Delivery
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_deliveries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every message of one email, for the tracking page — filtered in the
     * browser over this already-bounded set (a mailing's own size).
     *
     * @return Delivery[]
     */
    public function findByEmailId(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_deliveries WHERE email_id = ? ORDER BY id ASC');
        $stmt->execute([$emailId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Which targets each message of an email concerns.
     *
     * @return array<int, int[]> target ids keyed by delivery id; a message
     *         concerning nobody (a contact's) is absent
     */
    public function findTargetIdsByEmail(int $emailId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dt.delivery_id, dt.target_id
             FROM mass_mail_delivery_targets dt
             JOIN mass_mail_deliveries d ON d.id = dt.delivery_id
             WHERE d.email_id = ?
             ORDER BY dt.delivery_id ASC, dt.target_id ASC'
        );
        $stmt->execute([$emailId]);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['delivery_id']][] = (int) $row['target_id'];
        }

        return $map;
    }

    /**
     * The targets one message concerns.
     *
     * @return int[]
     */
    public function findTargetIdsForDelivery(int $deliveryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT target_id FROM mass_mail_delivery_targets WHERE delivery_id = ? ORDER BY target_id ASC'
        );
        $stmt->execute([$deliveryId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The oldest 'pending' messages across every email, site-wide — the
     * sending cadence is the relay's, never per email, so this deliberately
     * never filters by email_id. Used by Task\SendBatchHandler.
     *
     * @return Delivery[]
     */
    public function findOldestPending(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM mass_mail_deliveries WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT "
                . max(0, $limit)
        );
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function recordSendSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mass_mail_deliveries SET status = 'sent', sent_at = CURRENT_TIMESTAMP, error_message = NULL, "
                . 'attempts = attempts + 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * $errorMessage must never contain the address or any other personal
     * data — Task\SendBatchHandler only ever passes a fixed French reason.
     */
    public function recordSendFailure(int $id, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mass_mail_deliveries SET status = 'error', error_message = ?, attempts = attempts + 1 WHERE id = ?"
        );
        $stmt->execute([$errorMessage, $id]);
    }

    /**
     * « Renvoyer » (for an 'error' or an already 'sent' message) — back to
     * 'pending' so the next batch picks it up again. sent_at is left as the
     * record of the previous send; the tracking page only shows it while
     * the status is 'sent'.
     */
    public function resend(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mass_mail_deliveries SET status = 'pending', error_message = NULL, attempts = attempts + 1 "
                . 'WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Counts MESSAGES — one per address — never people. `unknown` is only
     * ever non-zero for an email imported from a former site.
     *
     * @return array{pending: int, sent: int, error: int, unknown: int, total: int}
     */
    public function countGroupedByStatus(int $emailId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS c FROM mass_mail_deliveries WHERE email_id = ? GROUP BY status'
        );
        $stmt->execute([$emailId]);

        $counts = ['pending' => 0, 'sent' => 0, 'error' => 0, 'unknown' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return [
            'pending' => $counts['pending'],
            'sent' => $counts['sent'],
            'error' => $counts['error'],
            'unknown' => $counts['unknown'],
            'total' => $counts['pending'] + $counts['sent'] + $counts['error'] + $counts['unknown'],
        ];
    }

    /**
     * Whether any message of any email waits to leave — the one question
     * Task\SendBatchHandler::bootstrap() asks on every request before it
     * asks the scheduler anything (issue #1443). Served by
     * `idx_mmd_status_id`, and answered « non » on almost every page view.
     */
    public function hasAnyPending(): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM mass_mail_deliveries WHERE status = 'pending' LIMIT 1");
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** How many messages of every email together wait to leave — what the chain still has to drain. */
    public function countAllPending(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM mass_mail_deliveries WHERE status = 'pending'");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function hasPending(int $emailId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM mass_mail_deliveries WHERE email_id = ? AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([$emailId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Delivery
    {
        return new Delivery(
            id: (int) $row['id'],
            emailId: (int) $row['email_id'],
            audienceRowId: $row['audience_row_id'] !== null ? (int) $row['audience_row_id'] : null,
            emailAddress: $this->encryption->decrypt($row['email_address_encrypted'], self::ENCRYPTION_CONTEXT),
            memberEmailId: $row['member_email_id'] !== null ? (int) $row['member_email_id'] : null,
            status: (string) $row['status'],
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            sentAt: $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            attempts: (int) $row['attempts'],
            createdAt: (string) $row['created_at']
        );
    }
}
