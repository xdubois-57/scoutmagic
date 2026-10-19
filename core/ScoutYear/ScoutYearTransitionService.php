<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\AppClock;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalRepository;
use Core\ScoutYear\Step\PublicYearSwitch;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Core\ScoutYear\Step\StepMark;
use Core\ScoutYear\Step\StepMarkResult;
use Core\ScoutYear\Step\StepNature;
use Core\ScoutYear\Step\StepState;
use Core\ScoutYear\Step\TransitionPhase;
use Core\ScoutYear\Step\TransitionStep;
use Core\ScoutYear\Step\TransitionWorkflow;
use Core\Security\UserAccountRepository;
use Core\Service\DateInput;

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
 * ones the site observes and which ones a human marks, the fact that the
 * order is advisory rather than enforced, and the blocks that are real.
 * **Adding, removing or reordering a step, changing what marks one done,
 * changing a step's key, or rewording a title the test matches on means
 * updating that test in the same change.** The rendered page
 * (core/View/templates/admin/scout_year.html.twig) carries the same
 * reminder, as does the test itself.
 *
 * The test is blocking: it fails the "Checks / End-to-end (browser)" job,
 * on every pull request and again on every release tag. See docs/developpement.md
 * § Tests de bout en bout.
 * ============================================================================
 *
 * This class is the ENGINE (specifications.md §16.3): it owns which steps
 * exist (STEP_KEYS, minus those of a disabled module), their order, phase,
 * nature and title, and it turns them into immutable TransitionStep values.
 * Everything a step says that depends on data — points to check, actions,
 * observed « done », blocks, refusal to mark — comes from that step's
 * Core\ScoutYear\Step\StepCheckProvider, one per key, so a check can be
 * filled without touching the engine.
 *
 * Four things are worth knowing before changing anything here.
 *
 * **The order is advice, not a gate.** Only three things are refused, all
 * on the server: marking step 1 while the re-enrolment campaign is not
 * finished (its check's markRefusal), activating the target for the staff
 * without a successful import, and the switch for everyone without the
 * staff year on the target, without a successful import, or against the
 * registration veto (ScoutYearAdminService, the only write path). Every
 * other control stays usable at all times.
 *
 * **"Done" has one source per step, never two.** A HUMAN step is done when
 * someone marked it (ScoutYearTransitionStepRepository) — whatever its
 * checks say, which only ever produce points to check. An OBSERVED step
 * (import, activate_staff, activate_public) is done when the site sees it,
 * and can never be marked: markStep() refuses its key.
 *
 * **A check without data is not a success.** Before the target year's
 * import, a check that reads it says so (StepContext::
 * waitingForImportMessage()) and produces neither a point nor a « ✓ ».
 *
 * **On this page the date is a signpost.** The "encodage dans Desk" date
 * (SETTING_DESK_ENCODING_DATE, 15 August by default) labels the phases and
 * says which one the calendar is currently in. Here it disables nothing,
 * activates nothing and triggers nothing (elsewhere it closes the public
 * year to the Desk import — Core\Import\DeskImportYearPolicy — and never
 * switches a year) — specifications.md §16.4 removed
 * date-driven transitions on purpose, because a computed date cannot be
 * told "not yet" by the registration veto.
 */
class ScoutYearTransitionService
{
    /**
     * Day and month (MM-JJ) from which the workflow presents the Desk
     * encoding phase as the current one. Indicative on this page — see the
     * class comment; the Desk import's year policy reads it too
     * (Core\Import\DeskImportYearDates::SETTING_ENCODING).
     */
    public const SETTING_DESK_ENCODING_DATE = 'scout_year_desk_encoding_date';

    public const PHASE_PREPARATION = 'preparation';
    public const PHASE_DESK = 'desk';
    public const PHASE_SITE = 'site';

    /**
     * Every step, in the advised order (specifications.md §16.3), with
     * its phase and nature.
     * The keys are the `step_key` values stored in
     * scout_year_transition_steps and must never be renamed: a rename
     * would silently forget every mark already made.
     */
    public const STEP_KEYS = [
        'departures' => [self::PHASE_PREPARATION, StepNature::HUMAN],
        'passage' => [self::PHASE_PREPARATION, StepNature::HUMAN],
        'confirm_registrations' => [self::PHASE_PREPARATION, StepNature::HUMAN],
        'desk_staff' => [self::PHASE_DESK, StepNature::HUMAN],
        'desk_members' => [self::PHASE_DESK, StepNature::HUMAN],
        'import' => [self::PHASE_DESK, StepNature::OBSERVED],
        'fees' => [self::PHASE_DESK, StepNature::HUMAN],
        'preview' => [self::PHASE_DESK, StepNature::HUMAN],
        'activate_staff' => [self::PHASE_SITE, StepNature::OBSERVED],
        'ephemerides' => [self::PHASE_SITE, StepNature::HUMAN],
        'badges' => [self::PHASE_SITE, StepNature::HUMAN],
        'trombinoscope' => [self::PHASE_SITE, StepNature::HUMAN],
        'staff_photos' => [self::PHASE_SITE, StepNature::HUMAN],
        'activate_public' => [self::PHASE_SITE, StepNature::OBSERVED],
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
        'fees' => 'fees',
        'ephemerides' => 'calendar',
        'trombinoscope' => 'trombinoscope',
    ];

    /** The journal entry ScoutYearController writes on a successful switch for everyone. */
    public const JOURNAL_PUBLIC_ACTIVATED = 'scout_year_public_activated';

    /** @var array<string, StepCheckProvider> */
    private array $checks = [];

    /**
     * @param iterable<StepCheckProvider> $checkProviders one per key of STEP_KEYS; a step that is
     *        on the page without its provider is a wiring error and throws
     * @param array<int, string> $enabledModuleIds ModuleManager::getEnabledModuleIds(),
     *        passed as plain data rather than as the manager itself — this
     *        service only ever asks "is X on", and a list of ids is what a
     *        test can hand it without booting the module system.
     */
    public function __construct(
        private ScoutYearTransitionStepRepository $stepRepository,
        private ImportJournalRepository $importJournal,
        private JournalRepository $journal,
        private ScoutYearService $scoutYearService,
        private UserAccountRepository $userAccountRepository,
        private SettingService $settingService,
        iterable $checkProviders,
        private array $enabledModuleIds = []
    ) {
        foreach ($checkProviders as $provider) {
            $key = $provider->stepKey();
            if (!array_key_exists($key, self::STEP_KEYS)) {
                throw new \LogicException("Step check provider for unknown step '{$key}'.");
            }
            if (isset($this->checks[$key])) {
                throw new \LogicException("Two step check providers for step '{$key}'.");
            }
            $this->checks[$key] = $provider;
        }
    }

    /**
     * The context every check of one request reads — built from server
     * state only. $targetYear is the year following the public one, as
     * ScoutYearController computes it.
     *
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     */
    public function contextFor(
        array $targetYear,
        array $publicYear,
        ?int $sessionPreviewId,
        ?int $staffYearId,
        \DateTimeImmutable $now
    ): StepContext {
        $imports = $this->importJournal->findRecordsByYear((int) $targetYear['id']);

        return new StepContext($targetYear, $publicYear, $staffYearId, $sessionPreviewId, $now, $imports[0] ?? null);
    }

    /**
     * Build the whole workflow for one transition, recomputed on every
     * request and never cached.
     */
    public function buildWorkflow(StepContext $context): TransitionWorkflow
    {
        $target = $context->targetId();
        $marks = $this->stepRepository->findByYear($target);
        $names = $this->userAccountRepository->findNamesByIds(array_values(array_filter(
            array_map(static fn(array $mark): ?int => $mark['done_by'], $marks),
            static fn(?int $id): bool => $id !== null
        )));

        $steps = [];
        foreach ($this->presentKeys() as $index => $key) {
            [$phase, $nature] = self::STEP_KEYS[$key];
            $text = $this->text($key, $context);
            $check = $this->provider($key)->check($context);
            $markRow = $nature === StepNature::HUMAN ? ($marks[$key] ?? null) : null;

            $steps[] = new TransitionStep(
                key: $key,
                number: $index + 1,
                phase: $phase,
                nature: $nature,
                state: $this->state($nature, $check, $markRow !== null),
                title: $text['title'],
                description: $text['description'],
                pointsToCheck: $check->pointsToCheck,
                primaryAction: $check->primaryAction,
                actions: $this->actions($check),
                markRefusal: $nature === StepNature::HUMAN ? $check->markRefusal : null,
                blockReason: $nature === StepNature::OBSERVED ? $check->blockReason : null,
                pendingMessage: $check->pendingMessage,
                mark: $markRow !== null ? $this->mark($markRow, $names) : null,
                details: $check->details,
                targetYearId: $target
            );
        }

        $deskDate = $this->deskEncodingDate($context->targetYear);

        return new TransitionWorkflow(
            $this->phases($steps, $context, $deskDate),
            $steps,
            $deskDate,
            $this->lastPublicSwitch()
        );
    }

    /**
     * Mark or unmark one human step for the context's target year.
     *
     * NOT_MARKABLE for an observed step, an unknown key or a step of a
     * disabled module — never a human's to set. REFUSED when the step's
     * check refuses the mark right now (StepCheck::$markRefusal), asked
     * again here rather than trusted from the page the request came from.
     * Unmarking is never refused: it does not assert anything.
     */
    public function markStep(StepContext $context, string $stepKey, bool $done, ?int $userAccountId): StepMarkResult
    {
        if (!$this->isManualStep($stepKey)) {
            return StepMarkResult::notMarkable();
        }

        if (!$done) {
            $this->stepRepository->unmark($context->targetId(), $stepKey);

            return StepMarkResult::unmarked();
        }

        $refusal = $this->provider($stepKey)->check($context)->markRefusal;
        if ($refusal !== null) {
            return StepMarkResult::refused($refusal);
        }

        $this->stepRepository->mark($context->targetId(), $stepKey, $userAccountId);

        return StepMarkResult::marked();
    }

    /**
     * Whether a step key may be marked by hand at all: it must be a human
     * step, and its module (if it has one) must be enabled.
     */
    public function isManualStep(string $stepKey): bool
    {
        $definition = self::STEP_KEYS[$stepKey] ?? null;

        return $definition !== null && $definition[1] === StepNature::HUMAN && $this->isPresent($stepKey);
    }

    /**
     * The present step keys, in order. Steps of a disabled module are
     * filtered out here, before numbering, so the numbers a chief reads
     * are always 1..n over the steps their site actually has.
     *
     * @return list<string>
     */
    private function presentKeys(): array
    {
        return array_values(array_filter(array_keys(self::STEP_KEYS), fn(string $key): bool => $this->isPresent($key)));
    }

    private function isPresent(string $stepKey): bool
    {
        $module = self::STEP_MODULES[$stepKey] ?? null;

        return $module === null || in_array($module, $this->enabledModuleIds, true);
    }

    private function provider(string $stepKey): StepCheckProvider
    {
        return $this->checks[$stepKey]
            ?? throw new \LogicException("No step check provider wired for step '{$stepKey}'.");
    }

    private function state(StepNature $nature, StepCheck $check, bool $marked): StepState
    {
        if ($nature === StepNature::HUMAN) {
            return $marked ? StepState::DONE : StepState::TODO;
        }

        if ($check->observedDone === true) {
            return StepState::DONE;
        }

        return $check->blockReason !== null ? StepState::BLOCKED : StepState::TODO;
    }

    /**
     * Every action of the step, the primary one first and only once.
     *
     * @return list<\Core\ScoutYear\Step\StepAction>
     */
    private function actions(StepCheck $check): array
    {
        $actions = $check->actions;
        if ($check->primaryAction !== null && !in_array($check->primaryAction, $actions, true)) {
            array_unshift($actions, $check->primaryAction);
        }

        return $actions;
    }

    /**
     * @param array{done_at: string, done_by: ?int} $row
     * @param array<int, array{first_name: ?string, last_name: ?string}> $names
     */
    private function mark(array $row, array $names): StepMark
    {
        return new StepMark(
            DateInput::requireFromStorage($row['done_at'], 'scout_year_transition_steps.done_at', AppClock::zone()),
            $row['done_by'] !== null ? $this->formatName($names[$row['done_by']] ?? null) : null
        );
    }

    /**
     * The titles and descriptions of the steps — French, the page's own
     * words (mock-up of chantier #930).
     *
     * @return array{title: string, description: string}
     */
    private function text(string $key, StepContext $context): array
    {
        $target = $context->targetLabel();
        $public = $context->publicLabel();

        return match ($key) {
            'departures' => [
                'title' => "Départs de l'unité",
                'description' => "Chaque staff indique, sur la page Départs de l'unité, les animés de sa section qui ne "
                    . "reviendront pas en {$target}. Le repérage se remet à zéro tout seul au prochain import Desk.",
            ],
            'passage' => [
                'title' => 'Passages de branche',
                'description' => "Sur la page Passages de branche, choisissez la section d'arrivée de chaque animé qui "
                    . "quitte sa branche en {$target}.",
            ],
            'confirm_registrations' => [
                'title' => 'Nouvelles inscriptions',
                'description' => "Acceptez ou refusez les demandes d'inscription pour {$target}, puis renseignez la "
                    . "« section prévue » de chaque demande acceptée.",
            ],
            'desk_staff' => [
                'title' => 'Encoder les nouveaux staffs dans Desk',
                'description' => "Dans Desk, encodez les animateurs et animatrices qui rejoignent l'unité en {$target}, "
                    . 'ainsi que les changements de staff. Cette étape se passe hors du site.',
            ],
            'desk_members' => [
                'title' => 'Encoder les animés dans Desk',
                'description' => 'Dans Desk, reportez les décisions de la préparation : les nouvelles inscriptions, '
                    . 'les passages et les départs. Cette étape se passe hors du site.',
            ],
            'import' => [
                'title' => "Importer les données Desk pour {$target}",
                'description' => "Importez le fichier CSV Desk de {$target} depuis la page « Import Desk ». Les membres "
                    . "de {$target} deviennent alors disponibles.",
            ],
            'fees' => [
                'title' => "Vérifier les cotisations de {$target}",
                'description' => "Vérifiez les catégories de cotisation encodées dans Desk pour {$target}.",
            ],
            'preview' => [
                'title' => "Prévisualiser le site de {$target}",
                'description' => "Voyez le site tel qu'il apparaîtra en {$target}. La prévisualisation ne concerne que "
                    . "votre session ; elle ne vaut pas vérification : marquez l'étape quand vous avez relu.",
            ],
            'activate_staff' => [
                'title' => "Activer {$target} pour les staffs",
                'description' => "Les animateurs et intendants verront {$target} dès leur prochaine connexion, tandis que "
                    . "les animés et les visiteurs restent sur l'année courante ({$public}).",
            ],
            'ephemerides' => [
                'title' => "Éphémérides de l'année",
                'description' => "Encodez dans le calendrier les dates de {$target} : réunions, week-ends, camps, "
                    . 'réunions de staff.',
            ],
            'badges' => [
                'title' => 'Attribuer les badges',
                'description' => "Attribuez les badges de {$target} (trésorier·e, infirmier·e, référent·e·s…) depuis la "
                    . 'page Staffs et badges.',
            ],
            'trombinoscope' => [
                'title' => 'Photos individuelles des animateurs',
                'description' => "La page « Les animateurs » se reconstruit toute seule à partir de l'import Desk : "
                    . "vérifiez les photos et les responsables de section pour {$target}.",
            ],
            'staff_photos' => [
                'title' => 'Photos de groupe des staffs',
                'description' => "Chaque section a une photo de staff par année scoute. Tant que celle de {$target} "
                    . "n'est pas prise, le site continue d'afficher celle de l'année précédente.",
            ],
            'activate_public' => [
                'title' => "Activer {$target} pour tout le monde",
                'description' => "Bascule l'ensemble du site (visiteurs inclus) sur {$target} de façon permanente et "
                    . "désactive l'année du staff.",
            ],
            default => throw new \LogicException("No text for step '{$key}'."),
        };
    }

    /**
     * @param list<TransitionStep> $steps
     * @return list<TransitionPhase>
     */
    private function phases(array $steps, StepContext $context, string $deskDate): array
    {
        $deskPhaseReached = $context->now->format('Y-m-d') >= $deskDate;
        $definitions = [
            self::PHASE_PREPARATION => [
                'Préparation',
                'Idéalement avant le ' . $this->frenchDate($deskDate),
                null,
                !$deskPhaseReached,
            ],
            self::PHASE_DESK => [
                'Encodage et contrôles Desk',
                'Plutôt à partir du ' . $this->frenchDate($deskDate),
                null,
                $deskPhaseReached,
            ],
            self::PHASE_SITE => [
                'Mise à jour du site et publication',
                null,
                "Le site est à jour pour {$context->targetLabel()} : c'est typiquement au tour des staffs d'agir.",
                false,
            ],
        ];

        $phases = [];
        foreach ($definitions as $key => [$title, $subtitle, $note, $current]) {
            $phaseSteps = array_values(array_filter($steps, static fn(TransitionStep $s): bool => $s->phase === $key));
            // A phase whose every step belonged to a disabled module has
            // nothing left to show; rendering its heading over an empty
            // list would suggest the workflow lost a step rather than never
            // had one.
            if ($phaseSteps !== []) {
                $phases[] = new TransitionPhase($key, $title, $subtitle, $note, $current, $phaseSteps);
            }
        }

        return $phases;
    }

    /**
     * The latest switch for everyone, read from the journal entry the
     * controller writes — no new table. Its context carries the two
     * year ids, and their labels for an entry written since this read
     * exists; the ids are looked up when the labels are absent.
     */
    private function lastPublicSwitch(): ?PublicYearSwitch
    {
        $row = $this->journal->findLatestEvent('core', self::JOURNAL_PUBLIC_ACTIVATED);
        if ($row === null) {
            return null;
        }

        $context = is_string($row['context'] ?? null) ? json_decode($row['context'], true) : null;
        $context = is_array($context) ? $context : [];

        $toLabel = $this->yearLabel($context['new_year_label'] ?? null, $context['new_year_id'] ?? null);
        $switchedAt = DateInput::fromStorage(is_string($row['logged_at'] ?? null) ? $row['logged_at'] : null, AppClock::zone());
        if ($toLabel === null || $switchedAt === null) {
            return null;
        }

        $userId = isset($row['user_account_id']) ? (int) $row['user_account_id'] : null;
        $name = $userId !== null ? ($this->userAccountRepository->findNamesByIds([$userId])[$userId] ?? null) : null;

        return new PublicYearSwitch(
            $this->yearLabel($context['old_year_label'] ?? null, $context['old_year_id'] ?? null),
            $toLabel,
            $switchedAt,
            $this->formatName($name)
        );
    }

    private function yearLabel(mixed $label, mixed $id): ?string
    {
        if (is_string($label) && $label !== '') {
            return $label;
        }

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        $year = (int) $id > 0 ? $this->scoutYearService->findById((int) $id) : null;

        return $year !== null ? (string) $year['label'] : null;
    }

    /**
     * The indicative Desk-encoding date for one transition, as 'Y-m-d':
     * the configured MM-JJ placed in the calendar year the target scout
     * year starts in (15 August 2026 for the 2026-2027 transition). An
     * unparseable setting falls back to 15 August rather than throwing —
     * a page that refuses to render because a signpost is malformed would
     * be a far worse failure than a signpost pointing at the default.
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

        $parsed = DateInput::iso($date);
        if ($parsed === null) {
            return $date;
        }

        return (int) $parsed->format('j') . ' ' . $months[(int) $parsed->format('n')] . ' ' . $parsed->format('Y');
    }
}
