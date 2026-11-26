<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;

/**
 * The security half of one component, as an operational alert (issue
 * #1358, lot 5) — one key per component and context, so PHP du site and
 * PHP des tâches planifiées are announced, and re-armed, independently.
 *
 * It reads the snapshot the weekly run has just written and compares
 * nothing itself: the condition is {@see ComponentAssessment::
 * alertWorthy()}, decided once by the evaluator.
 *
 * - **Triggered** when an update is needed and an advisory at CVSS ≥ 7
 *   applies or may apply. The reading carries the identifiers of those
 *   advisories as its subjects, so {@see \Core\Alert\OperationalAlertService}
 *   announces again — once — each time that set grows (the maintainer's
 *   decision 3), and never for the same set twice.
 * - **Re-armed** only on a conclusive verdict that the condition no longer
 *   holds: a version verified current, or a complete advisory answer with
 *   nothing at 7 or above.
 * - **Inconclusive** otherwise — a component not measured, a source that
 *   failed, a lookup still pending — which leaves a standing alert
 *   standing.
 *
 * Deliberately not under `core/Alert/Check/`: those are fixed, one-key
 * checks; this one is a family of keys, listed by {@see labels()}.
 */
final class HostSecurityAlertCheck implements OperationalCheck
{
    public const KEY_PREFIX = 'host_security_';

    public function __construct(
        private readonly string $subject,
        private readonly ?ComponentAssessment $assessment,
    ) {
    }

    /**
     * One check per subject this check can ever report — those the snapshot
     * does not carry (a component confirmed absent, a context never
     * measured) read inconclusive, so an alert raised before keeps its
     * state rather than re-arming on a missing line.
     *
     * @return list<self>
     */
    public static function forSnapshot(HostSecuritySnapshot $snapshot): array
    {
        $checks = [];
        foreach (array_keys(HostInventory::SUBJECTS) as $subject) {
            $checks[] = new self($subject, $snapshot->find($subject));
        }

        return $checks;
    }

    /**
     * Every key this family can write, with the name the attention page
     * prints — merged into {@see \Core\Alert\AlertSurfaces::labels()}.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (HostInventory::SUBJECTS as $subject => $label) {
            $labels[self::KEY_PREFIX . $subject] = 'Sécurité de l\'hébergement — ' . $label;
        }

        return $labels;
    }

    public function key(): string
    {
        return self::KEY_PREFIX . $this->subject;
    }

    public function label(): string
    {
        return self::labels()[$this->key()] ?? $this->key();
    }

    public function read(): AlertReading
    {
        $assessment = $this->assessment;
        if ($assessment === null) {
            return AlertReading::inconclusive();
        }

        if ($assessment->alertWorthy()) {
            $count = count($assessment->highAdvisoryIds);

            return new AlertReading(
                overTrigger: true,
                underRearm: false,
                value: (string) $assessment->version . ' — ' . $count . ' avis de sécurité CVSS ≥ 7',
                title: $assessment->label . ' ' . $assessment->version . ' : '
                    . ($count > 1 ? $count . ' failles de sécurité connues' : 'une faille de sécurité connue')
                    . ' (CVSS ≥ 7)',
                why: $this->why($assessment),
                actionUrl: '/config/maintenance',
                actionLabel: 'Ouvrir la santé de l\'hébergement',
                subjects: $assessment->highAdvisoryIds
            );
        }

        if ($assessment->conclusive) {
            return new AlertReading(false, true, (string) $assessment->version, '', '');
        }

        return AlertReading::inconclusive();
    }

    /**
     * The advisories with their gravity, and the action — the body of the
     * notification, kept to what a phone shows: the five gravest by name,
     * the rest counted.
     */
    private function why(ComponentAssessment $assessment): string
    {
        $high = array_values(array_filter(
            $assessment->advisories,
            static fn(Advisory $advisory): bool => $advisory->isHigh()
        ));
        $named = array_map(
            static fn(Advisory $advisory): string => $advisory->id . ' (' . $advisory->severityLabel() . ')',
            array_slice($high, 0, 5)
        );
        $others = count($assessment->highAdvisoryIds) - count($named);

        $text = 'Avis : ' . implode(', ', $named) . ($others > 0 ? ' et ' . $others . ' autre(s)' : '') . '.';
        if (!$assessment->certain) {
            $text .= ' L\'hébergeur a peut-être déjà intégré ces correctifs : à confirmer avec lui.';
        }

        return $text . ' ' . $assessment->action;
    }
}
