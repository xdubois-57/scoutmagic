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
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Mail\MailboxSelection;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Twig\Environment;

/**
 * "Espace chefs d'U > Locations" (`role_min: admin`) — the **structural**
 * configuration of the assets themselves: what exists, who manages it, and
 * later its booking rules, pricing and compliance register.
 *
 * Deliberately NOT where an asset is operated day to day. The contract
 * template, the email templates and the bookings all live in the asset's
 * own managed space, reachable by its managers — who are not necessarily
 * chiefs and have no business needing `admin` (roadmap §6.4). Unit staff
 * reach both, since they are implicitly managers of every asset.
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
        private MemberService $memberService,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private RentalPricingService $pricingService,
        private RentalAvailabilityService $availabilityService,
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
        private ?MailboxSelection $mailboxSelection = null
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
        if (!CsrfGuard::validateRequest()) {
            FlashMessage::set('danger', 'Session expirée, veuillez réessayer.');

            return $this->redirect('/admin/locations');
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
        $pricingSettings = $selected !== null ? $this->pricingService->loadSettings($selected->id) : null;

        return $this->render('@rental/config/index.html.twig', [
            'assets' => $assets,
            'selected_asset' => $selected,
            'managers' => $selected !== null
                ? $this->managerService->listManagersForAsset($selected->id, $scoutYearId, false)
                : [],
            'assignable_members' => $selected !== null
                ? $this->assignableMembers($selected->id, $scoutYearId)
                : [],
            'type_suggestions' => $this->typeSuggestions(),
            'billing_units' => \Modules\Rental\Pricing\BillingUnit::all(),
            'fee_natures' => \Modules\Rental\Pricing\RentalFee::natures(),
            'pricing' => $pricingSettings,
            'constraints' => $selected !== null
                ? $this->availabilityService->constraintsFor($selected->id)
                : null,
            // The simulator runs through the very same engine as the public
            // page and the contract (see RentalPricingController::simulate()),
            // which is the only thing that makes it a real guard-rail against
            // a wrong tariff rather than a second opinion.
            'simulation' => $selected !== null && $pricingSettings !== null
                ? RentalPricingController::simulate($this->pricingService, $pricingSettings, [
                    'sim_arrival' => $request->getQuery('sim_arrival', ''),
                    'sim_departure' => $request->getQuery('sim_departure', ''),
                    'sim_persons' => $request->getQuery('sim_persons', 0),
                    'sim_units' => $request->getQuery('sim_units', 1),
                    'sim_rooms' => $request->getQuery('sim_rooms', 1),
                    'sim_category' => $request->getQuery('sim_category', ''),
                ])
                : null,
            // Finance is a nullable dependency: with it off, the section
            // says so rather than rendering a broken account picker.
            'finance_available' => $this->paymentService?->isAvailable() ?? false,
            'finance_accounts' => $this->paymentService?->availableAccounts() ?? [],
            'payment_settings' => $selected !== null && $this->paymentService !== null
                ? $this->paymentService->settingsFor($selected->id)
                : new PaymentSettings(),
            'deposit_modes' => DepositMode::all(),
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
     * POST /admin/locations/payments — the asset's money configuration
     * (§6.19, §6.20).
     *
     * Its own action, like every other section here: a single
     * two-hundred-field form is the thing this screen is designed against.
     *
     * @param array<string, string> $params
     */
    public function savePayments(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
        }

        $assetId = (int) $request->getBody('asset_id', 0);
        if ($this->paymentService === null || $this->assetRepository->findById($assetId) === null) {
            return new Response('Not Found', 404);
        }

        $accountId = (int) $request->getBody('finance_account_id', 0);

        try {
            $this->paymentService->saveSettings($assetId, new PaymentSettings(
                enabled: $request->getBody('payments_enabled') !== null,
                financeAccountId: $accountId > 0 ? $accountId : null,
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

            FlashMessage::set('success', 'Configuration des paiements enregistrée.');
        } catch (RentalException $e) {
            FlashMessage::set('danger', $e->getMessage());
        }

        return $this->redirect('/admin/locations?asset=' . $assetId . '#paiements');
    }



    /**
     * POST /admin/locations/create
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
        }

        try {
            $id = $this->assetService->create(
                (string) $request->getBody('name', ''),
                (string) $request->getBody('asset_type', ''),
                self::optionalInt($request->getBody('capacity')),
                max(1, (int) $request->getBody('quantity', 1)),
                self::optionalString($request->getBody('arrival_time')),
                self::optionalString($request->getBody('departure_time')),
                self::optionalString($request->getBody('emergency_phone')),
                $request->getBody('is_public') !== null,
                $request->getBody('show_in_menu') !== null,
                AuthSession::getUserAccountId()
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
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
        }

        $assetId = (int) $request->getBody('asset_id', 0);

        try {
            $this->assetService->updateGeneral(
                $assetId,
                (string) $request->getBody('name', ''),
                (string) $request->getBody('asset_type', ''),
                self::optionalInt($request->getBody('capacity')),
                max(1, (int) $request->getBody('quantity', 1)),
                self::optionalString($request->getBody('arrival_time')),
                self::optionalString($request->getBody('departure_time')),
                self::optionalString($request->getBody('emergency_phone')),
                $request->getBody('is_public') !== null,
                $request->getBody('show_in_menu') !== null,
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
     * rather than taking an add/remove verb: the page shows a checklist, so
     * "these are the managers now" is what the operator actually expressed.
     * A member absent from the post is revoked — a deliberate decision by a
     * chief, unlike the import-driven deactivation, which never deletes.
     *
     * @param array<string, string> $params
     */
    public function saveManagers(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
        }

        $assetId = (int) $request->getBody('asset_id', 0);

        try {
            $this->assetService->requireAsset($assetId);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/admin/locations');
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        // Only member ids actually assignable this year are honoured — a
        // forged id in the post must not create a grant for someone who is
        // not on the roster at all.
        $assignableIds = array_keys($this->assignableMembers($assetId, $scoutYearId));
        $submitted = $request->getBody('manager_member_ids', []);
        $submittedIds = is_array($submitted) ? array_map('intval', $submitted) : [];
        $wantedIds = array_values(array_intersect($submittedIds, $assignableIds));

        $renterContacts = $request->getBody('renter_contact_member_ids', []);
        $renterContactIds = is_array($renterContacts) ? array_map('intval', $renterContacts) : [];

        $userId = AuthSession::getUserAccountId();
        $existing = $this->managerService->listManagersForAsset($assetId, $scoutYearId, false);
        $existingIds = array_map(static fn(array $row) => $row['manager']->memberId, $existing);

        foreach (array_diff($existingIds, $wantedIds) as $memberId) {
            $this->managerService->revoke($assetId, (int) $memberId, $userId);
        }

        foreach ($wantedIds as $memberId) {
            $this->managerService->grant(
                $assetId,
                $memberId,
                in_array($memberId, $renterContactIds, true),
                $userId
            );
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
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
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
     * Every member who could be made a manager of this asset, as
     * `members.id => display name`.
     *
     * Any active member of the current year qualifies: a manager is
     * explicitly not required to be a chief (roadmap §6.3), so narrowing
     * this by role would defeat the point of the feature. Authority still
     * comes only from the grant itself.
     *
     * @return array<int, string>
     */
    private function assignableMembers(int $assetId, int $scoutYearId): array
    {
        $memberIds = $this->memberService->findActiveMemberIdsForYear($scoutYearId);
        $names = $this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId);
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @return string[]
     */
    private function typeSuggestions(): array
    {
        $raw = (string) ($this->settingService->get('asset_type_suggestions', 'rental') ?: '');
        $types = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $t) => $t !== ''));

        return $types !== [] ? $types : RentalAssetService::SUGGESTED_TYPES;
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
