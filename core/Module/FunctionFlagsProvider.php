<?php

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to attach a configurable, per-function
 * boolean flag to the core "Config Desk" configuration page (Configuration >
 * Config Desk — ARCHITECTURE.md §3: functions are managed on a core page, not
 * via a module).
 *
 * Example: the trombinoscope module shows every active chief/chief-d'unité
 * member on the staff photo wall (role-based, not configurable), but needs to
 * know which function marks a section's "responsable" — without hardcoding
 * function names. It implements this interface and is wired into
 * FunctionsController from the composition root (public/index.php) only when
 * the module is enabled, keeping core free of any dependency on module code
 * (core defines the interface, the module implements it — never the other
 * way around).
 */
interface FunctionFlagsProvider
{
    /** Section title shown on the Fonctions page (French UI text). */
    public function getSectionLabel(): string;

    /** Label for the flag checkbox (French UI text). */
    public function getLeadLabel(): string;

    /**
     * Current flag for every function this provider cares about, keyed by
     * function id.
     *
     * @return array<int, bool>
     */
    public function getLeadFlags(): array;

    public function setLead(int $functionId, bool $lead): void;
}
