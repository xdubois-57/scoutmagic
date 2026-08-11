<?php

declare(strict_types=1);

namespace Core\Import;

class FeeCategoryRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Every imported fee category, ordered by label — Modules\Registration\
     * Controller\RegistrationRequestController's own "Tarif" dropdown
     * (the module suggests a category via Core\Member\FeeEstimationService
     * but never applies one automatically; a chief always picks from this
     * real, Desk-imported list).
     *
     * @return array<int, array{id: int, desk_code: string, label: string}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM fee_categories ORDER BY label');
        if ($stmt === false) {
            return [];
        }

        return array_map(static fn(array $row) => [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'label' => (string) $row['label'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return array{id: int, desk_code: string, label: string}|null
     */
    public function findByDeskCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fee_categories WHERE desk_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'label' => (string) $row['label'],
        ];
    }

    public function create(string $deskCode, string $label): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fee_categories (desk_code, label) VALUES (?, ?)'
        );
        $stmt->execute([$deskCode, $label]);
        return (int) $this->pdo->lastInsertId();
    }
}
