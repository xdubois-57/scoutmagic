<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberDirectoryEntry;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Rental\Mail\MailboxSelection;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Support;
use Twig\Environment;

/**
 * "Espace chefs d'U > Locations" (`role_min: admin`) — the administration of
 * the **park**: which assets exist, who manages each one, and the handful of
 * things that are not a property of a single asset (the watched mailboxes,
 * the Finance account an asset's money lands on).
 *
 * Deliberately NOT where an asset is *configured*, and no longer where it is
 * operated. There is exactly one authority in this module — "manager of this
 * asset" — and unit staff hold it over every asset by virtue of their
 * function, never through a stored grant (Service\RentalAuthorizationService).
 * So an asset's booking rules, its tariff and its deposit rules live in the
 * asset's own managed space alongside its bookings, reachable by the people
 * who actually run it, and unit staff reach them there like any other
 * manager rather than through a second, admin-only screen.
 *
 * **The one field that did not follow, and why.** Which Finance account an
 * asset's money is expected on stays here: `getConfiguredAccounts()` hands
 * back every active account with its IBAN and does not filter by
 * `role_min_view`, and a manager may be a parent or a former leader — see
 * saveFinanceAccount() below.
 *
 * **Why "Espace chefs d'U" and not "Configuration".** The intended audience
 * is the unit chief, i.e. `admin`. The `configuration` menu's own minimum is
 * `superadmin`, and `ModuleManifest` rejects, fail-safe, any module route
 * more permissive than its menu — so a module page cannot sit there at
 * `admin` at all. (`Core\Maintenance` appears to do exactly that, but it is a
 * core route, not a manifest-validated one; ARCHITECTURE.md §3 documents it
 * as the single exception.) `espace_admin`'s minimum IS `admin`, so it is
 * where an admin-level configuration page belongs without weakening that
 * guard for every other module.
 *
 * **Every section saves independently.** Each `save*` action below owns a
 * disjoint set of fields and writes only those. A single two-hundred-field
 * form is the thing this screen is designed against: it makes a partial
 * post silently blank fields the operator never saw.
 */
class RentalConfigController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private RentalAssetRepository $assetRepository,
        private RentalAssetService $assetService,
        private RentalManagerService $managerService,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        /**
         * Optional (§6.19): null on an installation without the Finance
         * module, where the payments section explains that instead of
         * offering a picker with nothing in it.
         */
        private ?RentalPaymentService $paymentService = null,
        /**
         * Optional (§7.4): null without the `inbound_mail` module. Nothing
         * in this controller ever reaches a mailbox — it only stores which
         * of the already-configured ones this module listens to.
         */
        private ?MailboxSelection $mailboxSelection = null,
        /**
         * Read here for one thing only: flagging a public asset that has no
         * rate at all, so a chief learns it from this page rather than from
         * a visitor met by "Tarif sur demande". Nullable only so the
         * controller stays constructible in tests that do not care about
         * it — the module always wires one.
         */
        private ?RentalPricingService $pricingService = null
    ) {
        parent::__construct($twig);
    }

    /**
     * Whether a real cron has run recently (§6.29).
     *
     * `cron_last_run` is stamped only by `public/cron.php`, never by a web
     * request — which is exactly what makes it able to tell a real crontab
     * from the request-driven scheduler that stands in for one.
     */
    private static function cronDetected(SettingService $settingService): bool
    {
        $lastRun = (int) ($settingService->get('cron_last_run') ?: 0);

        return $lastRun > 0 && (time() - $lastRun) < 600;
    }

    /**
     * POST /admin/locations/courrier — which mailboxes feed this module
     * (§7.4).
     *
     * Storing ids and nothing else is the whole of it: a manager never sees
     * or supplies a host, an account or a password here, and this action
     * has no way to reach one.
     *
     * @param array<string, string> $params
     */
    public function saveMailboxes(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/locations')) !== null) {
            return $guard;
        }

        if ($this->mailboxSelection === null) {
            return new Response('Not Found', 404);
        }

        $submitted = $request->getBody('mailbox_ids', []);
        $this->mailboxSelection->save(is_array($submitted) ? $submitted : []);

        FlashMessage::set('success', 'Boîtes surveillées enregistrées.');

        return $this->redirect('/admin/locations#courrier');
    }

    /**
     * GET /admin/locations
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $assets = $this->assetRepository->findAll();
        $selected = $this->resolveSelectedAsset($request, $assets);
        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        return $this->render('@rental/config/index.html.twig', [
            'assets' => $assets,
            'selected_asset' => $selected,
            'managers' => $selected !== null
                ? $this->managerService->listManagersForAsset($selected->id, $scoutYearId, false)
                : [],
            // The whole eligible roster, rendered as a plain <select> the
            // search box replaces client-side. A no-JS visitor keeps a
            // working control and the POST is identical either way —
            // Modules\Groups' invite search sets the same precedent.
            'candidates' => $selected !== null
                ? $this->managerService->listCandidates($scoutYearId)
                : [],
            'manager_minimum_age' => $this->managerService->minimumAge(),
            'type_suggestions' => $this->typeSuggestions(),
            // Ids of the public, live assets that have no rate configured at
            // all — the one setup gap this page could not show and a visitor
            // meets first. The chip picker badges them and the selected
            // asset gets a warning linking to its own tariff page.
            'tariff_missing_ids' => $this->tariffMissingIds($assets),
            'billing_units' => \Modules\Rental\Pricing\BillingUnit::all(),
            // Finance is a nullable dependency: with it off, the section
            // says so rather than rendering a broken account picker.
            'finance_available' => $this->paymentService?->isAvailable() ?? false,
            'finance_accounts' => $this->paymentService?->availableAccounts() ?? [],
            'payment_settings' => $selected !== null && $this->paymentService !== null
                ? $this->paymentService->settingsFor($selected->id)
                : new PaymentSettings(),
            // Inbound mail is a nullable dependency too (§7.5): without it
            // the section explains that instead of offering a picker with
            // nothing in it. A manager sees each box's name and state —
            // never its host, port or account (§7.4).
            'inbound_mail_available' => $this->mailboxSelection?->isAvailable() ?? false,
            'inbound_mailboxes' => $this->mailboxSelection?->availableMailboxes() ?? [],
            'selected_mailbox_ids' => $this->mailboxSelection?->selectedIds() ?? [],
            // The cron warning (§6.29). Same signal and same 10-minute
            // window the push-notification page uses — a real crontab
            // typically runs every minute, and the generous window only
            // avoids a false alarm right after a fresh install. Said out
            // loud rather than hidden: on shared hosting without a crontab
            // the reminders still go out, but hours late, and a unit that
            // does not know that will read the delay as a bug.
            'cron_detected' => self::cronDetected($this->settingService),
            'csrf_token' => CsrfGuard::generateToken(),
            'current_path' => '/admin/locations',
        ]);
    }

    /**
     * POST /admin/locations/compte — which Finance account this asset's
     * money is expected on, and **nothing else** about the money.
     *
     * This one field stays with unit staff while the rest of the payment
     * configuration (deposit, balance, security deposit) belongs to the
     * asset's managers, for a reason that is not a matter of taste:
     * `Modules\Finance\Api\FinanceAccountInterface::getConfiguredAccounts()`
     * returns every active account **with its IBAN** and does not filter by
     * `role_min_view`, while a manager is explicitly allowed to be a parent
     * or a former leader (§6.3). Rendering that picker on a `role_min:
     * identified` page would hand the unit's account numbers to all of
     * them, against SECURITY.md §3's rule that every page resolving Finance
     * accounts filters through their own visibility.
     *
     * @param array<string, string> $params
     */
    public function saveFinanceAccount(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/locations')) !== null) {
            return $guard;
        }

        $assetId = (int) $request->getBody('asset_id', 0);
        if ($this->paymentService === null || $this->assetRepository->findById($assetId) === null) {
            return new Response('Not Found', 404);
        }

        $accountId = (int) $request->getBody('finance_account_id', 0);

        try {
            // Only this field: everything else is read back from storage, so
            // pinning an account can never blank a deposit rule a manager
            // set on the other screen.
            $this->paymentService->saveFinanceAccount($assetId, $accountId > 0 ? $accountId : null);

            FlashMessage::set('success', 'Le compte de ce bien a été enregistré.');
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/admin/locations?asset_id=' . $assetId . '#compte');
    }

    /**
     * GET /admin/locations/gestionnaire-recherche — the search-as-you-type
     * box behind the managers section.
     *
     * Answers names, sections and functions. **Never an email address**: the
     * only reason Modules\Groups shows one in its own member picker is to
     * tell two logins of the same human apart, and a grant here names a
     * member, not a login.
     *
     * @param array<string, string> $params
     */
    public function searchManagers(Request $request, array $params): Response
    {
        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $matches = $this->managerService->searchCandidates(
            (string) $request->getQuery('q', ''),
            $scoutYearId
        );

        return $this->json(array_map(static fn(MemberDirectoryEntry $entry) => [
            'id' => $entry->memberId,
            'label' => $entry->label(),
            'sublabel' => $entry->sublabel(),
        ], $matches));
    }

    /**
     * POST /admin/locations/create
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/locations')) !== null) {
            return $guard;
        }

        try {
            $id = $this->assetService->create(
                (string) $request->getBody('name', ''),
                (string) $request->getBody('asset_type', ''),
                self::optionalInt($request->getBody('capacity')),
                max(1, (int) $request->getBody('quantity', 1)),
                Support::optionalString($request->getBody('arrival_time')),
                Support::optionalString($request->getBody('departure_time')),
                Support::optionalString($request->getBody('emergency_phone')),
                $request->getBody('is_public') !== null,
                AuthSession::getUserAccountId(),
                // Asked at creation because it decides the calendar, the
                // price AND the availability model together (§6.8) — a
                // default nobody chose ("forfait par séjour" for a hall) was
                // the first wrong thing every new asset carried. An unknown
                // value falls back to the schema default rather than failing
                // the whole creation.
                \Modules\Rental\Pricing\BillingUnit::tryFrom(
                    (string) $request->getBody('billing_unit', '')
                )
            );
            FlashMessage::set('success', 'Le bien a été créé.');

            return $this->redirect('/admin/locations?asset_id=' . $id);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/admin/locations');
        }
    }

    /**
     * POST /admin/locations/general — the "général" section only.
     *
     * @param array<string, string> $params
     */
    public function saveGeneral(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/locations')) !== null) {
            return $guard;
        }

        $assetId = (int) $request->getBody('asset_id', 0);

        try {
            $this->assetService->updateGeneral(
                $assetId,
                (string) $request->getBody('name', ''),
                (string) $request->getBody('asset_type', ''),
                self::optionalInt($request->getBody('capacity')),
                max(1, (int) $request->getBody('quantity', 1)),
                Support::optionalString($request->getBody('arrival_time')),
                Support::optionalString($request->getBody('departure_time')),
                Support::optionalString($request->getBody('emergency_phone')),
                $request->getBody('is_public') !== null,
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Les informations générales ont été enregistrées.');
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/admin/locations?asset_id=' . $assetId);
    }

    /**
     * POST /admin/locations/managers — the "gestionnaires" section only.
     *
     * Reads the full desired manager set from the form and reconciles it,
     * rather than taking an add/remove verb: the page shows the current
     * managers, so "these are the managers now" is what the operator
     * actually expressed. A member absent from the post is revoked — a
     * deliberate decision by a chief, unlike the import-driven
     * deactivation, which never deletes.
     *
     * **A real bug this shape used to have, and the reason the honoured set
     * is built the way it is.** The form only ever rendered members the
     * picker offers — active members of the year, and now only those old
     * enough — while `revoke()` is a `DELETE`. A manager dropped by the last
     * Desk import is in neither list, so every save silently and permanently
     * deleted their grant, precisely the grant the warning above the section
     * promises is "conservée : elle se réactive d'elle-même s'ils
     * réapparaissent dans un import". The honoured set is therefore built
     * from the candidates **plus every existing grant, active or not**: a
     * grant can only be removed by somebody who could actually see it on the
     * page and untick it.
     *
     * @param array<string, string> $params
     */
    public function saveManagers(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/locations')) !== null) {
            return $guard;
        }

        $assetId = (int) $request->getBody('asset_id', 0);

        try {
            $this->assetService->requireAsset($assetId);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/admin/locations');
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        $userId = AuthSession::getUserAccountId();
        $existing = $this->managerService->listManagersForAsset($assetId, $scoutYearId, false);
        $existingIds = array_map(static fn(array $row) => $row['manager']->memberId, $existing);

        // Only ids the page could actually offer are honoured — a forged id
        // in the post must not create a grant for somebody off the roster,
        // or under the configured minimum age. Existing grants count as
        // offerable because the page renders them: that is what stops a
        // save from deleting a manager it never showed.
        $offerableIds = array_merge(
            array_map(
                static fn(MemberDirectoryEntry $entry) => $entry->memberId,
                $this->managerService->listCandidates($scoutYearId)
            ),
            $existingIds
        );

        $submitted = $request->getBody('manager_member_ids', []);
        $submittedIds = is_array($submitted) ? array_map('intval', $submitted) : [];
        $wantedIds = array_values(array_intersect($submittedIds, $offerableIds));

        $renterContacts = $request->getBody('renter_contact_member_ids', []);
        $renterContactIds = is_array($renterContacts) ? array_map('intval', $renterContacts) : [];

        $suspendedIds = array_map(
            static fn(array $row) => $row['manager']->memberId,
            array_filter($existing, static fn(array $row) => !$row['manager']->isActive)
        );

        foreach (array_diff($existingIds, $wantedIds) as $memberId) {
            $this->managerService->revoke($assetId, (int) $memberId, $userId);
        }

        foreach ($wantedIds as $memberId) {
            $isRenterContact = in_array($memberId, $renterContactIds, true);

            if (in_array($memberId, $suspendedIds, true)) {
                // Kept exactly as the import left it: suspended. grant()
                // would flip `is_active` back on, handing access to
                // somebody the roster no longer lists — the import, not
                // this form, is what decides that, and it reactivates on
                // its own when they reappear. Only the renter-contact flag
                // is honoured here.
                $this->managerService->setRenterContact($assetId, $memberId, $isRenterContact);
                continue;
            }

            $this->managerService->grant($assetId, $memberId, $isRenterContact, $userId);
        }

        FlashMessage::set('success', 'Les gestionnaires ont été enregistrés.');

        return $this->redirect('/admin/locations?asset_id=' . $assetId);
    }

    /**
     * POST /admin/locations/archive
     *
     * @param array<string, string> $params
     */
    public function archive(Request $request, array $params): Response
    {
        return $this->lifecycleAction(
            $request,
            fn(int $assetId, ?int $userId) => $this->assetService->archive($assetId, $userId),
            'Le bien a été archivé.',
            true
        );
    }

    /**
     * POST /admin/locations/restore
     *
     * @param array<string, string> $params
     */
    public function restore(Request $request, array $params): Response
    {
        return $this->lifecycleAction(
            $request,
            fn(int $assetId, ?int $userId) => $this->assetService->restore($assetId, $userId),
            'Le bien a été désarchivé.',
            true
        );
    }

    /**
     * POST /admin/locations/delete
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        // Back to the list rather than to the asset: after a successful
        // delete there is nothing left to select.
        return $this->lifecycleAction(
            $request,
            fn(int $assetId, ?int $userId) => $this->assetService->delete($assetId, $userId),
            'Le bien a été supprimé.',
            false
        );
    }

    /**
     * The shape every lifecycle action shares: check CSRF, read the asset
     * id, run the one operation, turn a RentalException into a flash rather
     * than a stack trace, redirect.
     *
     * @param callable(int, ?int): void $operation
     * @param bool $backToAsset Whether to reselect the asset afterwards — false once it no longer exists.
     */
    private function lifecycleAction(
        Request $request,
        callable $operation,
        string $successMessage,
        bool $backToAsset
    ): Response {
        if (($guard = $this->guardCsrf($request, '/admin/locations')) !== null) {
            return $guard;
        }

        $assetId = (int) $request->getBody('asset_id', 0);

        try {
            $operation($assetId, AuthSession::getUserAccountId());
            FlashMessage::set('success', $successMessage);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/admin/locations?asset_id=' . $assetId);
        }

        return $backToAsset
            ? $this->redirect('/admin/locations?asset_id=' . $assetId)
            : $this->redirect('/admin/locations');
    }

    /**
     * The asset the chip picker currently points at — the one named in the
     * query string when it is real, else the first in the list, else null
     * on an empty installation.
     *
     * @param \Modules\Rental\Repository\RentalAsset[] $assets
     */
    private function resolveSelectedAsset(Request $request, array $assets): ?\Modules\Rental\Repository\RentalAsset
    {
        $requestedId = (int) $request->getQuery('asset_id', 0);

        foreach ($assets as $asset) {
            if ($asset->id === $requestedId) {
                return $asset;
            }
        }

        return $assets[0] ?? null;
    }

    /**
     * Ids of the assets a visitor can reach but nobody has priced: public,
     * not archived, and with neither a default rate nor a single grid cell.
     * Such an asset answers every estimate "Tarif sur demande", which is
     * true but rarely what the unit meant — this is how a chief finds out
     * before a renter does.
     *
     * @param \Modules\Rental\Repository\RentalAsset[] $assets
     * @return int[]
     */
    private function tariffMissingIds(array $assets): array
    {
        if ($this->pricingService === null) {
            return [];
        }

        $missing = [];
        foreach ($assets as $asset) {
            if (!$asset->isPublic || $asset->isArchived) {
                continue;
            }
            if (!$this->pricingService->loadSettings($asset->id)->hasAnyRate()) {
                $missing[] = $asset->id;
            }
        }

        return $missing;
    }

    /**
     * The closed list of asset types, asked of the service rather than
     * re-derived here: the same list has to shape the `<select>` and to
     * decide what a POST may contain, and two derivations of one rule is
     * how a form starts offering something the server refuses.
     *
     * @return string[]
     */
    private function typeSuggestions(): array
    {
        return $this->assetService->allowedTypes();
    }

    private static function optionalInt(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (int) $value;
    }
}
