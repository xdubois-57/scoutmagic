<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Service\DateInput;
use Core\View\MonthGrid\DayState;
use Core\View\MonthGrid\DayStateGridBuilder;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalPricingService;
use Twig\Environment;

/**
 * The public face of the module: the "Locations" index and one page per
 * asset, with its availability calendar and live price estimate.
 * `role_min: public`, so everything here is readable by an anonymous visitor
 * and the confidentiality rules are absolute rather than configurable.
 *
 * **Never rendered here** (§6.6): a manager's name, phone or email; the
 * asset's emergency phone; anything at all about a renter or a booking; a
 * price actually paid; a document; an access code; an internal comment. The
 * calendar shows *that* a day is taken and never *why* — a booking, a
 * temporary hold and a manual block are one indistinguishable state
 * (§6.7/§6.14).
 *
 * The "Gérer ce bien" button is rendered conditionally, and that condition
 * is **presentation only** — the managed space re-checks authority
 * server-side on every request (ARCHITECTURE.md §12).
 */
class RentalPublicController extends AbstractController
{
    /**
     * A visitor may never page backwards past the current month (§6.7), and
     * this caps how far forward they may go in one page load — a bound on
     * the query, not a booking rule (that is the configurable horizon).
     */
    private const MAX_MONTHS_AHEAD = 36;

    public function __construct(
        Environment $twig,
        private RentalAssetRepository $assetRepository,
        private RentalAuthorizationService $authorizationService,
        private ScoutYearService $scoutYearService,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService,
        private DayStateGridBuilder $gridBuilder
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /locations — every public asset, pinned to the menu or not.
     *
     * This page exists as soon as one public asset does. That is not a
     * detail: an asset that is public but not pinned has no menu entry of
     * its own, so without this index it would be reachable only by someone
     * who already knew its URL (§6.2).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@rental/public/index.html.twig', [
            'assets' => $this->assetRepository->findAllPublic(),
        ]);
    }

    /**
     * GET /locations/{slug}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $asset = $this->assetRepository->findBySlug((string) ($params['slug'] ?? ''));

        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $email = AuthSession::getEmail();
        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $canManage = $this->authorizationService->canManageAsset($email, $scoutYearId, $asset);

        // A non-public or archived asset stays reachable by its own
        // managers — that is how they get to the managed space for an asset
        // they have deliberately taken off the public site — and is a plain
        // 404 for everyone else. Not a 403: telling an anonymous visitor
        // "this exists but you may not see it" is itself a disclosure.
        if (!$asset->isPubliclyVisible() && !$canManage) {
            return new Response('Not Found', 404);
        }

        $today = new \DateTimeImmutable('today');
        $pricing = $this->pricingService->loadSettings($asset->id);
        $constraints = $this->availabilityService->constraintsFor($asset->id);

        [$year, $month] = $this->resolveMonth($request, $today);
        $selection = $this->resolveSelection($request);

        $states = $this->availabilityService->monthDayStates(
            $asset,
            $pricing->billingUnit,
            $year,
            $month,
            $today,
            $selection
        );

        return $this->render('@rental/public/show.html.twig', array_merge(
            $this->fragmentContext($asset, $request, $today),
            [
                'can_manage' => $canManage,
                'breadcrumb_current' => $asset->name,
                'constraints' => $constraints,
                'editable_prefix' => 'rental_asset_' . $asset->id,
            ]
        ));
    }

    /**
     * GET /locations/{slug}/apercu — the calendar and the estimate, as HTML
     * fragments, for the page that is already open.
     *
     * Tapping a day and pressing « Estimer » used to reload the whole page:
     * the visitor lost their scroll position, the page flashed, and on a
     * phone the calendar they had just tapped jumped out from under their
     * thumb. This returns the two blocks that actually change, rendered by
     * the same Twig partials the full page uses — so there is still exactly
     * ONE implementation of availability and pricing, and it is still the
     * server's. The client never decides a day is free or what a stay costs;
     * it swaps in what the server answered.
     *
     * Everything the full page checks, this checks identically: the same
     * lookup, the same 404 for an asset that is not publicly visible, and
     * the same query-parameter clamping. A fragment endpoint that trusted
     * its caller would be a way around the page's own rules.
     *
     * @param array<string, string> $params
     */
    public function fragment(Request $request, array $params): Response
    {
        $asset = $this->assetRepository->findBySlug((string) ($params['slug'] ?? ''));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        if (
            !$asset->isPubliclyVisible()
            && !$this->authorizationService->canManageAsset(AuthSession::getEmail(), $scoutYearId, $asset)
        ) {
            return new Response('Not Found', 404);
        }

        $context = $this->fragmentContext($asset, $request, new \DateTimeImmutable('today'));

        return $this->json([
            'calendar' => $this->renderToString('@rental/public/_calendar.html.twig', $context),
            'estimate' => $this->renderToString('@rental/public/_estimate.html.twig', $context),
        ]);
    }

    /**
     * Everything the calendar and the estimate need, built once.
     *
     * Shared by the full page and by the fragment endpoint precisely so the
     * two can never answer differently — a fragment that recomputed its own
     * month or its own selection would drift from the page it is replacing
     * part of.
     *
     * @return array<string, mixed>
     */
    private function fragmentContext(
        RentalAsset $asset,
        Request $request,
        \DateTimeImmutable $today
    ): array {
        $pricing = $this->pricingService->loadSettings($asset->id);
        [$year, $month] = $this->resolveMonth($request, $today);
        $selection = $this->resolveSelection($request);

        $states = $this->availabilityService->monthDayStates(
            $asset,
            $pricing->billingUnit,
            $year,
            $month,
            $today,
            $selection
        );

        return [
            'asset' => $asset,
            'billing_unit' => $pricing->billingUnit,
            // With no rate configured at all, the estimate block offers
            // "tarif sur demande" instead of an empty table adding up to
            // 0,00 € — a figure the unit would have to walk back, shown as
            // if it were a price.
            'has_tariff' => $pricing->hasAnyRate(),
            // Whether « Estimer » was actually pressed (the form carries a
            // hidden marker). Without it, submitting with no dates re-rendered
            // an identical page — for the visitor, a button that does nothing.
            'estimate_requested' => (string) $request->getQuery('estimate', '') === '1',
            'categories' => $pricing->categories,
            'weeks' => $this->gridBuilder->build(
                $year,
                $month,
                $states,
                // Every unmapped day still needs an accessible label; the
                // calculator maps the whole rendered window, so this is a
                // belt-and-braces default rather than a normal path.
                new DayState(DayState::STATE_UNSELECTABLE, 'Indisponible'),
                $today
            ),
            'calendar_month' => sprintf('%04d-%02d', $year, $month),
            'calendar_label' => self::monthLabel($year, $month),
            'previous_month' => $this->previousMonth($year, $month, $today),
            'next_month' => $this->nextMonth($year, $month, $today),
            'selection' => $selection,
            'estimate' => $this->buildEstimate($asset, $request, $selection, $today),
            // Echoed back so the summary form keeps what the visitor typed
            // across a calendar tap — losing the head count on every tap
            // would make the two halves of the page fight each other.
            'form_persons' => (int) $request->getQuery('persons', 0) ?: '',
            'form_units' => max(1, (int) $request->getQuery('units', 1)),
            'form_category' => (string) $request->getQuery('category', ''),
        ];
    }

    /**
     * The month to render.
     *
     * Clamped at both ends: **a visitor can never go into the past** (§6.7),
     * and cannot page arbitrarily far forward either. Clamping here rather
     * than only hiding the arrow matters — the month is a query parameter,
     * and a hidden button is not a boundary.
     *
     * @return array{0: int, 1: int}
     */
    private function resolveMonth(Request $request, \DateTimeImmutable $today): array
    {
        $requested = (string) $request->getQuery('month', '');
        $current = $today->modify('first day of this month');

        if (preg_match('/^(\d{4})-(\d{2})$/', $requested, $matches) !== 1) {
            return [(int) $current->format('Y'), (int) $current->format('n')];
        }

        $candidate = DateInput::iso($matches[1] . '-' . $matches[2] . '-01');
        if ($candidate === null) {
            return [(int) $current->format('Y'), (int) $current->format('n')];
        }

        $candidate = $candidate->setTime(0, 0);
        $latest = $current->modify('+' . self::MAX_MONTHS_AHEAD . ' months');

        if ($candidate < $current) {
            $candidate = $current;
        } elseif ($candidate > $latest) {
            $candidate = $latest;
        }

        return [(int) $candidate->format('Y'), (int) $candidate->format('n')];
    }

    /**
     * `Y-m` for the previous month, or **null at the current month** so the
     * template renders a disabled arrow rather than a link into the past.
     */
    private function previousMonth(int $year, int $month, \DateTimeImmutable $today): ?string
    {
        $current = $today->modify('first day of this month');
        $shown = DateInput::firstOfMonth($year, $month);

        if ($shown <= $current) {
            return null;
        }

        return $shown->modify('-1 month')->format('Y-m');
    }

    private function nextMonth(int $year, int $month, \DateTimeImmutable $today): ?string
    {
        $shown = DateInput::firstOfMonth($year, $month);
        $latest = $today->modify('first day of this month')->modify('+' . self::MAX_MONTHS_AHEAD . ' months');

        if ($shown >= $latest) {
            return null;
        }

        return $shown->modify('+1 month')->format('Y-m');
    }

    /**
     * The visitor's current selection, as `[arrival, departure|null]`.
     *
     * Two taps: the first sets the arrival, the second the departure. A
     * departure before the arrival is treated as the start of a **new**
     * selection rather than an error — tapping an earlier day is how a
     * visitor changes their mind, not a mistake to scold them for.
     *
     * @return array{0: string, 1: string|null}|null
     */
    private function resolveSelection(Request $request): ?array
    {
        $arrival = self::dateOrNull((string) $request->getQuery('arrival', ''));
        if ($arrival === null) {
            return null;
        }

        $departure = self::dateOrNull((string) $request->getQuery('departure', ''));
        if ($departure !== null && $departure < $arrival) {
            return [$departure, null];
        }

        return [$arrival, $departure];
    }

    /**
     * The price estimate for the current selection, plus whatever stops it
     * from being a firm figure.
     *
     * Runs through the very same engine as the configuration simulator and
     * the contract. When something needed is still missing — no head count
     * yet, no category chosen — the quote reports it and the template says
     * "dès X €" rather than a number it would have to walk back (§6.7).
     *
     * @param array{0: string, 1: string|null}|null $selection
     * @return array{quote: \Modules\Rental\Pricing\PriceQuote, errors: string[]}|null
     */
    private function buildEstimate(
        RentalAsset $asset,
        Request $request,
        ?array $selection,
        \DateTimeImmutable $today
    ): ?array {
        if ($selection === null || $selection[1] === null) {
            return null;
        }

        $persons = max(0, (int) $request->getQuery('persons', 0));
        $units = max(1, (int) $request->getQuery('units', 1));
        $categoryId = (string) $request->getQuery('category', '');

        $pricingRequest = new PricingRequest(
            arrivalDate: $selection[0],
            departureDate: $selection[1],
            persons: $persons,
            units: $units,
            rooms: 1,
            renterCategoryId: $categoryId !== '' ? (int) $categoryId : null
        );

        $pricing = $this->pricingService->loadSettings($asset->id);

        return [
            'quote' => $this->pricingService->quoteWithSettings($pricing, $pricingRequest),
            // Availability and constraints are reported separately from the
            // price: a range can be perfectly priceable and still not
            // bookable, and merging the two would tell a visitor the wrong
            // thing about which one to fix.
            'errors' => $this->availabilityService->validateRange(
                $asset,
                $pricing->billingUnit,
                DateInput::requireFromStorage($selection[0], 'the selected arrival date'),
                DateInput::requireFromStorage($selection[1], 'the selected departure date'),
                $units,
                $today,
                $persons > 0 ? $persons : null
            ),
        ];
    }

    private static function dateOrNull(string $value): ?string
    {
        return DateInput::isoStringOrNull($value);
    }

    /**
     * "juillet 2027" — French month names, written out rather than pulled
     * from `strftime()`/`IntlDateFormatter`: neither is guaranteed to have a
     * French locale installed on the shared hosting this project targets,
     * and a calendar heading reading "July" on some servers and "juillet" on
     * others is the kind of bug nobody reproduces locally.
     */
    public static function monthLabel(int $year, int $month): string
    {
        $names = [
            1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
        ];

        return ($names[$month] ?? '') . ' ' . $year;
    }
}
