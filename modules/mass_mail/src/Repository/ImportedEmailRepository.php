<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * The columns of mass_mail_emails that only an email imported from a former
 * site carries (chantier « Migration de données », decisions D6 and D11):
 * its provenance, the sender as that site recorded it, and the name of the
 * list it went to.
 *
 * Its own repository rather than more of Repository\EmailRepository because
 * the sender is personal data, encrypted at rest (SECURITY.md §5), and
 * EmailRepository holds no personal data and no EncryptionService. Only this
 * class encrypts or decrypts those two columns.
 */
class ImportedEmailRepository
{
    private const SENDER_ADDRESS_CONTEXT = 'mass_mail_emails.original_sender_address';
    private const SENDER_NAME_CONTEXT = 'mass_mail_emails.original_sender_name';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Writes an imported email, already 'sent', with its historical date as
     * its creation, update and sending date — the list sorts by creation,
     * and an email of 2021 belongs among those of 2021. No scout year, no
     * list, no audience: list_type 'imported' says why.
     *
     * Persists what it is told: the body is sanitized and the section chosen
     * by Service\EmailHistoryImportService.
     */
    public function insert(
        string $subject,
        string $bodyHtml,
        int $sectionId,
        string $sentAt,
        string $importedAt,
        string $importSource,
        string $senderAddress,
        ?string $senderName,
        ?string $audienceName
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_emails
                (subject, body_html, section_id, list_type, status, created_at, updated_at, sent_at, created_by,
                 imported_at, import_source, original_sender_address_encrypted, original_sender_name_encrypted,
                 original_audience_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $subject);
        $stmt->bindValue(2, $bodyHtml);
        $stmt->bindValue(3, $sectionId, \PDO::PARAM_INT);
        $stmt->bindValue(4, Email::LIST_TYPE_IMPORTED);
        $stmt->bindValue(5, Email::STATUS_SENT);
        $stmt->bindValue(6, $sentAt);
        $stmt->bindValue(7, $sentAt);
        $stmt->bindValue(8, $sentAt);
        $stmt->bindValue(9, $importedAt);
        $stmt->bindValue(10, $importSource, \PDO::PARAM_LOB);
        $stmt->bindValue(11, $this->encryption->encrypt($senderAddress, self::SENDER_ADDRESS_CONTEXT), \PDO::PARAM_LOB);
        $stmt->bindValue(
            12,
            $senderName !== null ? $this->encryption->encrypt($senderName, self::SENDER_NAME_CONTEXT) : null,
            $senderName !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL
        );
        $stmt->bindValue(13, $audienceName, $audienceName !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The email already imported under this provenance, compared byte for
     * byte (VARBINARY) — the module's own heuristic against a duplicate,
     * which still holds when the data_migration correspondence row is gone.
     */
    public function findIdByImportSource(string $importSource): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mass_mail_emails WHERE import_source = ? ORDER BY id ASC LIMIT 1');
        $stmt->bindValue(1, $importSource, \PDO::PARAM_LOB);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * The sender each imported email had on the former site, decrypted, for
     * the screens that show it as is (D6). An email written here is absent.
     *
     * @param int[] $emailIds
     * @return array<int, array{address: string, name: ?string}> keyed by email id
     */
    public function findOriginalSenders(array $emailIds): array
    {
        $emailIds = array_values(array_unique(array_map('intval', $emailIds)));
        if ($emailIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, original_sender_address_encrypted, original_sender_name_encrypted
             FROM mass_mail_emails
             WHERE id IN ({$placeholders}) AND imported_at IS NOT NULL
               AND original_sender_address_encrypted IS NOT NULL"
        );
        $stmt->execute($emailIds);

        $senders = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $senders[(int) $row['id']] = [
                'address' => $this->encryption->decrypt(
                    (string) $row['original_sender_address_encrypted'],
                    self::SENDER_ADDRESS_CONTEXT
                ),
                'name' => $row['original_sender_name_encrypted'] !== null
                    ? $this->encryption->decrypt(
                        (string) $row['original_sender_name_encrypted'],
                        self::SENDER_NAME_CONTEXT,
                    )
                    : null,
            ];
        }

        return $senders;
    }

    /**
     * Every section with the address it sends from, active or not, hidden
     * or not — an email of 2019 may come from a section that has emptied
     * since, and it is still that section's (D6). A section is never
     * deleted (ARCHITECTURE.md §8.8), only deactivated.
     *
     * @return list<array{id: int, desk_code: string, email: ?string}>
     */
    public function findSectionAddresses(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, desk_code, email FROM sections ORDER BY id ASC');
        if ($stmt === false || !$stmt->execute()) {
            return [];
        }

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'desk_code' => (string) $row['desk_code'],
                'email' => $row['email'] !== null ? (string) $row['email'] : null,
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }
}
