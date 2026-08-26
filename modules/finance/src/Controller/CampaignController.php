<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Finance\Service\CampaignExportService;
use Modules\Finance\Service\CampaignImportException;
use Modules\Finance\Service\CampaignNotificationService;
use Modules\Finance\Service\CampaignReminderService;
use Modules\Finance\Service\CampaignOverviewService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceivableAllocationService;
use Twig\Environment;

/**
 * The payment campaigns: the list, the upload, one campaign's lines, and
 * the gestures a treasurer makes on them.
 *
 * `role_min: intendant` on every route only proves the caller may open a
 * finance page at all. **Which campaigns they may see is a per-account
 * decision**, made in Service\CampaignService::requireCampaign() and
 * Service\CampaignOverviewService — a campaign booked against a
 * section's account belongs to that section's treasurer, here as
 * everywhere else.
 */
class CampaignController extends AbstractController
{
    private const SESSION_EXPIRED = 'Votre session a expiré. Rechargez la page et réessayez.';

    public function __construct(
        protected Environment $twig,
        private CampaignService $campaignService,
        private CampaignOverviewService $overviewService,
        private CampaignExportService $exportService,
        private CampaignReminderService $reminderService,
        private CampaignNotificationService $notificationService,
        private FinanceService $financeService,
        private ReceivableAllocationService $allocationService,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * GET /finance/campaigns
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $years = $this->scoutYears->getAll();
        $selectedYearId = $this->resolveYear($request->getQuery('scout_year_id'), $years);

        return $this->render('@finance/campaigns/list.html.twig', [
            'summaries' => $selectedYearId !== null ? $this->overviewService->listForYear($selectedYearId, $role) : [],
            'scout_years' => array_reverse($years),
            'selected_scout_year_id' => $selectedYearId,
            'selected_scout_year_label' => $selectedYearId !== null ? $this->scoutYearLabel($selectedYearId) : '',
            'accounts' => $this->financeService->getAccountsForUser($role),
        ]);
    }

    /**
     * GET /finance/campaigns/new
     *
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        return $this->renderForm();
    }

    /**
     * POST /finance/campaigns
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->renderForm(['error' => self::SESSION_EXPIRED]);
        }

        $file = $request->getFile('spreadsheet');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->renderForm(['error' => 'Aucun fichier fourni, ou erreur pendant le chargement.']);
        }

        try {
            $campaignId = $this->campaignService->createFromFile(
                (string) $request->getBody('label', ''),
                (int) $request->getBody('scout_year_id', 0),
                (int) $request->getBody('account_id', 0),
                (string) $file['tmp_name'],
                (string) $file['name'],
                Role::fromString(AuthSession::getRole()),
                AuthSession::getUserAccountId()
            );
        } catch (CampaignImportException $e) {
            // The refusal names every offending line at once, with what
            // the file says on it: a refusal that sends somebody to a
            // help page without explaining anything on the spot is a
            // refusal done badly.
            return $this->renderForm([
                'error' => $e->getMessage(),
                'rejected_lines' => $e->lines,
            ]);
        } catch (FinanceException $e) {
            return $this->renderForm(['error' => $e->getMessage()]);
        }

        FlashMessage::set('success', 'La campagne a été créée.');

        return $this->redirect('/finance/campaigns/' . $campaignId);
    }

    /**
     * GET /finance/campaigns/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $campaign = $this->campaignService->requireCampaign(
                (int) ($params['id'] ?? 0),
                Role::fromString(AuthSession::getRole())
            );
        } catch (FinanceException) {
            return $this->notFound();
        }

        $filter = CampaignOverviewService::normalizeFilter($request->getQuery('filter'));
        $detail = $this->overviewService->detail($campaign, $filter);

        return $this->render('@finance/campaigns/detail.html.twig', $detail + [
            'filter' => $filter,
            'reminder_available' => $this->reminderService->isAvailable(),
            'scout_year' => $this->scoutYearLabel($campaign->scoutYearId),
            'account' => $this->financeService->getAccount($campaign->accountId),
            'breadcrumb_trail' => [['label' => 'Campagnes', 'url' => '/finance/campaigns']],
        ]);
    }

    /**
     * GET /finance/campaigns/{id}/export
     *
     * **The export follows the filter on screen**, and the screen says so
     * — the button carries the count and a line beside the filter spells
     * it out. Exporting 262 lines while looking at the 41 unpaid ones is
     * a surprise; exporting 41 while believing you have 262 is worse, and
     * is how a reminder goes to a fifth of the families without anybody
     * noticing.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        try {
            $campaign = $this->campaignService->requireCampaign(
                (int) ($params['id'] ?? 0),
                Role::fromString(AuthSession::getRole())
            );
        } catch (FinanceException) {
            return $this->notFound();
        }

        $filter = CampaignOverviewService::normalizeFilter($request->getQuery('filter'));
        $detail = $this->overviewService->detail($campaign, $filter);
        $xlsx = $this->exportService->build($campaign, $detail['rows']);

        return (new Response($xlsx))
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="campagne-' . $campaign->id . '.xlsx"')
            ->setHeader('Content-Length', (string) strlen($xlsx));
    }

    /**
     * POST /finance/campaigns/{id}/status — close or reopen.
     *
     * @param array<string, string> $params
     */
    public function updateStatus(Request $request, array $params): Response
    {
        $campaignId = (int) ($params['id'] ?? 0);
        $redirect = '/finance/campaigns/' . $campaignId;

        $csrf = $this->guardCsrf($request, $redirect);
        if ($csrf !== null) {
            return $csrf;
        }

        $role = Role::fromString(AuthSession::getRole());
        $actor = AuthSession::getUserAccountId();

        try {
            if ((string) $request->getBody('status', '') === 'closed') {
                $this->campaignService->close($campaignId, $role, $actor);
                FlashMessage::set('success', 'La campagne est clôturée.');
            } else {
                $this->campaignService->reopen($campaignId, $role, $actor);
                FlashMessage::set('success', 'La campagne est rouverte.');
            }
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect);
    }

    /**
     * POST /finance/campaigns/{id}/rows/{rowId}/note
     *
     * @param array<string, string> $params
     */
    public function saveNote(Request $request, array $params): Response
    {
        $campaignId = (int) ($params['id'] ?? 0);
        $redirect = '/finance/campaigns/' . $campaignId;

        $csrf = $this->guardCsrf($request, $redirect);
        if ($csrf !== null) {
            return $csrf;
        }

        try {
            $this->campaignService->setNote(
                (int) ($params['rowId'] ?? 0),
                (string) $request->getBody('note', ''),
                Role::fromString(AuthSession::getRole()),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'La note a été enregistrée.');
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect . '?filter=' . CampaignOverviewService::normalizeFilter($request->getBody('filter')));
    }

    /**
     * POST /finance/campaigns/{id}/receivables/{receivableId}/waive
     *
     * Abandoning a receivable — a dispense, a goodwill gesture, an
     * invoicing mistake. It settles the line and **nothing enters the
     * account**, which is exactly why it is not recorded as a payment:
     * confusing the two would inflate every incoming total the treasurer
     * reads.
     *
     * @param array<string, string> $params
     */
    public function waive(Request $request, array $params): Response
    {
        $campaignId = (int) ($params['id'] ?? 0);
        $filter = CampaignOverviewService::normalizeFilter($request->getBody('filter'));
        $redirect = '/finance/campaigns/' . $campaignId . '?filter=' . $filter;

        $csrf = $this->guardCsrf($request, $redirect);
        if ($csrf !== null) {
            return $csrf;
        }

        $role = Role::fromString(AuthSession::getRole());
        $receivableId = (int) ($params['receivableId'] ?? 0);

        try {
            // The campaign is resolved first so a receivable id from
            // another campaign — or another account — cannot be waived
            // through this route.
            $this->campaignService->requireCampaign($campaignId, $role);

            if ((string) $request->getBody('waived', '1') === '0') {
                $this->allocationService->cancelWaiver($receivableId, $role);
                FlashMessage::set('success', "L'abandon a été annulé.");
            } else {
                $this->allocationService->waive($receivableId, $role, AuthSession::getUserAccountId());
                FlashMessage::set('success', 'La créance a été abandonnée.');
            }
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect);
    }

    /**
     * POST /finance/campaigns/{id}/reminder
     *
     * Prepares a mail-merge draft with the recipients, their amounts,
     * their communications and their QR — and **sends nothing**. The
     * treasurer reads it, edits it, and sends it from the mail-merge
     * screen like any other draft.
     *
     * @param array<string, string> $params
     */
    public function reminder(Request $request, array $params): Response
    {
        $campaignId = (int) ($params['id'] ?? 0);
        $redirect = '/finance/campaigns/' . $campaignId;

        $csrf = $this->guardCsrf($request, $redirect);
        if ($csrf !== null) {
            return $csrf;
        }

        try {
            $campaign = $this->campaignService->requireCampaign($campaignId, Role::fromString(AuthSession::getRole()));
            $url = $this->reminderService->createDraft(
                $campaign,
                AuthSession::getRole(),
                AuthSession::getEmail() ?? '',
                AuthSession::getUserAccountId()
            );
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($redirect);
        } catch (\Throwable $e) {
            // The mail-merge module's own refusal survives, its internals
            // do not (AGENTS.md § Exception messages that reach a visitor).
            FlashMessage::set('error', \Core\Exception\UserFacingMessage::from(
                $e,
                "Le brouillon de rappel n'a pas pu être créé. Vérifiez que le publipostage est configuré pour votre section."
            ));

            return $this->redirect($redirect);
        }

        FlashMessage::set('success', "Le brouillon est prêt — relisez-le, il n'a pas été envoyé.");

        return $this->redirect($url);
    }

    /**
     * POST /finance/campaigns/{id}/notify
     *
     * "Les familles ont été prévenues." A separate, explicit gesture,
     * because the reminder leaves by hand from the mail-merge screen and
     * the site cannot know when it actually went out.
     *
     * @param array<string, string> $params
     */
    public function notify(Request $request, array $params): Response
    {
        $campaignId = (int) ($params['id'] ?? 0);
        $redirect = '/finance/campaigns/' . $campaignId;

        $csrf = $this->guardCsrf($request, $redirect);
        if ($csrf !== null) {
            return $csrf;
        }

        try {
            $actorAccountId = AuthSession::getUserAccountId();
            $campaign = $this->campaignService->markNotified(
                $campaignId,
                Role::fromString(AuthSession::getRole()),
                $actorAccountId
            );

            // The mark and the notification are two steps of one gesture,
            // in this order: the date is what the screen reads, and a
            // notification that failed to reach anybody must still leave
            // the campaign marked rather than invite a second round of
            // messages to the families who did get one.
            $notified = $this->notificationService->notifyFamilies($campaign, $actorAccountId);

            FlashMessage::set('success', $notified > 0
                ? 'Les familles sont prévenues — ' . $notified . ' compte' . ($notified > 1 ? 's' : '') . ' notifié' . ($notified > 1 ? 's' : '') . '.'
                : "La campagne est marquée comme notifiée. Aucun compte n'a reçu de notification : soit tout est réglé, soit aucune famille n'a de compte sur le site.");
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderForm(array $context = []): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $years = $this->scoutYears->getAll();
        $current = $this->scoutYears->getCurrentYear();

        return $this->render('@finance/campaigns/new.html.twig', $context + [
            'accounts' => $this->financeService->getAccountsForUser($role),
            'scout_years' => array_reverse($years),
            'current_scout_year_id' => $current['id'],
            'breadcrumb_trail' => [['label' => 'Campagnes', 'url' => '/finance/campaigns']],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $years
     */
    private function resolveYear(mixed $requested, array $years): ?int
    {
        $ids = array_map(static fn(array $year): int => (int) $year['id'], $years);
        if ($requested !== null && $requested !== '' && in_array((int) $requested, $ids, true)) {
            return (int) $requested;
        }

        $current = $this->scoutYears->getCurrentYear();
        $currentId = $current['id'];

        return in_array($currentId, $ids, true) ? $currentId : ($ids[count($ids) - 1] ?? null);
    }

    private function scoutYearLabel(int $scoutYearId): string
    {
        foreach ($this->scoutYears->getAll() as $year) {
            if ((int) $year['id'] === $scoutYearId) {
                return (string) $year['label'];
            }
        }

        return '';
    }
}
