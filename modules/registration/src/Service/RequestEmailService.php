<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\CapabilityToken;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Acceptance/refusal emails — always sent explicitly (module spec: never
 * automatically at a status change), never before their body has actually
 * been written (isAcceptedBodyReady()/isRefusedBodyReady() are the guard
 * Controller\RegistrationRequestController disables the send button on —
 * unlike the itération 4 receipt/unit-alert emails, these ship with NO
 * default body: a chief must write one, or the request stays visibly
 * "en cours" to the parent forever, per Service\RequestStatusService::
 * visibleStatus()).
 */
class RequestEmailService
{
    private const ACCEPTED_KEY = 'registration_email_accepted_body';
    private const REFUSED_KEY = 'registration_email_refused_body';

    public function __construct(
        private RegistrationRequestRepository $repository,
        private MailService $mailService,
        private EditableContentService $editableContentService,
        private JournalService $journalService,
        private string $baseUrl,
        private string $siteName
    ) {
    }

    public function isAcceptedBodyReady(): bool
    {
        return $this->hasContent(self::ACCEPTED_KEY);
    }

    public function isRefusedBodyReady(): bool
    {
        return $this->hasContent(self::REFUSED_KEY);
    }

    /**
     * @throws RegistrationException when the body hasn't been written yet
     */
    public function sendAccepted(RegistrationRequest $request, string $targetYearLabel): void
    {
        if (!$this->isAcceptedBodyReady()) {
            throw new RegistrationException("Le corps de l'email d'acceptation n'a pas encore été rédigé.");
        }

        $this->send($request, self::ACCEPTED_KEY, "Votre demande d'inscription a été acceptée", $targetYearLabel);
        $this->repository->markAcceptedEmailSent($request->id);

        $this->journalService->log(
            'registration', 'registration_accepted_email_sent', 'info',
            "Email d'acceptation envoyé", ['request_id' => $request->id]
        );
    }

    /**
     * @throws RegistrationException when the body hasn't been written yet
     */
    public function sendRefused(RegistrationRequest $request, string $targetYearLabel): void
    {
        if (!$this->isRefusedBodyReady()) {
            throw new RegistrationException("Le corps de l'email de refus n'a pas encore été rédigé.");
        }

        $this->send($request, self::REFUSED_KEY, "Votre demande d'inscription a été refusée", $targetYearLabel);
        $this->repository->markRefusedEmailSent($request->id);

        $this->journalService->log(
            'registration', 'registration_refused_email_sent', 'info',
            'Email de refus envoyé', ['request_id' => $request->id]
        );
    }

    private function hasContent(string $key): bool
    {
        $value = $this->editableContentService->get($key);

        return $value !== null && trim(strip_tags($value)) !== '';
    }

    /**
     * @throws RegistrationException on a mail delivery failure — unlike
     *         the best-effort submission emails (itération 4), a staff-
     *         triggered send must surface its own failure so the button
     *         doesn't silently claim success (Controller\
     *         RegistrationRequestController shows the error and leaves
     *         *_email_sent_at untouched, so retrying is always available).
     */
    private function send(RegistrationRequest $request, string $contentKey, string $subject, string $targetYearLabel): void
    {
        // The token is minted here but only PERSISTED once the mail is
        // actually out (below). Persisting first — what this method used to
        // do — meant a delivery failure killed the link the family already
        // had while never handing them the replacement.
        $trackingToken = CapabilityToken::generate();
        $trackingUrl = rtrim($this->baseUrl, '/') . "/inscriptions/suivi/{$request->id}/{$trackingToken}";

        $body = $this->substitute((string) $this->editableContentService->get($contentKey), [
            'prenom_enfant' => $request->childFirstName,
            'annee_scoute' => $targetYearLabel,
            'lien_suivi' => $trackingUrl,
            'nom_unite' => $this->siteName,
        ]);

        try {
            $this->mailService->send(to: $request->email, subject: $subject, bodyHtml: $body, bodyText: self::toPlainText($body));
        } catch (MailException $e) {
            // Nothing was written: the previous tracking link still works and
            // *_email_sent_at is untouched, so retrying really is free. Worth
            // a journal entry all the same — a flash message disappears with
            // the page, and a unit whose SMTP is broken has otherwise no
            // trace that a parent was never told anything.
            $this->journalService->log(
                'registration',
                'registration_status_email_failed',
                'info',
                "Échec de l'envoi d'un email de décision — lien de suivi inchangé, réessai possible",
                [
                    'request_id' => $request->id,
                    'content_key' => $contentKey,
                    // Core\Mail\MailException is built from PHPMailer's
                    // ErrorInfo — raw SMTP English, and the reason this
                    // never goes in the sentence below.
                    'mail_error' => $e->getMessage(),
                ]
            );

            throw new RegistrationException(
                "L'email n'a pas pu être envoyé à la famille — le lien de suivi précédent reste valable, réessayez dans quelques instants.",
                0,
                $e
            );
        }

        $this->repository->storeTrackingTokenHash($request->id, $trackingToken);
    }

    /**
     * The plain-text alternative of an HTML body. strip_tags() alone left
     * the entities substitute() introduced (a first name with an apostrophe
     * arrived as "Ma&#039;lo", an url's & as "&amp;"), so the decode pass
     * is what actually makes this readable.
     */
    public static function toPlainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param array<string, string> $vars
     */
    private function substitute(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        return strtr($template, $replacements);
    }
}
