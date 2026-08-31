<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard;

/**
 * What a support ticket is about — a closed list, declared here and
 * nowhere else (roadmap IT-23).
 *
 * **The receiver owns the vocabulary, and hands it to the instances.**
 * Every answer of the intake endpoint carries the current list, so an
 * installation renders a picker it did not have to be shipped with, and a
 * receiver that adds a category does not have to wait for every
 * installation to update before anybody can use it. The reverse — each
 * installation shipping its own list — would guarantee six spellings of
 * "e-mail" in the same dashboard within a year.
 *
 * There is deliberately **no urgency level**. A self-declared urgency
 * says more about the reporter than about the problem, and a field
 * everybody sets to "haute" is a field nobody reads.
 *
 * The stored value is the case name in lower snake case; the label is
 * French because it is what a form shows.
 */
enum TicketCategory: string
{
    case INSTALLATION = 'installation';
    case UPDATE = 'update';
    case EMAIL = 'email';
    case DESK_IMPORT = 'desk_import';
    case PERFORMANCE = 'performance';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::INSTALLATION => 'Installation',
            self::UPDATE => 'Mise à jour',
            self::EMAIL => 'E-mail',
            self::DESK_IMPORT => 'Import Desk',
            self::PERFORMANCE => 'Performance',
            self::OTHER => 'Autre',
        };
    }

    /**
     * The list as the API publishes it: value and French label, in the
     * order a form should offer them — « Autre » last, because a picker
     * that offers the escape hatch first gets nothing else.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function published(): array
    {
        return array_map(
            static fn(self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
