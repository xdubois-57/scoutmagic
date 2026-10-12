<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Exception\UserFacingMessage;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\CapabilityToken;
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmail;
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
 *
 * **Every send is an attempt recorded by Service\DecisionEmailService**
 * (issue #843, IT-05): claimed before the transport — so two clicks, or a
 * click racing the automatic sending, send one e-mail and not two — then
 * recorded as sent or failed, never overwriting an earlier attempt. An
 * attempt refused by the body guard is recorded as failed too: an e-mail
 * nobody wrote never passes for one that left.
 */
class RequestEmailService
{
    private const ACCEPTED_KEY = 'registration_email_accepted_body';
    private const REFUSED_KEY = 'registration_email_refused_body';

    /**
     * What differs between the two e-mails.
     *
     * @var array<string, array{key: string, subject: string, missing: string, event: string, journal: string}>
     */
    private const EMAILS = [
        DecisionEmail::DECISION_ACCEPTED => [
            'key' => self::ACCEPTED_KEY,
            'subject' => "Votre demande d'inscription a été acceptée",
            'missing' => "Le corps de l'email d'acceptation n'a pas encore été rédigé.",
            'event' => 'registration_accepted_email_sent',
            'journal' => "Email d'acceptation envoyé",
        ],
        DecisionEmail::DECISION_REFUSED => [
            'key' => self::REFUSED_KEY,
            'subject' => "Votre demande d'inscription a été refusée",
            'missing' => "Le corps de l'email de refus n'a pas encore été rédigé.",
            'event' => 'registration_refused_email_sent',
            'journal' => 'Email de refus envoyé',
        ],
    ];

    public function __construct(
        private RegistrationRequestRepository $repository,
        private MailService $mailService,
        private EditableContentService $editableContentService,
        private JournalService $journalService,
        private string $baseUrl,
        private string $siteName,
        private DecisionEmailService $decisionEmails
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
     * Sends — or resends — the acceptance e-mail of an accepted (or
     * encoded) request.
     *
     * @throws RegistrationException when the request is not accepted,
     *         another send of it is in flight, the body hasn't been written
     *         yet, or the transport failed — the last two recorded as a
     *         failed attempt
     */
    public function sendAccepted(RegistrationRequest $request, string $targetYearLabel): void
    {
        $this->deliver($request, DecisionEmail::DECISION_ACCEPTED, $targetYearLabel);
    }

    /**
     * Sends — or resends — the refusal e-mail of a refused request.
     *
     * @throws RegistrationException as sendAccepted()
     */
    public function sendRefused(RegistrationRequest $request, string $targetYearLabel): void
    {
        $this->deliver($request, DecisionEmail::DECISION_REFUSED, $targetYearLabel);
    }

    /**
     * One attempt, from the claim to its recorded outcome.
     *
     * @throws RegistrationException
     */
    private function deliver(RegistrationRequest $request, string $decision, string $targetYearLabel): void
    {
        $email = self::EMAILS[$decision];
        $attempt = $this->decisionEmails->begin($request, $decision);

        // The guard D10 keeps: the template is checked on the claimed
        // attempt, so a send that cannot happen leaves a failed attempt
        // behind — what the automatic sending will do too — and never a
        // success.
        if (!$this->hasContent($email['key'])) {
            $this->decisionEmails->recordFailure($attempt, DecisionEmail::FAILURE_BODY_NOT_WRITTEN, $email['missing']);

            throw new RegistrationException($email['missing']);
        }

        try {
            $trackingToken = $this->send($request, $email['key'], $email['subject'], $targetYearLabel);
        } catch (RegistrationException $e) {
            // Gated here, at the write: the row keeps a sentence a chief
            // may read, never the transport's own text (send() journals
            // that).
            $this->decisionEmails->recordFailure(
                $attempt,
                DecisionEmail::FAILURE_MAIL_TRANSPORT,
                UserFacingMessage::from($e, "L'e-mail n'a pas pu être envoyé à la famille.")
            );

            throw $e;
        }

        // The message is out: that is recorded first, so nothing after it
        // can leave a delivered e-mail looking unsent. Only then does the
        // link it carries replace the family's previous one (R6) — a
        // failure there leaves the old link working, never a dead one.
        $recorded = $this->decisionEmails->recordSuccess($attempt);
        $this->repository->storeTrackingTokenHash($request->id, $trackingToken);

        $this->journalService->log(
            'registration',
            $email['event'],
            'info',
            $email['journal'],
            ['request_id' => $request->id, 'attempt' => $attempt->attempt, 'resend' => $attempt->isResend]
        );
        if (!$recorded) {
            $this->journalService->log(
                'registration',
                'registration_decision_email_outcome_lost',
                'warning',
                "Un e-mail de décision est parti, mais son envoi n'a pas pu être consigné dans l'historique",
                ['request_id' => $request->id, 'email_id' => $attempt->id]
            );
        }
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
     *         RegistrationRequestController shows the error, deliver()
     *         records the attempt as failed, and retrying is always
     *         available).
     *
     * @return string the raw tracking token the e-mail carries, for
     *         deliver() to persist once the send is recorded
     */
    private function send(
        RegistrationRequest $request,
        string $contentKey,
        string $subject,
        string $targetYearLabel
    ): string
    {
        // The token is minted here but only PERSISTED once the mail is
        // actually out (deliver()). Persisting first — what this method
        // used to do — meant a delivery failure killed the link the family
        // already had while never handing them the replacement.
        $trackingToken = CapabilityToken::generate();
        $trackingUrl = rtrim($this->baseUrl, '/') . "/inscriptions/suivi/{$request->id}/{$trackingToken}";

        $body = $this->substitute((string) $this->editableContentService->get($contentKey), [
            'prenom_enfant' => $request->childFirstName,
            'annee_scoute' => $targetYearLabel,
            'lien_suivi' => $trackingUrl,
            'nom_unite' => $this->siteName,
        ]);

        try {
            $this->mailService->send(
                to: $request->email,
                subject: $subject,
                bodyHtml: $body,
                bodyText: self::toPlainText($body)
            );
        } catch (MailException $e) {
            // Nothing was written: the previous tracking link still works and
            // the attempt is recorded as failed, so retrying is free. Worth
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
                "L'email n'a pas pu être envoyé à la famille — le lien de suivi précédent reste valable, réessayez "
                    . "dans quelques instants.",
                0,
                $e
            );
        }

        return $trackingToken;
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
