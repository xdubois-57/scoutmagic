<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard;

/**
 * What a support ticket is about (roadmap IT-23).
 *
 * **The receiver owns the vocabulary, and hands it to the instances.**
 * Every answer of the intake endpoint carries the current list, so an
 * installation renders a picker it did not have to be shipped with, and a
 * receiver that adds a category does not have to wait for every
 * installation to update before anybody can use it. The reverse — each
 * installation shipping its own list — would guarantee six spellings of
 * "e-mail" in the same dashboard within a year.
 *
 * **One half of the vocabulary is NOT the receiver's, and cannot be.**
 * « De quel module s'agit-il » is answered by the modules the SENDING
 * installation has enabled, which this side has no way of knowing: two
 * units run different sets, and publishing this receiver's own modules
 * would offer a unit categories for features it does not have. So a
 * module category is minted locally (`Core\Support\Ticket\
 * SupportTicketSender::categories()`), travels as `module_<id>`, and is
 * accepted here on the strength of its shape. That is the reason this
 * stopped being an enum: an enum is a vocabulary closed at compile time,
 * and half of this one is not.
 *
 * **Nothing is ever removed, only stopped being offered.** A category
 * that leaves the picker still has to name itself on the tickets already
 * filed under it — `RETIRED` is what keeps « Installation » readable on
 * a two-year-old ticket while no new one can be filed under it.
 *
 * There is deliberately **no urgency level**. A self-declared urgency
 * says more about the reporter than about the problem, and a field
 * everybody sets to "haute" is a field nobody reads.
 *
 * Instances are interned, so two objects for the same value are the same
 * object — `===` and `assertSame()` behave as they did for the enum.
 *
 * The stored value is lower snake case; the label is French because it is
 * what a form shows.
 */
final class TicketCategory
{
    /** How a module category is spelled, on both sides of the wire. */
    public const MODULE_PREFIX = 'module_';

    /**
     * The escape hatch, offered last: a picker that opens with « Autre »
     * gets nothing else.
     */
    public const OTHER = 'other';

    /** @var array<string, string> value => French label, in picker order */
    private const OFFERED = [
        'update' => 'Mise à jour',
        'email' => 'E-mail',
        'privacy' => 'Vie privée',
        'desk_import' => 'Import Desk',
        'performance' => 'Performance',
        self::OTHER => 'Autre',
    ];

    /**
     * Still readable, no longer offered.
     *
     * « Installation » went: an installation problem is one somebody has
     * before they have a site to report it from, so the tickets filed
     * under it were about everything else.
     *
     * @var array<string, string>
     */
    private const RETIRED = [
        'installation' => 'Installation',
    ];

    /** @var array<string, self> */
    private static array $interned = [];

    private function __construct(public readonly string $value, private readonly string $label)
    {
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The list the API publishes — the fixed half, and only that half:
     * the module categories belong to the sending installation and are
     * added there (see the class docblock).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function published(): array
    {
        $published = [];
        foreach (self::OFFERED as $value => $label) {
            $published[] = ['value' => $value, 'label' => $label];
        }

        return $published;
    }

    public static function tryFromValue(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (isset(self::$interned[$value])) {
            return self::$interned[$value];
        }

        $label = self::OFFERED[$value] ?? self::RETIRED[$value] ?? self::moduleLabel($value);
        if ($label === null) {
            return null;
        }

        return self::$interned[$value] = new self($value, $label);
    }

    /**
     * The known value, or a failure: for a caller that has already
     * validated, and for tests that name a category out loud.
     */
    public static function of(string $value): self
    {
        $category = self::tryFromValue($value);
        if ($category === null) {
            throw new \InvalidArgumentException("Catégorie de ticket inconnue : {$value}");
        }

        return $category;
    }

    /**
     * The value a module category travels under.
     */
    public static function forModule(string $moduleId): string
    {
        return self::MODULE_PREFIX . $moduleId;
    }

    /**
     * A module category, named by its id and not by its human name.
     *
     * The name lives in the sender's `module.json`, on the other side of
     * the wire; asking this receiver's own manifests for it would be a
     * guess that is wrong precisely when it matters — a unit reporting a
     * module this receiver does not have, or has under a newer name. The
     * id is what both sides actually agreed on, and it is what the
     * maintainer reading this dashboard greps for.
     *
     * Null for anything that is not a well-formed module id, which is
     * what makes an unknown category unknown rather than silently
     * accepted.
     */
    private static function moduleLabel(string $value): ?string
    {
        if (!str_starts_with($value, self::MODULE_PREFIX)) {
            return null;
        }

        $moduleId = substr($value, strlen(self::MODULE_PREFIX));
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $moduleId) !== 1) {
            return null;
        }

        return 'Module : ' . $moduleId;
    }
}
