<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\SettingService;
use Core\Photo\SectionPhotoRepository;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\ScoutYearEventCountProvider;
use Modules\Registration\Api\ScoutYearPreparationProvider;

/**
 * The "Année scoute" workflow: everything a chef d'unité has to do to move
 * the unit from one scout year to the next, in the order it actually
 * happens, grouped into the three phases the season really has.
 *
 * ============================================================================
 * THIS CLASS IS THE SPECIFICATION FOR THE END-TO-END TEST
 * tests/e2e/specs/scout-year-transition.spec.js.
 * ----------------------------------------------------------------------------
 * That test replays the whole workflow in a real browser: the three phases,
 * every step in order, which steps disappear when a module is off, which
 * ones tick themselves and which ones a human ticks, the fact that the
 * order is advisory rather than enforced, and the two blocks that are
 * real. **Adding, removing or reordering a step, changing what marks one
 * done, changing a step's key, or rewording a title the test matches on
 * means updating that test in the same change.** The rendered page
 * (core/View/templates/admin/scout_year.html.twig) carries the same
 * reminder, as does the test itself.
 *
 * The test is blocking: it fails CI (the "End-to-end (browser)" job) and
 * scripts/release.sh's E2E gate. See README.md § Tests de bout en bout.
 * ============================================================================
 *
 * Three things are worth knowing before changing anything here.
 *
 * **The order is advice, not a gate.** Only two conditions actually stop
 * anything, and both are real: a public year cannot be activated without a
 * staff year first, and the registration module vetoes that activation
 * while requests are open (§19.2). Everything else stays clickable at all
 * times. A fourteen-step workflow that locked each step behind the
 * previous one would be unusable the first time a unit did something in a
 * different order — which is most years.
 *
 * **"Done" comes from two places, never three.** A step is done when the
 * site can *see* it is done (members imported, staff year set, every
 * section photographed for the year) or when someone said so
 * (ScoutYearTransitionStepRepository). Steps whose work happens inside
 * Desk, or whose completion is a judgement call, only ever have the second
 * — the site has no way to observe them and must not pretend otherwise.
 * A step with a signal keeps its manual tick as an override, so a chief is
 * never stuck behind a signal that will not fire for their unit.
 *
 * **The date is a signpost.** The "encodage dans Desk" date
 * (SETTING_DESK_ENCODING_DATE, 15 August by default) labels the phases and
 * says which one the calendar is currently in. It disables nothing,
 * activates nothing and triggers nothing — specifications.md §16.4 removed
 * date-driven transitions on purpose, because a computed date cannot be
 * told "not yet" by the registration veto. Reintroducing one here would
 * walk straight back into that.
 */
class ScoutYearTransitionService
{
    /**
     * Day and month (MM-JJ) from which the workflow presents the Desk
     * encoding phase as the current one. Indicative only — see the class
     * comment.
     */
    public const SETTING_DESK_ENCODING_DATE = 'scout_year_desk_encoding_date';

    public const PHASE_PREPARATION = 'preparation';
    public const PHASE_DESK = 'desk';
    public const PHASE_SITE = 'site';

    /**
     * Steps a human ticks off. Every other step is derived from a signal
     * and has no checkbox at all: ticking "activer pour tout le monde" by
     * hand would be a plain lie, since the site knows perfectly well
     * whether it happened.
     */
    private const MANUAL_STEPS = [
        'departures',
        'passage',
        'confirm_registrations',
        'desk_staff',
        'desk_members',
        'fees',
        'ephemerides',
        'badges',
        'trombinoscope',
        'staff_photos',
    ];

    /**
     * Which module each step belongs to. A step whose module is disabled
     * or absent is dropped from the workflow entirely rather than shown
     * greyed out: it would link to a page that does not exist, and a
     * permanently un-completable step in a numbered list reads as the
     * workflow being stuck.
     */
    private const STEP_MODULES = [
        'departures' => 'registration',
        'passage' => 'registration',
        'confirm_registrations' => 'registration',
        'ephemerides' => 'calendar',
        'trombinoscope' => 'trombinoscope',
    ];

    /** Memoised answer of the calendar module, asked twice per build. */
    private ?int $eventCountCache = null;

    /**
     * @param array<int, string> $enabledModuleIds ModuleManager::getEnabledModuleIds(),
     *        passed as plain data rather than as the manager itself — this
     *        service only ever asks "is X on", and a list of ids is what a
     *        test can hand it without booting the module system.
     */
    public function __construct(
        private ScoutYearResolver $resolver,
        private ScoutYearTransitionStepRepository $stepRepository,
        private SectionPhotoRepository $sectionPhotoRepository,
        private UserAccountRepository $userAccountRepository,
        private SettingService $settingService,
        private array $enabledModuleIds = [],
        private ?ScoutYearPreparationProvider $preparation = null,
        private ?ScoutYearEventCountProvider $eventCount = null
    ) {
    }

    /**
     * Build the whole workflow for one transition, recomputed on every
     * request and never cached.
     *
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     * @return array{
     *   phases: array<int, array{key: string, title: string, subtitle: ?string, note: ?string, current: bool, steps: array<int, array<string, mixed>>}>,
     *   current_step: int,
     *   step_count: int,
     *   desk_encoding_date: string
     * }
     */
    public function buildWorkflow(
        array $targetYear,
        array $publicYear,
        ?int $sessionPreviewId,
        ?int $staffYearId,
        int $publicYearId,
        \DateTimeImmutable $now
    ): array {
        $target = (int) $targetYear['id'];
        $marks = $this->stepRepository->findByYear($target);
        $deskDate = $this->deskEncodingDate($targetYear);
        $deskPhaseReached = $now->format('Y-m-d') >= $deskDate;

        $steps = $this->stepDefinitions(
            $targetYear,
            $publicYear,
            $sessionPreviewId,
            $staffYearId,
            $publicYearId,
            $target
        );

        $steps = $this->decorate($steps, $marks, $target);

        $phases = [
            [
                'key' => self::PHASE_PREPARATION,
                'title' => 'Préparation',
                'subtitle' => 'Idéalement avant le ' . $this->frenchDate($deskDate),
                'note' => null,
                'current' => !$deskPhaseReached,
                'steps' => [],
            ],
            [
                'key' => self::PHASE_DESK,
                'title' => 'Encodage dans Desk',
                'subtitle' => 'Plutôt à partir du ' . $this->frenchDate($deskDate),
                'note' => null,
                'current' => $deskPhaseReached,
                'steps' => [],
            ],
            [
                'key' => self::PHASE_SITE,
                'title' => 'Mise à jour du site',
                'subtitle' => null,
                'note' => "Le site est à jour pour {$targetYear['label']} : c'est typiquement au tour des staffs d'agir.",
                'current' => false,
                'steps' => [],
            ],
        ];

        $byKey = [];
        foreach ($phases as $index => $phase) {
            $byKey[$phase['key']] = $index;
        }
        foreach ($steps as $step) {
            $phases[$byKey[$step['phase']]]['steps'][] = $step;
        }

        // A phase whose every step belonged to a disabled module has
        // nothing left to show; rendering its heading over an empty list
        // would suggest the workflow lost a step rather than never had one.
        $phases = array_values(array_filter($phases, static fn(array $p): bool => $p['steps'] !== []));

        return [
            'phases' => $phases,
            'current_step' => $this->firstUndoneNumber($steps),
            'step_count' => count($steps),
            'desk_encoding_date' => $deskDate,
        ];
    }

    /**
     * Mark or unmark one manual step for a target year. Returns false when
     * $stepKey is not a manual step — an auto-derived step's state is not
     * a chief's to set, and a step of a disabled module is not theirs to
     * set either.
     */
    public function setManualStep(int $targetYearId, string $stepKey, bool $done, ?int $userAccountId): bool
    {
        if (!$this->isManualStep($stepKey)) {
            return false;
        }

        if ($done) {
            $this->stepRepository->mark($targetYearId, $stepKey, $userAccountId);
        } else {
            $this->stepRepository->unmark($targetYearId, $stepKey);
        }

        return true;
    }

    /**
     * Whether a step key may be ticked by hand right now: it must be one of
     * the manual steps, and its module (if it has one) must be enabled.
     */
    public function isManualStep(string $stepKey): bool
    {
        if (!in_array($stepKey, self::MANUAL_STEPS, true)) {
            return false;
        }

        $module = self::STEP_MODULES[$stepKey] ?? null;

        return $module === null || in_array($module, $this->enabledModuleIds, true);
    }

    /**
     * The indicative Desk-encoding date for one transition, as 'Y-m-d':
     * the configured MM-JJ placed in the calendar year the target scout
     * year starts in (15 August 2026 for the 2026-2027 transition). An
     * unparseable setting falls back to 15 August rather than throwing —
     * this date decorates a page, it never gates anything, and a page that
     * refuses to render because a signpost is malformed would be a far
     * worse failure than a signpost pointing at the default.
     *
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     */
    private function deskEncodingDate(array $targetYear): string
    {
        $configured = trim((string) $this->settingService->get(self::SETTING_DESK_ENCODING_DATE, null, '08-15'));
        if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $configured) !== 1) {
            $configured = '08-15';
        }

        $startYear = substr((string) $targetYear['start_date'], 0, 4);

        return $startYear . '-' . $configured;
    }

    /**
     * The ordered step catalogue. Steps of a disabled module are filtered
     * out here, before numbering, so the numbers a chief reads are always
     * 1..n over the steps their site actually has.
     *
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     * @return array<int, array<string, mixed>>
     */
    private function stepDefinitions(
        array $targetYear,
        array $publicYear,
        ?int $sessionPreviewId,
        ?int $staffYearId,
        int $publicYearId,
        int $target
    ): array {
        $targetLabel = $targetYear['label'];
        $publicLabel = $publicYear['label'];
        $memberCount = $this->resolver->countMembers($target);

        $definitions = [
            [
                'key' => 'departures',
                'phase' => self::PHASE_PREPARATION,
                'title' => "Indiquer les animés qui se désinscrivent en {$targetLabel}",
                'description' => "Chaque staff coche, sur la page Départs, les animés de sa section qui ne reviendront pas en {$targetLabel}. Le repérage se remet à zéro tout seul au prochain import Desk — il n'y a jamais rien à effacer d'une année à l'autre.",
                'auto_done' => null,
                'action_url' => '/departs',
                'action_label' => 'Aller aux Départs',
            ],
            [
                'key' => 'passage',
                'phase' => self::PHASE_PREPARATION,
                'title' => 'Indiquer la section des animés qui changent de branche',
                'description' => "Sur la page Passage, choisissez la section d'arrivée de chaque animé qui quitte sa branche en {$targetLabel}.",
                'auto_done' => $this->passageDone(),
                'progress' => $this->passageProgress(),
                'action_url' => '/passage',
                'action_label' => 'Aller au Passage',
            ],
            [
                'key' => 'confirm_registrations',
                'phase' => self::PHASE_PREPARATION,
                'title' => 'Confirmer les inscriptions et indiquer la branche cible',
                'description' => "Acceptez ou refusez les demandes d'inscription pour {$targetLabel}, puis renseignez la « section prévue » de chaque demande acceptée — c'est elle qui alimente le bloc « nouvelles inscriptions » de la page Passage.",
                'auto_done' => null,
                'action_url' => '/config/inscriptions',
                'action_label' => 'Aller aux inscriptions',
            ],
            [
                'key' => 'desk_staff',
                'phase' => self::PHASE_DESK,
                'title' => 'Encoder les nouveaux staffs dans Desk',
                'description' => "Dans Desk, encodez les animateurs et animatrices qui rejoignent l'unité en {$targetLabel}, ainsi que les changements de staff. Cette étape se passe hors du site : cochez-la vous-même une fois faite.",
                'auto_done' => null,
            ],
            [
                'key' => 'desk_members',
                'phase' => self::PHASE_DESK,
                'title' => 'Encoder les animés dans Desk (nouveaux inscrits, passages, sortants)',
                'description' => "Dans Desk, reportez les décisions de la phase de préparation : les nouvelles inscriptions, les passages d'une section à l'autre, et les départs. Cette étape se passe hors du site : cochez-la vous-même une fois faite.",
                'note' => "Une fois l'import fait (étape suivante), les animateurs peuvent se fier à la prévisualisation de {$targetLabel} et télécharger la liste des membres {$targetLabel} depuis « Membres par section ». Cette liste permet déjà de communiquer avec les familles par email.",
                'note_url' => '/chefs/membres',
                'note_label' => 'Aller aux membres par section',
                'auto_done' => null,
            ],
            [
                'key' => 'fees',
                'phase' => self::PHASE_DESK,
                'title' => 'Mettre à jour les cotisations',
                'description' => "Dans Desk, mettez à jour les cotisations pour {$targetLabel}. Cette étape se passe hors du site : cochez-la vous-même une fois faite.",
                'auto_done' => null,
            ],
            [
                'key' => 'preview',
                'phase' => self::PHASE_DESK,
                'title' => "Prévisualiser le site de l'année prochaine ({$targetLabel})",
                'description' => "Choisissez {$targetLabel} ci-dessous pour voir le site tel qu'il apparaîtra. Cette prévisualisation ne concerne que votre session et n'affecte aucun autre utilisateur.",
                'auto_done' => $sessionPreviewId === $target,
                'action' => 'preview',
            ],
            [
                'key' => 'import',
                'phase' => self::PHASE_DESK,
                'title' => 'Importer les données Desk',
                'description' => "Importez le fichier CSV Desk pour l'année {$targetLabel} depuis la page « Import Desk ». Les membres de la nouvelle année deviennent alors disponibles.",
                'auto_done' => $memberCount > 0,
                'progress' => $memberCount > 0 ? "{$memberCount} membre(s) importé(s) pour {$targetLabel}" : null,
                'action_url' => '/admin/import',
                'action_label' => "Aller à l'import Desk",
            ],
            [
                'key' => 'activate_staff',
                'phase' => self::PHASE_SITE,
                'title' => "Activer l'année {$targetLabel} pour les staffs",
                'description' => "Les chefs et intendants verront {$targetLabel} dès leur prochaine connexion, tandis que les animés et les visiteurs restent sur l'année courante ({$publicLabel}).",
                'auto_done' => $staffYearId === $target,
                'warning' => $memberCount === 0
                    ? "Aucun membre n'a encore été importé pour {$targetLabel} : les staffs verraient une année vide. Rien ne vous empêche d'activer quand même, mais l'import est normalement fait d'abord."
                    : null,
                'action' => 'activate-staff',
            ],
            [
                'key' => 'ephemerides',
                'phase' => self::PHASE_SITE,
                'title' => "Encoder les éphémérides de l'année sur le site",
                'description' => "Encodez dans le calendrier les dates de {$targetLabel} : réunions, week-ends, camps, réunions de staff.",
                'auto_done' => $this->ephemeridesDone($targetYear),
                'progress' => $this->ephemeridesProgress($targetYear),
                'action_url' => '/chefs/calendar',
                'action_label' => 'Aller au calendrier',
            ],
            [
                'key' => 'badges',
                'phase' => self::PHASE_SITE,
                'title' => 'Encoder les badges (trésorier·e, infirmier·e, référent·e·s…)',
                'description' => "Attribuez les badges transversaux de {$targetLabel} aux membres du staff, depuis la page Staffs.",
                'auto_done' => null,
                'action_url' => '/chefs/staffs',
                'action_label' => 'Aller aux staffs',
            ],
            [
                'key' => 'trombinoscope',
                'phase' => self::PHASE_SITE,
                'title' => 'Mettre à jour le trombinoscope',
                'description' => "Le trombinoscope se reconstruit tout seul à partir de l'import Desk : vérifiez les photos et les responsables de section pour {$targetLabel}.",
                'auto_done' => null,
                'action_url' => '/trombinoscope',
                'action_label' => 'Aller au trombinoscope',
            ],
            [
                'key' => 'staff_photos',
                'phase' => self::PHASE_SITE,
                'title' => 'Mettre à jour les photos de staff',
                'description' => "Chaque section a une photo de staff par année scoute. Tant que celle de {$targetLabel} n'est pas prise, le site continue d'afficher celle de l'année précédente.",
                'auto_done' => $this->staffPhotosDone($target),
                'progress' => $this->staffPhotosProgress($target),
                'action_url' => '/chefs/staffs',
                'action_label' => 'Aller aux staffs',
            ],
            [
                'key' => 'activate_public',
                'phase' => self::PHASE_SITE,
                'title' => 'Activer pour tout le monde',
                'description' => "Bascule l'ensemble du site (visiteurs inclus) sur {$targetLabel} de façon permanente et désactive l'année du staff.",
                'auto_done' => $publicYearId === $target,
                'action' => 'activate-public',
            ],
        ];

        return array_values(array_filter($definitions, function (array $definition): bool {
            $module = self::STEP_MODULES[$definition['key']] ?? null;

            return $module === null || in_array($module, $this->enabledModuleIds, true);
        }));
    }

    /**
     * Number the surviving steps and fold in their manual state.
     *
     * @param array<int, array<string, mixed>> $definitions
     * @param array<string, array{done_at: string, done_by: ?int}> $marks
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $definitions, array $marks, int $target): array
    {
        $names = $this->userAccountRepository->findNamesByIds(array_values(array_filter(
            array_map(static fn(array $mark): ?int => $mark['done_by'], $marks),
            static fn(?int $id): bool => $id !== null
        )));

        $steps = [];
        foreach ($definitions as $index => $definition) {
            $key = (string) $definition['key'];
            $mark = $marks[$key] ?? null;
            $manual = in_array($key, self::MANUAL_STEPS, true);
            $autoDone = $definition['auto_done'] ?? null;
            $manualDone = $manual && $mark !== null;

            $steps[] = [
                'key' => $key,
                'number' => $index + 1,
                'phase' => $definition['phase'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                // A signal that fires and a human tick are both enough on
                // their own: the signal so the page keeps up with reality
                // without anyone clicking, the tick so a unit whose signal
                // will never fire (no passage this year, a calendar kept
                // elsewhere) is not left with a step it cannot finish.
                'done' => ($autoDone === true) || $manualDone,
                'auto_done' => $autoDone,
                'manual' => $manual,
                'manual_done' => $manualDone,
                'done_at' => $mark['done_at'] ?? null,
                'done_by' => $mark !== null ? $this->formatName($names[$mark['done_by']] ?? null) : null,
                'action' => $definition['action'] ?? null,
                'action_url' => $definition['action_url'] ?? null,
                'action_label' => $definition['action_label'] ?? null,
                'progress' => $definition['progress'] ?? null,
                'warning' => $definition['warning'] ?? null,
                'note' => $definition['note'] ?? null,
                'note_url' => $definition['note_url'] ?? null,
                'note_label' => $definition['note_label'] ?? null,
                'target_year_id' => $target,
            ];
        }

        return $steps;
    }

    /**
     * The step the page highlights: the first one not yet done. 0 when
     * everything is done — the workflow is over and nothing is current.
     *
     * @param array<int, array<string, mixed>> $steps
     */
    private function firstUndoneNumber(array $steps): int
    {
        foreach ($steps as $step) {
            if ($step['done'] !== true) {
                return (int) $step['number'];
            }
        }

        return 0;
    }

    /**
     * @param array{first_name: ?string, last_name: ?string}|null $name
     */
    private function formatName(?array $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $full = trim(($name['first_name'] ?? '') . ' ' . ($name['last_name'] ?? ''));

        return $full !== '' ? $full : null;
    }

    /**
     * Null (no signal) when the registration module is off — the step is
     * gone in that case anyway, but the catalogue is built before the
     * filtering and must not call a provider that isn't there.
     */
    private function passageDone(): ?bool
    {
        return $this->preparation !== null ? $this->preparation->countUnassignedPassages() === 0 : null;
    }

    private function passageProgress(): ?string
    {
        if ($this->preparation === null) {
            return null;
        }

        $total = $this->preparation->countPassages();
        if ($total === 0) {
            return 'Aucun animé ne change de branche cette année.';
        }

        $unassigned = $this->preparation->countUnassignedPassages();

        return $unassigned === 0
            ? "{$total} passage(s), tous avec une section d'arrivée."
            : "{$unassigned} passage(s) sur {$total} sans section d'arrivée.";
    }

    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     */
    private function ephemeridesDone(array $targetYear): ?bool
    {
        $count = $this->countEvents($targetYear);

        return $count !== null ? $count > 0 : null;
    }

    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     */
    private function ephemeridesProgress(array $targetYear): ?string
    {
        $count = $this->countEvents($targetYear);

        return $count !== null && $count > 0
            ? "{$count} événement(s) encodé(s) pour {$targetYear['label']}"
            : null;
    }

    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     */
    private function countEvents(array $targetYear): ?int
    {
        if ($this->eventCount === null) {
            return null;
        }

        // Asked twice per build (once for "done", once for the progress
        // line) and answered by a query, so it is answered once.
        return $this->eventCountCache ??= $this->eventCount->countEventsBetween(
            (string) $targetYear['start_date'],
            (string) $targetYear['end_date']
        );
    }

    /**
     * French "15 août 2026" from a 'Y-m-d' string, without the intl
     * extension — the same 12-entry lookup Core\View\TwigFactory's
     * french_date filter uses. Done here rather than in the template
     * because the phase subtitle is one sentence the workflow owns, and
     * because this service must stay renderable by a bare Twig
     * environment that has registered no filters of ours.
     */
    private function frenchDate(string $date): string
    {
        static $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($parsed === false) {
            return $date;
        }

        return (int) $parsed->format('j') . ' ' . $months[(int) $parsed->format('n')] . ' ' . $parsed->format('Y');
    }

    /**
     * Done once every section the target year actually has is photographed
     * for that year. Undecidable — and therefore no signal at all — before
     * the import, when the year has no sections yet: "0 of 0 sections
     * photographed" is not an achievement.
     */
    private function staffPhotosDone(int $target): ?bool
    {
        $sections = $this->resolver->countSections($target);
        if ($sections === 0) {
            return null;
        }

        return $this->sectionPhotoRepository->countSectionsWithPhotoForYear($target) >= $sections;
    }

    private function staffPhotosProgress(int $target): ?string
    {
        $sections = $this->resolver->countSections($target);
        if ($sections === 0) {
            return null;
        }

        $withPhoto = $this->sectionPhotoRepository->countSectionsWithPhotoForYear($target);

        return "{$withPhoto}/{$sections} section(s) photographiée(s)";
    }
}
