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
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\IbanNormalizer;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReceivableQrTokenService;
use Twig\Environment;

/**
 * The PNG of one receivable's QR, served to a mail client.
 *
 * **`role_min: public`, and the token is the whole authorization.** A
 * mail client has no session: an image in an e-mail is fetched by a
 * program that has never logged in and never will. The token is an HMAC
 * of the receivable's id under the installation's own key
 * (Service\ReceivableQrTokenService) and is compared in constant time —
 * unguessable, derived rather than stored, and identical every time,
 * which is what makes the archived copy of a sent mail render exactly
 * what went out.
 *
 * What a valid link exposes is one receivable's amount and communication
 * — which is what the mail says in text right underneath. It exposes
 * nothing else: no name, no account holder beyond the beneficiary the
 * payer has to type anyway, and no other receivable.
 *
 * The image is generated on the fly and never persisted: a QR is entirely
 * determined by beneficiary, IBAN, amount and communication, exactly like
 * a photo's thumbnail. It encodes what is STILL DUE, so a reminder sent
 * after a partial payment asks for the balance and not for the whole
 * amount again.
 */
class ReceivableQrController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ExpectedReceivableRepository $receivables,
        private AccountRepository $accounts,
        private ReceivableAllocationService $allocations,
        private ReceivableQrTokenService $tokens,
        private ?SepaQrCodeInterface $sepaQrCode = null
    ) {
    }

    /**
     * GET /finance/qr/{id}/{token}
     *
     * @param array<string, string> $params
     */
    public function png(Request $request, array $params): Response
    {
        $receivableId = (int) ($params['id'] ?? 0);
        if (!$this->tokens->isValid($receivableId, (string) ($params['token'] ?? ''))) {
            // The same answer as an id that does not exist: a wrong token
            // must not tell a prober which receivables are real.
            return new Response('Not Found', 404);
        }

        $receivable = $this->receivables->findById($receivableId);
        if ($receivable === null || $this->sepaQrCode === null) {
            return new Response('Not Found', 404);
        }

        $account = $this->accounts->findById($receivable->accountId);
        if ($account === null || $account->iban === null || $account->iban === '') {
            return new Response('Not Found', 404);
        }

        // refreshAndSettle() rather than a plain read: the amount asked
        // for is the one thing here that must never be stale. A parent
        // who paid half yesterday and opens the mail today would
        // otherwise be shown the full amount again, and pay it.
        $remaining = $this->allocations->refreshAndSettle([$receivable])[$receivable->id]->amountRemainingCents();
        if ($remaining <= 0) {
            // Nothing left to ask for. Serving a QR for 0 € would produce
            // a payment request a bank refuses, which reads as a broken
            // site rather than as a settled receivable.
            return new Response('Not Found', 404);
        }

        $png = $this->sepaQrCode->generatePng(
            $account->holderName ?? $account->name,
            IbanNormalizer::normalize($account->iban),
            null,
            $remaining,
            $receivable->communication
        );

        return (new Response($png))
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Content-Length', (string) strlen($png))
            // Never a shared cache: the image is one family's payment
            // request, and it changes the moment they pay part of it.
            ->setHeader('Cache-Control', 'private, max-age=300');
    }
}
