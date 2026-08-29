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
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\IbanNormalizer;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReconciliationService;
use Twig\Environment;

/**
 * « Rapprochement » — what the automatic matching could not settle on its
 * own, and the four gestures that settle it.
 *
 * Every write here goes through Service\ReceivableAllocationService,
 * which is where the account partition and the never-across-two-accounts
 * invariant are enforced. The controller resolves the caller's role and
 * passes it on; it decides nothing itself.
 */
class ReconciliationController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ReconciliationService $reconciliation,
        private ReceivableAllocationService $allocations,
        private ExpectedReceivableRepository $receivables,
        private FinanceService $financeService,
        private MemberService $members,
        private ScoutYearService $scoutYears,
        private ?SepaQrCodeInterface $sepaQrCode = null
    ) {
    }

    /**
     * GET /finance/reconciliation
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $account = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));
        $accounts = $this->financeService->getAccountsForUser($role);

        if ($account === null) {
            return $this->render('@finance/reconciliation.html.twig', [
                'accounts' => $accounts,
                'selected_account' => null,
                'view' => null,
                'tab' => 'split',
            ]);
        }

        $scoutYearId = (int) ($this->scoutYears->getCurrentYear()['id']);

        try {
            $view = $this->reconciliation->build($account->id, $scoutYearId, $role);
        } catch (FinanceException) {
            return $this->notFound();
        }

        return $this->render('@finance/reconciliation.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $account,
            'view' => $view,
            'tab' => $this->normalizeTab($request->getQuery('tab')),
        ]);
    }

    /**
     * POST /finance/reconciliation/split/{transactionId}
     *
     * The household paid in one go. The site proposed the split; the
     * treasurer confirms it, possibly after correcting an amount.
     *
     * @param array<string, string> $params
     */
    public function applySplit(Request $request, array $params): Response
    {
        $redirect = $this->redirectTarget($request, 'split');
        if (($guard = $this->guardCsrf($request, $redirect)) !== null) {
            return $guard;
        }

        $submitted = $request->getBody('amount', []);
        $amounts = [];
        foreach (is_array($submitted) ? $submitted : [] as $receivableId => $amount) {
            $cents = self::parseAmountCents(is_scalar($amount) ? (string) $amount : '');
            if ($cents !== null && $cents > 0) {
                $amounts[(int) $receivableId] = $cents;
            }
        }

        try {
            $this->allocations->split(
                (int) ($params['transactionId'] ?? 0),
                $amounts,
                Role::fromString(AuthSession::getRole()),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Le virement a été réparti.');
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect);
    }

    /**
     * POST /finance/reconciliation/attach/{transactionId}
     *
     * A credit nobody's communication matched, attached by hand.
     *
     * @param array<string, string> $params
     */
    public function attach(Request $request, array $params): Response
    {
        $redirect = $this->redirectTarget($request, 'orphans');
        if (($guard = $this->guardCsrf($request, $redirect)) !== null) {
            return $guard;
        }

        $amount = self::parseAmountCents((string) $request->getBody('amount', ''));

        try {
            $this->allocations->allocate(
                (int) ($params['transactionId'] ?? 0),
                (int) $request->getBody('receivable_id', 0),
                $amount ?? 0,
                Role::fromString(AuthSession::getRole()),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Le paiement a été rattaché.');
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect);
    }

    /**
     * POST /finance/reconciliation/overpaid/{receivableId}
     *
     * The three answers to a surplus: declare it owed back, put it on
     * another receivable of the household, or leave it alone — which is
     * simply not submitting this form.
     *
     * @param array<string, string> $params
     */
    public function resolveOverpayment(Request $request, array $params): Response
    {
        $redirect = $this->redirectTarget($request, 'overpaid');
        if (($guard = $this->guardCsrf($request, $redirect)) !== null) {
            return $guard;
        }

        $role = Role::fromString(AuthSession::getRole());
        $actor = AuthSession::getUserAccountId();
        $receivableId = (int) ($params['receivableId'] ?? 0);

        try {
            if ((string) $request->getBody('action', '') === 'transfer') {
                $this->allocations->transferOverpayment(
                    $receivableId,
                    (int) $request->getBody('target_receivable_id', 0),
                    self::parseAmountCents((string) $request->getBody('amount', '')) ?? 0,
                    $role,
                    $actor
                );
                FlashMessage::set('success', 'Le trop-perçu a été imputé sur une autre créance du foyer.');
            } else {
                $this->allocations->requestRefund($receivableId, $role, $actor);
                FlashMessage::set('success', 'Le trop-perçu est marqué à rembourser.');
            }
        } catch (FinanceException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($redirect);
    }

    /**
     * GET /finance/receivables/{id}/qr
     *
     * Full screen, on purpose: the gesture is handing a phone to a parent
     * met after the meeting so they can scan it. Maximum contrast, and
     * the member's own name in view the whole time — a treasurer going
     * through three parents in a row can very easily show the neighbour's
     * receivable.
     *
     * **The QR encodes what is still due, never the original amount.** On
     * a receivable of 45 € with 20 € already in, it asks for 25 €. Asking
     * for 45 € again would manufacture a 20 € surplus — exactly the
     * problem the rest of this screen exists to clear up.
     *
     * @param array<string, string> $params
     */
    public function qr(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $receivable = $this->receivables->findById((int) ($params['id'] ?? 0));
        if ($receivable === null) {
            return $this->notFound();
        }

        $account = $this->financeService->getAccount($receivable->accountId);
        if (!$this->financeService->isAccountVisibleTo($account, $role)) {
            return $this->notFound();
        }
        \assert($account !== null);

        // Refreshed rather than read: the amount on a QR somebody is
        // about to scan is the one thing that must never be stale.
        $settlement = $this->allocations->refreshAndSettle([$receivable])[$receivable->id];
        $remaining = $settlement->amountRemainingCents();

        $png = null;
        if ($this->sepaQrCode !== null && $account->iban !== null && $account->iban !== '' && $remaining > 0) {
            $png = $this->sepaQrCode->generatePng(
                $account->holderName ?? $account->name,
                IbanNormalizer::normalize($account->iban),
                null,
                $remaining,
                $receivable->communication
            );
        }

        $name = null;
        if ($receivable->memberId !== null) {
            $scoutYearId = (int) ($this->scoutYears->getCurrentYear()['id']);
            foreach ($this->members->findDirectoryForYear($scoutYearId) as $entry) {
                if ($entry->memberId === $receivable->memberId) {
                    $name = trim($entry->firstName . ' ' . $entry->lastName);
                    break;
                }
            }
        }

        return $this->render('@finance/receivable_qr.html.twig', [
            'receivable' => $receivable,
            'settlement' => $settlement,
            'remaining_cents' => $remaining,
            'member_name' => $name,
            'account' => $account,
            // Grouped for READING only — IbanNormalizer::format() must
            // never travel further (ARCHITECTURE.md §8.72).
            'iban_display' => $account->iban !== null ? IbanNormalizer::format(IbanNormalizer::normalize($account->iban)) : null,
            'qr_data_uri' => $png !== null ? 'data:image/png;base64,' . base64_encode($png) : null,
            // The breadcrumb is the site's only back affordance
            // (design.md §7.3), so the way back is a trail entry and
            // never a « Retour » button.
            'breadcrumb_trail' => [$this->originTrail($request)],
        ]);
    }

    /**
     * Where this QR was opened from — a campaign's lines, or the
     * reconciliation screen.
     *
     * @return array{label: string, url: string}
     */
    private function originTrail(Request $request): array
    {
        $campaignId = (int) $request->getQuery('campaign_id', 0);
        if ($campaignId > 0) {
            return ['label' => 'Campagne', 'url' => '/finance/campaigns/' . $campaignId];
        }

        return [
            'label' => 'Rapprochement',
            'url' => '/finance/reconciliation?account_id=' . (int) $request->getQuery('account_id', 0),
        ];
    }

    private function redirectTarget(Request $request, string $tab): string
    {
        $accountId = (int) $request->getBody('account_id', 0);

        return '/finance/reconciliation?account_id=' . $accountId . '&tab=' . $tab;
    }

    private function normalizeTab(mixed $tab): string
    {
        return in_array($tab, ['split', 'orphans', 'overpaid', 'cross_account'], true) ? (string) $tab : 'split';
    }

    /**
     * The same reading as a campaign file's amounts — one rule for
     * "what a human typed as an amount", not two.
     */
    private static function parseAmountCents(string $raw): ?int
    {
        return \Modules\Finance\Service\CampaignImportService::parseAmountCents($raw);
    }
}
