<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class AgeBranchRepository
{
    /**
     * Shipped default federation logo per canonical sort order (member page
     * branch card — Core\Http\Controller\MemberController), used when no
     * admin has uploaded one for that branch yet. Files live at
     * public/assets/img/branches/ and are served directly (not through
     * /files/{id} — they're static site assets, not files table rows).
     * No default for unknown (99) — see defaultLogoFilename().
     *
     * @var array<int, string>
     */
    private const DEFAULT_LOGO_FILENAME = [
        10 => 'logo_baladins.png',
        20 => 'logo_louveteaux.png',
        30 => 'logo_eclaireurs.png',
        40 => 'logo_pionniers.png',
        50 => 'logo_staffdu.png',
        60 => 'logo_route.png',
        70 => 'logo_iama.png',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{id: int, desk_code: string, label: string, sort_order: int, logo_file_id: ?int, explanation_url: string}|null
     */
    public function findByDeskCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM age_branches WHERE desk_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return self::mapRow($row);
    }

    /**
     * @return array{id: int, desk_code: string, label: string, sort_order: int, logo_file_id: ?int, explanation_url: string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM age_branches WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return self::mapRow($row);
    }

    public function create(string $deskCode, string $label): int
    {
        $sortOrder = self::canonicalSortOrder($label);
        $stmt = $this->pdo->prepare(
            'INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)'
        );
        $stmt->execute([$deskCode, $label, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Return canonical sort order for known Les Scouts branches.
     * Order: Baladins → Louveteaux → Éclaireurs → Pionniers → Staff d'U → Route → Iama.
     * Unknown branches get sort_order 99 (displayed last).
     */
    public static function canonicalSortOrder(string $label): int
    {
        $normalized = mb_strtolower(trim($label));
        return match (true) {
            str_contains($normalized, 'baladin') => 10,
            str_contains($normalized, 'louveteau') => 20,
            str_contains($normalized, 'eclaireur'), str_contains($normalized, 'éclaireur') => 30,
            str_contains($normalized, 'pionnier') => 40,
            str_contains($normalized, 'staff'), str_contains($normalized, 'unité') => 50,
            str_contains($normalized, 'route'), str_contains($normalized, 'routier') => 60,
            str_contains($normalized, 'iama') => 70,
            default => 99,
        };
    }

    public function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare('UPDATE age_branches SET sort_order = ? WHERE id = ?');
        $stmt->execute([$sortOrder, $id]);
    }

    /**
     * @return array<int, array{id: int, desk_code: string, label: string, sort_order: int, logo_file_id: ?int, explanation_url: string}>
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM age_branches ORDER BY sort_order, label');
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => self::mapRow($row), $rows);
    }

    /**
     * Sets (or replaces) the uploaded federation logo for a branch —
     * Configuration > Config Desk (superadmin), via the generic /upload
     * flow's `age_branch_logo` context.
     */
    public function setLogo(int $id, int $fileId): void
    {
        $stmt = $this->pdo->prepare('UPDATE age_branches SET logo_file_id = ? WHERE id = ?');
        $stmt->execute([$fileId, $id]);
    }

    public function setExplanationUrl(int $id, string $url): void
    {
        $stmt = $this->pdo->prepare('UPDATE age_branches SET explanation_url = ? WHERE id = ?');
        $stmt->execute([$url, $id]);
    }

    /**
     * The shipped default logo filename (relative to
     * public/assets/img/branches/) for a canonical sort order, or null
     * only for an unknown branch (99) — the member page's branch card then
     * shows nothing rather than an empty box, matching section_photo()'s
     * own "nothing to show" behavior.
     */
    public static function defaultLogoFilename(int $sortOrder): ?string
    {
        return self::DEFAULT_LOGO_FILENAME[$sortOrder] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, desk_code: string, label: string, sort_order: int, logo_file_id: ?int, explanation_url: string}
     */
    private static function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'desk_code' => (string) $row['desk_code'],
            'label' => (string) $row['label'],
            'sort_order' => (int) $row['sort_order'],
            'logo_file_id' => $row['logo_file_id'] !== null ? (int) $row['logo_file_id'] : null,
            'explanation_url' => (string) $row['explanation_url'],
        ];
    }
}
