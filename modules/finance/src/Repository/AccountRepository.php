<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Security\EncryptionService;

class AccountRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return Account[]
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_accounts ORDER BY name ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?Account
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Finds the account whose IBAN blind index matches — used at import
     * time to verify a bank statement's source IBAN against the target
     * account without ever decrypting on a WHERE clause (module spec
     * follow-up "itération 3").
     */
    public function findByIbanBlindIndex(string $blindIndex): ?Account
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_accounts WHERE iban_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        string $name,
        string $accountType,
        ?int $sectionId,
        ?string $iban,
        ?string $holderName,
        string $roleMinView
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_accounts (name, account_type, section_id, iban, iban_blind_index, holder_name, role_min_view)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $accountType,
            $sectionId,
            $iban !== null ? $this->encryption->encrypt($iban) : null,
            $iban !== null ? $this->encryption->blindIndex($iban) : null,
            $holderName !== null ? $this->encryption->encrypt($holderName) : null,
            $roleMinView,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $accountType,
        ?int $sectionId,
        ?string $iban,
        ?string $holderName,
        string $roleMinView
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE finance_accounts
             SET name = ?, account_type = ?, section_id = ?, iban = ?, iban_blind_index = ?, holder_name = ?, role_min_view = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $accountType,
            $sectionId,
            $iban !== null ? $this->encryption->encrypt($iban) : null,
            $iban !== null ? $this->encryption->blindIndex($iban) : null,
            $holderName !== null ? $this->encryption->encrypt($holderName) : null,
            $roleMinView,
            $id,
        ]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE finance_accounts SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Account
    {
        return new Account(
            id: (int) $row['id'],
            name: (string) $row['name'],
            accountType: (string) $row['account_type'],
            sectionId: $row['section_id'] !== null ? (int) $row['section_id'] : null,
            iban: $row['iban'] !== null ? $this->encryption->decrypt($row['iban']) : null,
            holderName: $row['holder_name'] !== null ? $this->encryption->decrypt($row['holder_name']) : null,
            roleMinView: (string) $row['role_min_view'],
            status: (string) $row['status']
        );
    }
}
