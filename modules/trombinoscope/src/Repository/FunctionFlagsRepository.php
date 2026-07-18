<?php

declare(strict_types=1);

namespace Modules\Trombinoscope\Repository;

class FunctionFlagsRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Lead flag for every function with role 'chief' or 'admin', keyed by
     * function id. A function with no row in trombinoscope_function_flags
     * defaults to false — the table only needs to record the lead function(s).
     *
     * @return array<int, bool>
     */
    public function getLeadFlags(): array
    {
        $stmt = $this->pdo->query(
            "SELECT f.id, COALESCE(tff.is_lead, 0) AS is_lead
             FROM functions f
             LEFT JOIN trombinoscope_function_flags tff ON tff.function_id = f.id
             WHERE f.role IN ('chief', 'admin')"
        );

        $flags = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $flags[(int) $row['id']] = (bool) $row['is_lead'];
            }
        }

        return $flags;
    }

    public function setLead(int $functionId, bool $lead): void
    {
        $stmt = $this->pdo->prepare('SELECT function_id FROM trombinoscope_function_flags WHERE function_id = ?');
        $stmt->execute([$functionId]);

        if ($stmt->fetchColumn() !== false) {
            $update = $this->pdo->prepare(
                'UPDATE trombinoscope_function_flags SET is_lead = ? WHERE function_id = ?'
            );
            $update->execute([$lead ? 1 : 0, $functionId]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO trombinoscope_function_flags (function_id, is_lead) VALUES (?, ?)'
        );
        $insert->execute([$functionId, $lead ? 1 : 0]);
    }
}
