<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPricingService;
use Twig\Environment;

/**
 * The pricing half of "Espace chefs d'U > Locations" — the four
 * configuration blocks of module spec §6.10, each saving independently
 * (§6.4). Kept apart from Controller\RentalConfigController so neither file
 * becomes the place where every asset setting lives; both render into the
 * same page, which Controller\RentalConfigController::index() assembles.
 *
 * Every action here re-reads its asset id from the request and scopes its
 * write by it, so an id from a stale form can only ever touch the asset it
 * names — never another one.
 */
class RentalPricingController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private RentalPricingService $pricingService
    ) {
        parent::__construct($twig);
    }

    /**
     * POST /admin/locations/pricing — the asset-level block: billing unit,
     * default rate and the billable minimum.
     *
     * @param array<string, string> $params
     */
    public function savePricing(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
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
                $assetId,
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
     * POST /admin/locations/pricing/period
     *
     * @param array<string, string> $params
     */
    public function addPeriod(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
            $this->pricingService->addPeriod(
                $assetId,
                (string) $request->getBody('label', ''),
                (string) $request->getBody('start_date', ''),
                (string) $request->getBody('end_date', ''),
                $request->getBody('recurs_yearly') !== null
            );

            return 'La période a été ajoutée.';
        });
    }

    /**
     * POST /admin/locations/pricing/period-delete
     *
     * @param array<string, string> $params
     */
    public function deletePeriod(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
            $this->pricingService->deletePeriod($assetId, (int) $request->getBody('period_id', 0));

            return 'La période a été supprimée.';
        });
    }

    /**
     * POST /admin/locations/pricing/category
     *
     * @param array<string, string> $params
     */
    public function addCategory(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
            $this->pricingService->addCategory(
                $assetId,
                (string) $request->getBody('label', ''),
                $request->getBody('is_default') !== null
            );

            return 'La catégorie a été ajoutée.';
        });
    }

    /**
     * POST /admin/locations/pricing/category-delete
     *
     * @param array<string, string> $params
     */
    public function deleteCategory(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
            $this->pricingService->deleteCategory($assetId, (int) $request->getBody('category_id', 0));

            return 'La catégorie a été supprimée.';
        });
    }

    /**
     * POST /admin/locations/pricing/grid — the whole grid at once.
     *
     * @param array<string, string> $params
     */
    public function saveGrid(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
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

            $this->pricingService->saveGrid($assetId, $cells);

            return 'La grille tarifaire a été enregistrée.';
        });
    }

    /**
     * POST /admin/locations/pricing/fee
     *
     * @param array<string, string> $params
     */
    public function addFee(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
            $amount = RentalPricingService::parseAmountToCents((string) $request->getBody('amount', ''));

            $this->pricingService->addFee(
                $assetId,
                (string) $request->getBody('label', ''),
                (string) $request->getBody('nature', ''),
                $amount ?? 0,
                self::optionalString($request->getBody('meter_unit'))
            );

            return 'Le frais a été ajouté.';
        });
    }

    /**
     * POST /admin/locations/pricing/fee-delete
     *
     * @param array<string, string> $params
     */
    public function deleteFee(Request $request, array $params): Response
    {
        return $this->guarded($request, function (int $assetId) use ($request): string {
            $this->pricingService->deleteFee($assetId, (int) $request->getBody('fee_id', 0));

            return 'Le frais a été supprimé.';
        });
    }

    /**
     * The shape every action here shares: CSRF, asset id, run, turn a
     * RentalException into a flash rather than a stack trace, and go back to
     * the asset's own pricing section.
     *
     * @param callable(int): string $operation Returns the success message.
     */
    private function guarded(Request $request, callable $operation): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
        }

        $assetId = (int) $request->getBody('asset_id', 0);

        try {
            FlashMessage::set('success', $operation($assetId));
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/admin/locations?asset_id=' . $assetId . '#tarification');
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

        if ($arrival === '' || $departure === '') {
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

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
