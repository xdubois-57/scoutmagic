<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
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
        $trackingToken = $this->repository->rotateTrackingToken($request->id);
        $trackingUrl = rtrim($this->baseUrl, '/') . "/inscriptions/suivi/{$request->id}/{$trackingToken}";

        $body = $this->substitute((string) $this->editableContentService->get($contentKey), [
            'prenom_enfant' => $request->childFirstName,
            'annee_scoute' => $targetYearLabel,
            'lien_suivi' => $trackingUrl,
            'nom_unite' => $this->siteName,
        ]);

        try {
            $this->mailService->send(to: $request->email, subject: $subject, bodyHtml: $body, bodyText: strip_tags($body));
        } catch (MailException $e) {
            throw new RegistrationException("Échec de l'envoi de l'email : {$e->getMessage()}");
        }
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
