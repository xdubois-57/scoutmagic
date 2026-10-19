<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * One reason why ScoutYearAdminService refuses to activate a year — the
 * same value for the server's refusal (carried by the exception) and for
 * the page that shows the refusal before anyone clicks (the steps 9 and
 * 14 of the workflow and the review before the switch), so the two
 * can never disagree on what blocks.
 *
 * Structured on purpose: the service that decides knows a year id, the
 * page knows its label, and the French sentence needs the label.
 */
final class ActivationBlocker
{
    /** activatePublicYear(): the staff year is not the year being activated. */
    public const STAFF_YEAR_NOT_TARGET = 'staff_year_not_target';
    /** Either activation: import_journal holds no import of that year. */
    public const NO_SUCCESSFUL_IMPORT = 'no_successful_import';
    /** activatePublicYear(): the registration module's veto (§19.2). */
    public const REGISTRATION_VETO = 'registration_veto';

    public function __construct(
        public readonly string $code,
        public readonly int $count = 0
    ) {
    }

    public function message(string $yearLabel): string
    {
        return match ($this->code) {
            self::STAFF_YEAR_NOT_TARGET => "{$yearLabel} n'est pas activée pour les staffs.",
            self::NO_SUCCESSFUL_IMPORT => "Aucun import Desk réussi pour {$yearLabel}.",
            self::REGISTRATION_VETO => "{$this->count} demande(s) d'inscription ne sont pas clôturées, toutes années "
                . 'confondues.',
            default => throw new \LogicException("Unknown activation blocker '{$this->code}'."),
        };
    }

    /** Where the blocker is resolved; null when it is resolved on the Année scoute page itself. */
    public function resolutionUrl(int $yearId): ?string
    {
        return match ($this->code) {
            self::NO_SUCCESSFUL_IMPORT => '/admin/import?annee=' . $yearId,
            self::REGISTRATION_VETO => '/config/inscriptions/demandes',
            default => null,
        };
    }

    public function resolutionLabel(string $yearLabel): ?string
    {
        return match ($this->code) {
            self::NO_SUCCESSFUL_IMPORT => "Importer {$yearLabel}",
            self::REGISTRATION_VETO => 'Traiter les demandes',
            default => null,
        };
    }
}
