<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Support;
use Twig\Environment;

/**
 * The write half of an asset's "Réglages" page — its booking rules, its
 * tariff and its deposit rules, each block saving independently (§6.4).
 * Kept apart from Controller\RentalManagementController, which is already
 * the largest file in the module, and from
 * Controller\RentalConfigController, which administers the park rather than
 * any one asset.
 *
 * **`role_min: identified`, and that is not the protection.** These actions
 * used to sit at `admin`, which made a unit chief the only person who could
 * price the hall an intendant actually lets. A manager is explicitly not
 * required to be a chief (§6.3), so the route guard now grants almost
 * nothing and `Service\RentalAuthorizationService` is what stands between a
 * logged-in visitor and somebody else's tariff. It is re-checked at the top
 * of **every** action here, from the slug in the URL, and an asset the
 * visitor may not manage answers **404 rather than 403**: "this exists but
 * is not yours" is itself a disclosure about the unit's assets (§6.6).
 */
class RentalPricingController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private RentalPricingService $pricingService,
        private RentalAvailabilityService $availabilityService,
        private RentalAuthorizationService $authorizationService,
        private RentalAssetRepository $assetRepository,
        private ScoutYearService $scoutYearService,
        /**
         * Optional (§6.19): null on an installation without the Finance
         * module, where the payments block explains that instead of
         * offering a picker with nothing in it.
         */
        private ?RentalPaymentService $paymentService = null
    ) {
        parent::__construct($twig);
    }

    /**
     * POST /mes-locations/{slug}/reglages/tarif — the asset-level block: billing unit,
     * default rate and the billable minimum.
     *
     * @param array<string, string> $params
     */
    public function savePricing(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            // The minimum is one field with a mode, not two independent
            // ones: the spec says amount OR persons, and offering both as
            // free-standing inputs is how an operator ends up with both set
            // and the service refusing the save with no obvious cause.
            $minimumMode = (string) $request->getBody('minimum_mode', 'none');
            $minimumAmountCents = $minimumMode === 'amount'
                ? RentalPricingService::parseAmountToCents((string) $request->getBody('minimum_amount', ''))
                : null;
            $minimumPersons = $minimumMode === 'persons'
                ? self::optionalInt($request->getBody('minimum_persons'))
                : null;

            $this->pricingService->saveAssetPricing(
                $asset->id,
                (string) $request->getBody('billing_unit', ''),
                RentalPricingService::parseAmountToCents((string) $request->getBody('default_unit_price', '')),
                $minimumAmountCents,
                $minimumPersons,
                AuthSession::getUserAccountId()
            );

            return 'La tarification a été enregistrée.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/periode
     *
     * @param array<string, string> $params
     */
    public function addPeriod(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $this->pricingService->addPeriod(
                $asset->id,
                (string) $request->getBody('label', ''),
                (string) $request->getBody('start_date', ''),
                (string) $request->getBody('end_date', ''),
                $request->getBody('recurs_yearly') !== null
            );

            return 'La période a été ajoutée.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/periode-supprimer
     *
     * @param array<string, string> $params
     */
    public function deletePeriod(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $this->pricingService->deletePeriod($asset->id, (int) $request->getBody('period_id', 0));

            return 'La période a été supprimée.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/categorie
     *
     * @param array<string, string> $params
     */
    public function addCategory(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $this->pricingService->addCategory(
                $asset->id,
                (string) $request->getBody('label', ''),
                $request->getBody('is_default') !== null
            );

            return 'La catégorie a été ajoutée.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/categorie-supprimer
     *
     * @param array<string, string> $params
     */
    public function deleteCategory(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $this->pricingService->deleteCategory($asset->id, (int) $request->getBody('category_id', 0));

            return 'La catégorie a été supprimée.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/grille — the whole grid at once.
     *
     * @param array<string, string> $params
     */
    public function saveGrid(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $submitted = $request->getBody('grid', []);
            $cells = [];

            if (is_array($submitted)) {
                foreach ($submitted as $key => $value) {
                    // An empty cell is "no price here", not "free" — it must
                    // vanish from the grid so the default rate applies,
                    // rather than being stored as zero.
                    $cents = RentalPricingService::parseAmountToCents(is_string($value) ? $value : '');
                    if ($cents !== null) {
                        $cells[(string) $key] = $cents;
                    }
                }
            }

            $this->pricingService->saveGrid($asset->id, $cells);

            return 'La grille tarifaire a été enregistrée.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/frais
     *
     * @param array<string, string> $params
     */
    public function addFee(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $amount = RentalPricingService::parseAmountToCents((string) $request->getBody('amount', ''));

            $this->pricingService->addFee(
                $asset->id,
                (string) $request->getBody('label', ''),
                (string) $request->getBody('nature', ''),
                $amount ?? 0,
                Support::optionalString($request->getBody('meter_unit'))
            );

            return 'Le frais a été ajouté.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/frais-supprimer
     *
     * @param array<string, string> $params
     */
    public function deleteFee(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'tarification', function (RentalAsset $asset) use ($request): string {
            $this->pricingService->deleteFee($asset->id, (int) $request->getBody('fee_id', 0));

            return 'Le frais a été supprimé.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/regles — what a visitor may ASK for.
     *
     * Its own section, deliberately separate from pricing: constraints
     * decide whether a range may be requested, availability decides whether
     * the asset is free, and conflating them is how a free day inside the
     * notice window ends up shown as "occupé" (§6.7).
     *
     * @param array<string, string> $params
     */
    public function saveConstraints(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'regles', function (RentalAsset $asset) use ($request): string {
            $weekdays = $request->getBody('allowed_arrival_weekdays', []);

            $this->availabilityService->saveConstraints(
                $asset->id,
                max(0, (int) $request->getBody('min_nights', 0)),
                max(0, (int) $request->getBody('max_nights', 0)),
                max(0, (int) $request->getBody('min_notice_days', 0)),
                max(0, (int) $request->getBody('max_horizon_days', 0)),
                is_array($weekdays) ? $weekdays : [],
                self::optionalInt($request->getBody('max_persons')),
                max(0, (int) $request->getBody('buffer_nights', 0)),
                AuthSession::getUserAccountId()
            );

            return 'Les règles de réservation ont été enregistrées.';
        });
    }

    /**
     * POST /mes-locations/{slug}/reglages/paiements — the asset's money
     * configuration (§6.19, §6.20), minus the Finance account.
     *
     * Which account the money lands on is pinned by unit staff on
     * "Espace chefs d'U > Locations" and is deliberately not a field here —
     * see Controller\RentalConfigController::saveFinanceAccount() for why.
     * `saveManagedSettings()` reads the stored account back and carries it
     * across, so saving this form can never clear it.
     *
     * @param array<string, string> $params
     */
    public function savePayments(Request $request, array $params): Response
    {
        return $this->guarded($request, $params, 'paiements', function (RentalAsset $asset) use ($request): string {
            if ($this->paymentService === null) {
                throw new RentalException(
                    'Le module Finances n\'est pas actif : la configuration des paiements est indisponible.'
                );
            }

            $this->paymentService->saveManagedSettings($asset->id, new PaymentSettings(
                enabled: $request->getBody('payments_enabled') !== null,
                depositMode: DepositMode::tryFrom((string) $request->getBody('deposit_mode', 'none'))
                    ?? DepositMode::NONE,
                depositAmountCents: RentalPricingService::parseAmountToCents(
                    (string) $request->getBody('deposit_amount', '')
                ),
                depositPercentage: self::optionalInt($request->getBody('deposit_percentage')),
                depositDueDays: self::optionalInt($request->getBody('deposit_due_days')),
                balanceDueDays: self::optionalInt($request->getBody('balance_due_days')),
                securityDepositEnabled: $request->getBody('security_deposit_enabled') !== null,
                securityDepositAmountCents: RentalPricingService::parseAmountToCents(
                    (string) $request->getBody('security_deposit_amount', '')
                ),
                securityDepositDueDays: self::optionalInt($request->getBody('security_deposit_due_days'))
            ));

            return 'Configuration des paiements enregistrée.';
        });
    }

    /**
     * The shape every action here shares: CSRF, then **the per-asset
     * authorization check**, then the one operation, turning a
     * RentalException into a flash rather than a stack trace, and back to
     * the section of the asset's own settings page the operator came from.
     *
     * The asset comes from the slug in the URL, never from a body field: it
     * is the same resource the page itself is scoped to, and a request that
     * names an asset the visitor cannot manage is answered 404 before a
     * single write is attempted.
     *
     * @param array<string, string> $params
     * @param callable(RentalAsset): string $operation Returns the success message.
     */
    private function guarded(Request $request, array $params, string $anchor, callable $operation): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations/' . rawurlencode((string) ($params['slug'] ?? '')) . '/reglages#' . $anchor)) !== null) {
            return $guard;
        }

        $asset = $this->manageableAsset($params);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        try {
            FlashMessage::set('success', $operation($asset));
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/reglages#' . $anchor);
    }

    /**
     * The asset named by the URL, but only if the visitor may manage it.
     *
     * Same resolution and same 404-rather-than-403 answer as
     * Controller\RentalManagementController's own helper of the same name —
     * deliberately a second, three-line copy rather than a shared base
     * class, so this controller's authorization is readable in the file
     * that performs the writes rather than inherited from somewhere else.
     *
     * @param array<string, string> $params
     */
    private function manageableAsset(array $params): ?RentalAsset
    {
        $asset = $this->assetRepository->findBySlug((string) ($params['slug'] ?? ''));
        if ($asset === null) {
            return null;
        }

        return $this->authorizationService->canManageAsset(
            AuthSession::getEmail(),
            (int) $this->scoutYearService->getCurrentYear()['id'],
            $asset
        ) ? $asset : null;
    }

    /**
     * The simulator's own result, computed for whatever the operator typed
     * into it.
     *
     * Deliberately server-side and deliberately `quoteWithSettings()`: the
     * simulator exists as the main guard-rail against a wrong tariff, which
     * it can only be if it is provably the *same* engine that prices the
     * public page and the contract. A client-side re-implementation would be
     * a guard-rail against nothing.
     *
     * @param array<string, mixed> $query
     * @return array{request: \Modules\Rental\Pricing\PricingRequest, quote: \Modules\Rental\Pricing\PriceQuote}|null
     */
    public static function simulate(
        RentalPricingService $pricingService,
        PricingSettings $settings,
        array $query
    ): ?array {
        $arrival = isset($query['sim_arrival']) ? (string) $query['sim_arrival'] : '';
        $departure = isset($query['sim_departure']) ? (string) $query['sim_departure'] : '';

        // A simulation is a query string on the configuration page, so both
        // values are raw text. PricingRequest::nights() hands them to
        // DateTimeImmutable, which throws on anything that is not a date —
        // and simulate() runs inline while the template context is built, so
        // that throw used to take the whole configuration page down with a
        // 500. An unparseable simulation simply has no result.
        if (!Support::isDate($arrival) || !Support::isDate($departure)) {
            return null;
        }

        $request = new \Modules\Rental\Pricing\PricingRequest(
            arrivalDate: $arrival,
            departureDate: $departure,
            persons: max(0, (int) ($query['sim_persons'] ?? 0)),
            units: max(1, (int) ($query['sim_units'] ?? 1)),
            rooms: max(1, (int) ($query['sim_rooms'] ?? 1)),
            renterCategoryId: isset($query['sim_category']) && $query['sim_category'] !== ''
                ? (int) $query['sim_category']
                : null
        );

        return [
            'request' => $request,
            'quote' => $pricingService->quoteWithSettings($settings, $request),
        ];
    }

    private static function optionalInt(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (int) $value;
    }
}
