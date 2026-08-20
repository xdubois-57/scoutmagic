<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

class RegistrationYearCodeRepository
{
    /**
     * Excludes visually confusable characters (0/O, 1/I/L) — the code is
     * read aloud over the phone and typed back by a parent, never a
     * security secret (see RegistrationYearCode's docblock).
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private \PDO $pdo)
    {
    }

    public function findForYear(int $scoutYearId): ?RegistrationYearCode
    {
        $stmt = $this->pdo->prepare(
            'SELECT scout_year_id, code, is_active FROM registration_year_codes WHERE scout_year_id = ?'
        );
        $stmt->execute([$scoutYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new RegistrationYearCode(
            scoutYearId: (int) $row['scout_year_id'],
            code: (string) $row['code'],
            isActive: (bool) $row['is_active']
        );
    }

    /**
     * Whether $submittedCode matches the ACTIVE code for this scout year —
     * the one and only thing this class is asked to verify. Comparison is
     * case-insensitive and ignores the dictation grouping's separators,
     * since a parent may type either back.
     */
    public function isValidActiveCode(int $scoutYearId, string $submittedCode): bool
    {
        $code = $this->findForYear($scoutYearId);
        if ($code === null || !$code->isActive) {
            return false;
        }

        // Constant-time even though this code is explicitly not a security
        // barrier (§8.35: it is dictated over the phone and only decides
        // which scout year a submission targets) — it costs nothing and
        // keeps every secret comparison in the codebase looking the same.
        return hash_equals(self::normalize($code->code), self::normalize($submittedCode));
    }

    /**
     * Generates a fresh code for $scoutYearId (replacing any existing one —
     * the previous value stops matching immediately) and returns it.
     */
    public function regenerate(int $scoutYearId): string
    {
        $code = self::generateCode();

        $stmt = $this->pdo->prepare('SELECT id FROM registration_year_codes WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare('UPDATE registration_year_codes SET code = ?, is_active = 1 WHERE id = ?');
            $update->execute([$code, (int) $existingId]);
            return $code;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO registration_year_codes (scout_year_id, code, is_active) VALUES (?, ?, 1)'
        );
        $insert->execute([$scoutYearId, $code]);

        return $code;
    }

    /**
     * "Aucun code actif" — the only mechanism needed to close in-year
     * registration (schema.sql's docblock). Never deletes the row, so the
     * previous code stays visible for reference.
     */
    public function deactivate(int $scoutYearId): void
    {
        $stmt = $this->pdo->prepare('UPDATE registration_year_codes SET is_active = 0 WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);
    }

    /**
     * Grouped for dictation over the phone, e.g. "4KX9-7QRM".
     */
    private static function generateCode(): string
    {
        $length = 8;
        $alphabetLength = strlen(self::ALPHABET);
        $raw = '';
        for ($i = 0; $i < $length; $i++) {
            $raw .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4);
    }

    private static function normalize(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', $code));
    }
}
