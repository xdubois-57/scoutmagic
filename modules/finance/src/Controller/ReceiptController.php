<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Service\FinanceService;

/**
 * Upload, OCR-assisted amount/date suggestion (Service\ReceiptExtractionService),
 * replace, and delete are not implemented yet — a later iteration of the
 * module spec. list() already shows active attachments for real, since
 * Repository\AttachmentRepository is fully built.
 */
class ReceiptController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private AttachmentRepository $attachmentRepository,
        private FinanceService $financeService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        return $this->render('@finance/receipts/list.html.twig', [
            'attachments' => $this->attachmentRepository->findActiveOrdered(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        return $this->render('@finance/receipts/form.html.twig', [
            'accounts' => $this->financeService->getAllAccountsForConfig(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        return $this->json(['success' => false, 'error' => "L'ajout de reçus n'est pas encore disponible."], 501);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        return $this->json(['success' => false, 'error' => "La modification des reçus n'est pas encore disponible."], 501);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        return $this->json(['success' => false, 'error' => "La suppression des reçus n'est pas encore disponible."], 501);
    }

    /**
     * @param array<string, string> $params
     */
    public function replace(Request $request, array $params): Response
    {
        return $this->json(['success' => false, 'error' => "Le remplacement des reçus n'est pas encore disponible."], 501);
    }
}
