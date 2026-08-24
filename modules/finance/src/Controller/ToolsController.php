<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\IbanNormalizer;
use Modules\Finance\Service\StructuredCommunicationService;
use Twig\Environment;

/**
 * "Outils" — two small utilities that share nothing but a page
 * (ARCHITECTURE.md §8.73). Both are answers to a question a treasurer
 * asks with a phone in one hand: "make me a QR for this payment" and
 * "what is this communication on my statement?".
 *
 * Server-rendered, POST then render, exactly like Controller\
 * ImportController: no build tooling, no new dependency, and the QR
 * arrives as a data: URI the way Modules\News\Service\ResponseService
 * already delivers one. A tool that needed a JavaScript bundle to answer
 * two questions would cost more than it saves.
 */
class ToolsController extends AbstractController
{
    private const MAX_COMMUNICATION_LENGTH = 140;

    public function __construct(
        protected Environment $twig,
        private FinanceService $financeService,
        private ExpectedReceivableRepository $receivableRepository,
        private JournalService $journalService,
        private ?SepaQrCodeInterface $sepaQrCode = null
    ) {
    }

    /**
     * GET /finance/tools
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->renderTools();
    }

    /**
     * POST /finance/tools/qr — a payment QR for an arbitrary beneficiary,
     * IBAN, amount and communication.
     *
     * **It creates no receivable, and that is the point.** Writing a
     * `finance_expected_receivables` row here would fill the
     * reconciliation page with money nobody ever promised, and a
     * treasurer producing a QR to show somebody has not thereby decided
     * that a payment is owed. It renders an image; that is all.
     *
     * @param array<string, string> $params
     */
    public function generateQr(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/finance/tools')) !== null) {
            return $guard;
        }

        $beneficiary = trim((string) $request->getBody('beneficiary', ''));
        $ibanInput = (string) $request->getBody('iban', '');
        $amountInput = trim((string) $request->getBody('amount', ''));
        $communication = trim((string) $request->getBody('communication', ''));

        $iban = IbanNormalizer::normalize($ibanInput);
        $amountCents = $this->parseAmountCents($amountInput);

        $error = match (true) {
            $beneficiary === '' => 'Le nom du bénéficiaire est obligatoire.',
            !IbanNormalizer::isValidFullIban($iban) => "Cet IBAN n'est pas valide.",
            $amountCents === null => 'Le montant doit être un nombre positif.',
            mb_strlen($communication) > self::MAX_COMMUNICATION_LENGTH
                => 'La communication ne peut pas dépasser ' . self::MAX_COMMUNICATION_LENGTH . ' caractères.',
            $this->sepaQrCode === null => "Le générateur de QR n'est pas disponible.",
            default => null,
        };

        if ($error !== null) {
            return $this->renderTools([
                'qr_error' => $error,
                'qr_form' => $this->qrFormValues($beneficiary, $ibanInput, $amountInput, $communication),
            ]);
        }

        // No assertion needed: the match above returns for a null
        // generator and for an unparseable amount, so both are known
        // good here — PHPStan agrees, which is why there is no assert().
        $png = $this->sepaQrCode->generatePng($beneficiary, $iban, null, $amountCents, $communication);

        return $this->renderTools([
            'qr_form' => $this->qrFormValues($beneficiary, $ibanInput, $amountInput, $communication),
            'qr_result' => [
                'data_uri' => 'data:image/png;base64,' . base64_encode($png),
                'beneficiary' => $beneficiary,
                // Grouped for READING only — this is a screen, and
                // IbanNormalizer::format() must never travel further
                // (ARCHITECTURE.md §8.72).
                'iban' => IbanNormalizer::format($iban),
                'amount_cents' => $amountCents,
                'communication' => $communication,
            ],
        ]);
    }

    /**
     * POST /finance/tools/communication — is this communication
     * well-formed, and is it one of ours?
     *
     * @param array<string, string> $params
     */
    public function checkCommunication(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/finance/tools')) !== null) {
            return $guard;
        }

        $input = trim((string) $request->getBody('communication', ''));
        if ($input === '') {
            return $this->renderTools(['check_error' => 'Saisissez une communication à vérifier.']);
        }

        $isValid = StructuredCommunicationService::isValid($input);
        $match = $isValid ? $this->visibleReceivableFor($input) : null;

        // The journal records THAT somebody checked and what came of it —
        // never the communication typed nor the label found. A payment
        // reference and a receivable's label are personal data about who
        // paid what; a numeric id is enough to follow the thread
        // (SECURITY.md §11).
        $this->journalService->log(
            'finance',
            'communication_checked',
            'info',
            'Vérification d\'une communication structurée',
            ['valid' => $isValid, 'receivable_id' => $match['id'] ?? null],
            AuthSession::getUserAccountId()
        );

        return $this->renderTools([
            'check_input' => $input,
            'check_result' => ['valid' => $isValid, 'match' => $match],
        ]);
    }

    /**
     * The receivable this communication refers to, **only if the caller
     * may see the account it is booked against**.
     *
     * A receivable carries a label and an amount — what somebody owes and
     * for what. §8.69 narrowed a section's account to that section's
     * treasurer and §8.70 did the same for its receipts; a checker that
     * answered "Camp 2026 — 25,00 €" for an account the caller cannot
     * open would hand back through this page exactly what those two
     * removed everywhere else.
     *
     * An invisible match answers like an unknown one. Saying "it exists
     * but is not yours" would still confirm that this communication was
     * issued, and by which unit — the fact of existence is itself the
     * thing being withheld.
     *
     * @return array{id: int, label: ?string, amount_due_cents: int, account_name: string}|null
     */
    private function visibleReceivableFor(string $communication): ?array
    {
        $receivable = $this->receivableRepository->findByCommunication($communication);
        if ($receivable === null) {
            return null;
        }

        // One condition, not two, because they are one answer: an
        // account that no longer exists and an account the caller may not
        // open are both "we cannot tell you about this".
        $account = $this->financeService->getAccount($receivable->accountId);
        $role = Role::fromString(AuthSession::getRole());
        if ($account === null || !$this->financeService->isAccountVisibleTo($account, $role)) {
            return null;
        }

        return [
            'id' => $receivable->id,
            'label' => $receivable->label,
            'amount_due_cents' => $receivable->amountDueCents,
            'account_name' => $account->name,
        ];
    }

    /**
     * Cents, or null when the input is not a positive amount. Accepts the
     * comma every French-speaking keyboard produces as readily as the dot.
     */
    private function parseAmountCents(string $amount): ?int
    {
        $normalized = str_replace([' ', ','], ['', '.'], $amount);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $cents = (int) round(((float) $normalized) * 100);

        return $cents > 0 ? $cents : null;
    }

    /**
     * @return array<string, string>
     */
    private function qrFormValues(string $beneficiary, string $iban, string $amount, string $communication): array
    {
        return [
            'beneficiary' => $beneficiary,
            'iban' => $iban,
            'amount' => $amount,
            'communication' => $communication,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTools(array $context = []): Response
    {
        return $this->render('@finance/tools.html.twig', $context + [
            // The page picker in @finance/_nav.html.twig wants these; the
            // account picker is suppressed because neither tool works on
            // an account — the QR takes an arbitrary IBAN, and the checker
            // searches every receivable the caller may see.
            'accounts' => [],
            'hide_account_picker' => true,
        ]);
    }
}
