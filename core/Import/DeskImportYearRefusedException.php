<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * A Desk import aimed at a scout year {@see DeskImportYearPolicy} does not
 * allow today, or at one it allows only once confirmed, without the
 * confirmation. Thrown before the file is read: nothing is written.
 *
 * An {@see ImportException}, so user-facing: every message is built in
 * this class, in French, from a year label and nothing else.
 */
final class DeskImportYearRefusedException extends ImportException
{
    public const REASON_NOT_OFFERED = 'not_offered';
    public const REASON_CONFIRMATION_MISSING = 'confirmation_missing';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $importableLabels what may be imported today, for the sentence
     */
    public static function notOffered(?string $label, array $importableLabels): self
    {
        $year = $label !== null ? "L'année {$label}" : "L'année choisie";
        $allowed = $importableLabels === []
            ? "Aucune année n'est importable aujourd'hui."
            : (count($importableLabels) === 1
                ? "Seule l'année {$importableLabels[0]} est importable aujourd'hui."
                : 'Années importables aujourd\'hui : ' . implode(', ', $importableLabels) . '.');

        return new self(
            "{$year} ne peut pas recevoir d'import Desk aujourd'hui, rien n'a été importé. {$allowed}",
            self::REASON_NOT_OFFERED
        );
    }

    public static function confirmationMissing(string $label): self
    {
        return new self(
            "L'import dans {$label} demande une confirmation, rien n'a été importé. Sélectionnez à nouveau le "
                . "fichier, touchez « Importer » et confirmez l'année dans la fenêtre qui s'ouvre.",
            self::REASON_CONFIRMATION_MISSING
        );
    }
}
