<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Exception\UserFacingMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\FeeCategoryRepository;
use Core\Journal\JournalService;
use Core\Member\Household\HouseholdService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Fees\HouseholdCategoryLabel;
use Modules\Fees\Repository\FeesImportRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Service\FederalScaleLookupService;
use Modules\Fees\Service\FeeAccuracyService;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\ShippedScaleService;
use Modules\Fees\Service\TariffConfigurationService;
use Modules\Fees\Support\SuggestedScale;
use Modules\Fees\Value\FeeAccuracyReport;
use Modules\Fees\Value\HouseholdReview;
use Modules\Fees\Value\HouseholdReviewMember;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Twig\Environment;

/**
 * « Justesse des tarifs » — the encoded fee category against the number of
 * people at the same address.
 *
 * Four views of one report, selected by `?vue=`, each a real URL so the
 * page works with no JavaScript and a treasurer can keep one open in a tab
 * next to Desk.
 */
class FeeAccuracyController extends AbstractController
{
    private const PATH = '/admin/fees/tarifs';

    private const VIEWS = [
        'corriger' => 'À corriger dans Desk',
        'prevoir' => 'À prévoir',
        'ignores' => 'Ignorés',
        'sans-adresse' => 'Sans adresse',
    ];

    public function __construct(
        protected Environment $twig,
        private FeeAccuracyService $accuracy,
        private HouseholdTariffService $tariffs,
        private IgnoredHouseholdRepository $ignoredHouseholds,
        private HouseholdService $households,
        private FeesImportRepository $imports,
        private FeeCategoryRepository $feeCategories,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journal,
        private FederalScaleLookupService $federalScale,
        private ShippedScaleService $shippedScale,
        private TariffConfigurationService $configuration
    ) {
    }

    /**
     * GET /admin/fees/tarifs
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $year = $this->effectiveYear();
        $report = $this->accuracy->report($year->id);
        $view = $this->resolveView($request->getQuery('vue'));

        $declined = SuggestedScale::takeDeclined();
        $draft = SuggestedScale::draftFor($year->label);
        $proposal = SuggestedScale::peek($year->label);
        $suggested = $declined || $draft !== null || $proposal !== null
            ? null : $this->shippedScale->suggestionFor($year->label);

        return $this->render('@fees/accuracy.html.twig', [
            'scout_year_label' => $year->label,
            'last_import_at' => $this->imports->findLastImportAt($year->id),
            'report' => $report,
            'view' => $view,
            'views' => self::VIEWS,
            'view_counts' => [
                'corriger' => count($report->toCorrect),
                'prevoir' => count($report->upcoming),
                'ignores' => count($report->ignored),
                'sans-adresse' => $report->withoutAddressCount(),
            ],
            'category_labels' => HouseholdCategoryLabel::all(),
            'tariff_panel' => $this->tariffs->panel(),
            'has_any_amount' => $this->tariffs->hasAnyAmount(),
            'fee_categories' => $this->feeCategories->findAll(),
            // The lookup button exists only while a CHEAP-tier model is reachable.
            'scale_lookup_available' => $this->federalScale->isAvailable(),
            'scale_suggestion' => $suggested,
            'scale_proposal' => $proposal,
            'scale_draft' => $draft,
            'scale_year' => FederalScaleLookupService::normalizeYear($year->label),
            'guest_period' => $this->configuration->fields(),
        ]);
    }

    /**
     * POST /admin/fees/tarifs/ignorer — set one household aside, with the
     * reason the chef d'unité gave.
     *
     * @param array<string, string> $params
     */
    public function ignore(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH)) !== null) {
            return $guard;
        }

        $year = $this->effectiveYear();
        $blindIndex = trim((string) $request->getBody('address_blind_index', ''));
        $reason = trim((string) $request->getBody('reason', ''));

        if ($reason === '') {
            FlashMessage::set('error', 'Indiquez pourquoi ce foyer doit être ignoré.');

            return $this->redirect(self::PATH);
        }

        $household = $this->households->householdsForYear($year->id)[$blindIndex] ?? null;
        if ($household === null) {
            // An address that is not one of this year's households: a stale
            // page, or a hand-made request. Same answer either way — the
            // screen never confirms which.
            FlashMessage::set('error', "Ce foyer n'existe plus pour cette année scoute.");

            return $this->redirect(self::PATH);
        }

        $this->ignoredHouseholds->ignore(
            $year->id,
            $blindIndex,
            FeeAccuracyService::compositionHash($household),
            mb_substr($reason, 0, 255),
            AuthSession::getUserAccountId()
        );

        // The reason is never journaled: it is free text about a family's
        // arrangements (SECURITY.md §11).
        $this->journal->log(
            'fees',
            'fees_household_ignored',
            'info',
            'Foyer ignoré dans la vérification des tarifs',
            ['scout_year_id' => $year->id, 'member_count' => $household->deskSize()],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Foyer ignoré.');

        return $this->redirect(self::PATH . '?vue=ignores');
    }

    /**
     * POST /admin/fees/tarifs/reprendre — put an ignored household back in
     * the verification.
     *
     * @param array<string, string> $params
     */
    public function unignore(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH . '?vue=ignores')) !== null) {
            return $guard;
        }

        $year = $this->effectiveYear();
        $blindIndex = trim((string) $request->getBody('address_blind_index', ''));
        $this->ignoredHouseholds->forget($year->id, $blindIndex);

        $this->journal->log(
            'fees',
            'fees_household_unignored',
            'info',
            'Foyer remis dans la vérification des tarifs',
            ['scout_year_id' => $year->id],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Foyer remis dans la vérification.');

        return $this->redirect(self::PATH . '?vue=ignores');
    }

    /**
     * POST /admin/fees/tarifs/bareme — the only write of the whole scale.
     *
     * @param array<string, string> $params
     */
    public function saveTariffs(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH)) !== null) {
            return $guard;
        }
        $year = $this->effectiveYear();
        try {
            $this->configuration->save($year->label, $request->getBodyAll());
        } catch (\Throwable $error) {
            SuggestedScale::rememberDraft($year->label, $request->getBodyAll());
            FlashMessage::set('error', UserFacingMessage::from(
                $error, 'Le barème n’a pas pu être enregistré. Vérifiez les valeurs puis réessayez.'
            ));

            return $this->redirect(self::PATH);
        }
        SuggestedScale::clear();
        $this->journal->log(
            'fees', 'fees_tariff_scale_saved', 'info', 'Barème des cotisations enregistré',
            ['scout_year_id' => $year->id], AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Barème enregistré.');

        return $this->redirect(self::PATH);
    }

    /**
     * POST /admin/fees/tarifs/bareme/chercher — retains the draft and offers values separately.
     *
     * @param array<string, string> $params
     */
    public function lookupTariffs(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH)) !== null) {
            return $guard;
        }
        $year = $this->effectiveYear();
        SuggestedScale::rememberDraft($year->label, $request->getBodyAll());
        if (!$this->federalScale->isAvailable()) {
            FlashMessage::set('error', 'Aucun connecteur IA n’est configuré : la recherche n’est pas disponible.');

            return $this->redirect(self::PATH);
        }
        try {
            TariffConfigurationService::requireFormYear($request->getBody('scale_year'), $year->label);
        } catch (\Core\Config\SettingException $error) {
            FlashMessage::set('error', $error->getMessage());

            return $this->redirect(self::PATH);
        }
        $lookup = $this->federalScale->lookup($year->label, AuthSession::getUserAccountId());
        if (!$lookup->isFound()) {
            SuggestedScale::decline();
            FlashMessage::set('error', $lookup->message);
        } else {
            SuggestedScale::set($lookup);
            FlashMessage::set('success', $lookup->message);
        }

        return $this->redirect(self::PATH);
    }

    /**
     * POST /admin/fees/tarifs/bareme/appliquer — fills fields, never saves.
     *
     * @param array<string, string> $params
     */
    public function applyTariffs(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH)) !== null) {
            return $guard;
        }
        $year = $this->effectiveYear();
        try {
            $mode = $request->getBody('apply_scale');
            $draft = in_array($mode, ['amounts', 'all'], true)
                ? SuggestedScale::apply(
                    $year->label, $request->getBody('proposal_token'), $request->getBodyAll(), $mode === 'all'
                ) : null;
            if ($draft === null) {
                FlashMessage::set('error', 'Cette proposition a expiré ou a été remplacée. '
                    . 'Vérifiez les valeurs affichées avant de continuer.');
            } else {
                FlashMessage::set(
                    'success', 'Proposition reprise dans le formulaire. Enregistrez le barème pour la conserver.'
                );
            }
        } catch (\Core\Config\SettingException $error) {
            FlashMessage::set('error', $error->getMessage());
        }

        return $this->redirect(self::PATH);
    }

    /**
     * GET /admin/fees/tarifs/export — the same rows, in the same order, as
     * the screen shows.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $year = $this->effectiveYear();
        $report = $this->accuracy->report($year->id);
        $xlsx = $this->buildXlsx($report);

        $this->journal->log(
            'fees',
            'fees_accuracy_exported',
            'info',
            'Export de la justesse des tarifs',
            [
                'scout_year_id' => $year->id,
                'to_correct' => count($report->toCorrect),
                'upcoming' => count($report->upcoming),
            ],
            AuthSession::getUserAccountId()
        );

        return \Core\Http\SpreadsheetResponse::download($xlsx, 'justesse-des-tarifs.xlsx');
    }

    private function buildXlsx(FeeAccuracyReport $report): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $columns = [
            'Onglet', 'Adresse', 'Membres dans Desk', 'Tarif attendu',
            'Membre', 'Tarif encodé', 'Verdict', 'Écart (€)', 'Déclencheur',
        ];
        foreach ($columns as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        $rowNum = 2;
        foreach ([
            'À corriger dans Desk' => $report->toCorrect,
            'À prévoir' => $report->upcoming,
            'Ignorés' => $report->ignored
        ] as $tab => $reviews) {
            foreach ($reviews as $review) {
                foreach ($review->members as $member) {
                    // Every text column is written explicitly as a string:
                    // a name or a Desk label starting with '=' must not
                    // become a live formula in the treasurer's spreadsheet
                    // (SECURITY.md §23).
                    $sheet->setCellValueExplicit([1, $rowNum], $tab, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit([2, $rowNum], $review->addressLabel, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit([3, $rowNum], (string) $review->deskSize, DataType::TYPE_NUMERIC);
                    $sheet->setCellValueExplicit(
                        [4, $rowNum],
                        HouseholdCategoryLabel::for($review->expectedCategory),
                        DataType::TYPE_STRING
                    );
                    $sheet->setCellValueExplicit(
                        [5, $rowNum],
                        trim($member->firstName . ' ' . $member->lastName),
                        DataType::TYPE_STRING
                    );
                    $sheet->setCellValueExplicit(
                        [6, $rowNum],
                        $member->encodedFeeCategoryLabel ?? '',
                        DataType::TYPE_STRING
                    );
                    $sheet->setCellValueExplicit([7, $rowNum], self::verdict($review, $member), DataType::TYPE_STRING);
                    if ($review->differenceCents !== null) {
                        $sheet->setCellValueExplicit(
                            [8, $rowNum],
                            (string) ($review->differenceCents / 100),
                            DataType::TYPE_NUMERIC
                        );
                    }
                    $sheet->setCellValueExplicit([9, $rowNum], self::trigger($review), DataType::TYPE_STRING);
                    $rowNum++;
                }
            }
        }

        return $spreadsheet;
    }

    private static function verdict(HouseholdReview $review, HouseholdReviewMember $member): string
    {
        if (!$member->comparable) {
            return 'Hors tarif de foyer';
        }

        return $member->matches($review->expectedCategory) ? 'Conforme' : 'À corriger';
    }

    private static function trigger(HouseholdReview $review): string
    {
        if (!$review->willChange) {
            return '';
        }

        $parts = [];
        foreach ($review->leavingMembers() as $member) {
            $parts[] = trim($member->firstName . ' ' . $member->lastName) . ' — départ annoncé';
        }
        if ($review->incomingRegistrations > 0) {
            $parts[] = $review->incomingRegistrations . ' inscription(s) acceptée(s)';
        }

        return implode(' ; ', $parts);
    }

    private function resolveView(mixed $requested): string
    {
        $requested = is_string($requested) ? $requested : '';

        return isset(self::VIEWS[$requested]) ? $requested : 'corriger';
    }

    private function effectiveYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        $role = Role::fromString(AuthSession::getRole());

        return $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
    }

}
