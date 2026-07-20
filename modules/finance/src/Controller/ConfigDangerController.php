<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * POST /config/finance/danger — the two destructive "zone de danger"
 * actions (module spec §"Page de configuration"). The client shows a
 * modal plus a typed confirmation field before ever calling this
 * endpoint; the expected text is re-checked here too, since a client-side
 * check alone is never a real safeguard.
 */
class ConfigDangerController extends AbstractController
{
    private const CONFIRMATION_TEXT = 'SUPPRIMER';

    public function __construct(
        protected \Twig\Environment $twig,
        private TransactionRepository $transactionRepository,
        private BalanceCheckpointRepository $checkpointRepository,
        private AttachmentRepository $attachmentRepository,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function execute(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        if ((string) ($data['confirmation_text'] ?? '') !== self::CONFIRMATION_TEXT) {
            return $this->json(['success' => false, 'error' => 'Texte de confirmation incorrect.'], 400);
        }

        $action = (string) ($data['action'] ?? '');

        switch ($action) {
            case 'delete_movements':
                $accountId = (int) ($data['account_id'] ?? 0);
                if ($accountId === 0) {
                    return $this->json(['success' => false, 'error' => 'Compte requis.'], 400);
                }
                $deletedMovements = $this->transactionRepository->deleteAllForAccount($accountId);
                $deletedCheckpoints = $this->checkpointRepository->deleteAllForAccount($accountId);
                $this->journalService->log(
                    'finance',
                    'movements_deleted',
                    'security',
                    "Tous les mouvements du compte ont été supprimés ({$deletedMovements} mouvement(s), {$deletedCheckpoints} solde(s) de référence)",
                    ['account_id' => $accountId, 'deleted_movements' => $deletedMovements, 'deleted_checkpoints' => $deletedCheckpoints],
                    AuthSession::getUserAccountId()
                );
                return $this->json(['success' => true]);

            case 'delete_receipts':
                $archived = $this->attachmentRepository->archiveAll();
                $this->journalService->log(
                    'finance',
                    'receipts_deleted',
                    'security',
                    "Tous les reçus ont été archivés ({$archived} reçu(s))",
                    ['archived' => $archived],
                    AuthSession::getUserAccountId()
                );
                return $this->json(['success' => true]);

            default:
                return $this->json(['success' => false, 'error' => 'Action inconnue.'], 400);
        }
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function decodeAndAuthorize(Request $request): array|Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        return $data;
    }
}
