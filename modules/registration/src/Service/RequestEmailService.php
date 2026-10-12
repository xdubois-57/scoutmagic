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
 * Acceptance/refusal emails — sent by hand from the fiche, or, when the
 * automatic sending is on, at the due date planned when the decision was
 * taken (issue #843, IT-09: sendScheduled(), called by Task\
 * SendDecisionEmailsHandler) — never before their body has actually
 * been written (isAcceptedBodyReady()/isRefusedBodyReady() are the guard;
 * the fiche's « E-mail de décision » block shows « Gabarit à rédiger » and
 * no button on it — unlike the itération 4 receipt/unit-alert emails,
 * these ship with NO default body: a chief must write one, or the request
 * stays visibly "en cours" to the parent forever, per Service\
 * RequestStatusService::visibleStatus()).
 *
 * The fiche's block is panel(), and its three buttons are
 * sendFromRequestPage() (issue #843, IT-08).
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
    private const ACCEPTED_KEY = RequestEmailTemplateService::KEY_ACCEPTED;
    private const REFUSED_KEY = RequestEmailTemplateService::KEY_REFUSED;

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

    /**
     * What a fiche button answers when the block no longer offers it.
     *
     * @var array<string, string>
     */
    private const ACTION_REFUSED = [
        DecisionEmailPanel::ACTION_SEND_NOW => "Cet e-mail ne peut plus être envoyé d'ici : il est déjà parti, en "
            . "cours d'envoi ou en échec. Rechargez la fiche pour voir où il en est.",
        DecisionEmailPanel::ACTION_RETRY => "Il n'y a pas d'échec à réessayer : rechargez la fiche pour voir où en est "
            . "cet e-mail.",
        DecisionEmailPanel::ACTION_RESEND => "Cet e-mail n'est pas encore parti, ou un envoi est en cours : il ne peut "
            . "pas être renvoyé. Rechargez la fiche pour voir où il en est.",
    ];

    private const ACTION_REFUSED_UNKNOWN = "Action inconnue : rien n'a été envoyé.";

    private const AUTOMATIC_NOT_STARTED_MESSAGE = "L'envoi automatique de cet e-mail n'a pas pu être lancé : rien "
        . "n'est parti. « Réessayer » le relance.";

    private const AUTOMATIC_INTERRUPTED_MESSAGE = "L'envoi automatique a été interrompu avant d'aboutir : il n'est "
        . 'pas certain que la famille ait reçu cet e-mail.';

    /** What sendScheduled() did with a due e-mail. */
    public const SCHEDULED_SENT = 'sent';
    public const SCHEDULED_FAILED = 'failed';
    public const SCHEDULED_SKIPPED = 'skipped';

    public function __construct(
        private RegistrationRequestRepository $repository,
        private MailService $mailService,
        private EditableContentService $editableContentService,
        private JournalService $journalService,
        private string $baseUrl,
        private string $siteName,
        private DecisionEmailService $decisionEmails,
        // The unit in copy of both decision e-mails — manual send, retry
        // and resend alike, all of which go through send() below
        // (chantier #843, D9). Null is no copy.
        private ?FamilyEmailCopyService $familyEmailCopy = null
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
     * Whether the body of the $decision e-mail (DecisionEmail::DECISION_*)
     * is written — false for an unknown decision.
     */
    public function isBodyReady(string $decision): bool
    {
        return isset(self::EMAILS[$decision]) && $this->hasContent(self::EMAILS[$decision]['key']);
    }

    /**
     * The « E-mail de décision » block of $request's fiche (issue #843,
     * IT-08), or null when the request holds no decision.
     */
    public function panel(RegistrationRequest $request): ?DecisionEmailPanel
    {
        $decision = DecisionEmail::decisionForStatus($request->status);
        if ($decision === null) {
            return null;
        }

        return DecisionEmailPanel::build(
            $request,
            $this->decisionEmails->history($request->id),
            $this->hasContent(self::EMAILS[$decision]['key']),
            new \DateTimeImmutable()
        );
    }

    /**
     * One of the fiche's three buttons — « Envoyer maintenant »,
     * « Réessayer », « Renvoyer l'e-mail » (DecisionEmailPanel::ACTION_*) —
     * on the e-mail of the decision $request holds now. The action must be
     * one the block offers at this moment: a second « Envoyer maintenant »
     * posted after the first went out (a double click that arrived late, a
     * form sent again from the browser history) is refused instead of
     * becoming a resend nobody asked for. Then the one send path below,
     * copy to the unit included (D9).
     *
     * Journaled before the send, with the action — the outcome journals
     * itself (sent, or the transport's failure).
     *
     * @return string the decision whose e-mail left (DecisionEmail::DECISION_*)
     *
     * @throws RegistrationException when the action is not offered, or the
     *         send fails (recorded as a failed attempt)
     */
    public function sendFromRequestPage(RegistrationRequest $request, string $action, string $targetYearLabel): string
    {
        $panel = $this->panel($request);
        if ($panel === null) {
            throw new RegistrationException(
                "Cette demande n'a pas de décision : aucun e-mail de décision ne peut partir."
            );
        }
        // Before the action check: a template emptied since the page was
        // loaded is the reason, whatever the state (a sent e-mail included).
        if (!$panel->templateReady) {
            throw new RegistrationException(self::EMAILS[$panel->decision]['missing']);
        }
        if (!$panel->allows($action)) {
            throw new RegistrationException(self::ACTION_REFUSED[$action] ?? self::ACTION_REFUSED_UNKNOWN);
        }

        $this->journalService->log(
            'registration',
            'registration_decision_email_requested',
            'info',
            "Envoi d'un e-mail de décision demandé depuis la fiche",
            ['request_id' => $request->id, 'decision' => $panel->decision, 'action' => $action]
        );
        $this->deliver($request, $panel->decision, $targetYearLabel);

        return $panel->decision;
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
     * The automatic sending of one scheduled e-mail that has come due
     * (issue #843, IT-09) — what Task\SendDecisionEmailsHandler does for each
     * row DecisionEmailService::findDue() returned. $yearLabel gives the
     * label of a scout year by its id (the request's target year, which the
     * e-mail names).
     *
     * **That very row is claimed first, by its id**, and nothing else is
     * ever sent in its place: a row somebody else claimed meanwhile (a
     * chief's « Envoyer maintenant », another scheduler pass) or cancelled
     * (a change of decision) is SKIPPED. Going through begin() instead
     * would have created a fresh attempt when the planned one was already
     * gone — a second e-mail for a send that had just happened. A claimed
     * row is never claimed again, so a pass interrupted after its claim
     * resumes without sending it twice: the next pass does not see it.
     *
     * The request is read AFTER the claim, so the decision it is checked
     * against is the one of that moment. Then the same path as a manual
     * send: the template guard (a body not written is a FAILED attempt,
     * never a success), the transport with the unit in copy (D9), the
     * outcome recorded, the tracking token rotated after a success only
     * (R6), journaled as automatic.
     *
     * **One e-mail never stops the others**: anything that goes wrong is
     * recorded on that row and answered as FAILED, so the task moves on. A
     * failure before the transport says nothing left; one during it says
     * it is not certain whether the family received it — the fiche offers
     * « Réessayer » either way, and nothing is resent on its own.
     *
     * @param callable(int): string $yearLabel
     * @return string self::SCHEDULED_* — sent, failed (recorded on the
     *         row), or skipped (not ours to send)
     */
    public function sendScheduled(DecisionEmail $due, callable $yearLabel): string
    {
        if (!$this->decisionEmails->claim($due->id)) {
            return self::SCHEDULED_SKIPPED;
        }

        try {
            $request = $this->repository->findById($due->requestId);
            // A claimed row still describes the request's decision — a
            // change supersedes it in the same transaction as the status —
            // so this only guards an inconsistent database: the e-mail of a
            // decision the family no longer has is never sent, and the row
            // is settled rather than left claimed.
            if ($request === null || DecisionEmail::decisionForStatus($request->status) !== $due->decision) {
                $this->decisionEmails->recordFailure(
                    $due,
                    DecisionEmail::FAILURE_INTERRUPTED,
                    self::AUTOMATIC_NOT_STARTED_MESSAGE
                );

                return self::SCHEDULED_SKIPPED;
            }
            $targetYearLabel = $yearLabel($request->scoutYearId);
        } catch (\Throwable $e) {
            $this->settleAutomaticError($due, self::AUTOMATIC_NOT_STARTED_MESSAGE, $e);

            return self::SCHEDULED_FAILED;
        }

        try {
            $this->deliverAttempt($request, $due->decision, $due, $targetYearLabel, true);
        } catch (RegistrationException) {
            // Recorded on the row by deliverAttempt(), and journaled.
            return self::SCHEDULED_FAILED;
        } catch (\Throwable $e) {
            // Something other than the mail transport's own refusal, at an
            // unknown point of the send: whether it left is not known. A
            // row already recorded (sent, failed) is left as it is.
            $this->settleAutomaticError($due, self::AUTOMATIC_INTERRUPTED_MESSAGE, $e);

            return self::SCHEDULED_FAILED;
        }

        return self::SCHEDULED_SENT;
    }

    /**
     * Records an automatic send that broke on something unexpected as
     * failed, with a sentence for the staff, and journals the error's class
     * — never its message, which may quote a family's data.
     */
    private function settleAutomaticError(DecisionEmail $due, string $message, \Throwable $error): void
    {
        try {
            $this->decisionEmails->recordFailure($due, DecisionEmail::FAILURE_INTERRUPTED, $message);
        } catch (\Throwable) {
            // The row stays claimed and reads « Envoi interrompu » once
            // stale (DecisionEmailPanel): still never resent on its own.
        }

        $this->journalService->log(
            'registration',
            'registration_decision_email_auto_send_error',
            'warning',
            "Erreur pendant l'envoi automatique d'un e-mail de décision",
            ['email_id' => $due->id, 'request_id' => $due->requestId, 'error' => $error::class]
        );
    }

    /**
     * One attempt, from the claim to its recorded outcome.
     *
     * @throws RegistrationException
     */
    private function deliver(RegistrationRequest $request, string $decision, string $targetYearLabel): void
    {
        $this->deliverAttempt(
            $request,
            $decision,
            $this->decisionEmails->begin($request, $decision),
            $targetYearLabel,
            false
        );
    }

    /**
     * Sends the $attempt its caller has claimed, and records its outcome.
     *
     * @throws RegistrationException when the body is not written or the
     *         transport fails — both recorded as a failed attempt
     */
    private function deliverAttempt(
        RegistrationRequest $request,
        string $decision,
        DecisionEmail $attempt,
        string $targetYearLabel,
        bool $automatic
    ): void {
        $email = self::EMAILS[$decision];

        // The guard D10 keeps: the template is checked on the claimed
        // attempt, so a send that cannot happen leaves a failed attempt
        // behind — the automatic sending included — and never a success.
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
        try {
            $recorded = $this->decisionEmails->recordSuccess($attempt);
        } catch (\Throwable) {
            // A database error here must not cost the family its new link:
            // the message is out, so the token it carries is stored all the
            // same, and the lost record is journaled below.
            $recorded = false;
        }
        $this->repository->storeTrackingTokenHash($request->id, $trackingToken);

        $this->journalService->log(
            'registration',
            $email['event'],
            'info',
            $email['journal'],
            [
                'request_id' => $request->id,
                'attempt' => $attempt->attempt,
                'resend' => $attempt->isResend,
                'automatic' => $automatic,
            ]
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

    /**
     * « À rédiger » has one definition, RequestEmailTemplateService::
     * isToWrite() — the very one the « Gabarits » page shows (issue #843,
     * D10). Built here from the content store this service already holds,
     * so the guard and the page can never disagree.
     */
    private function hasContent(string $key): bool
    {
        return !$this->templates()->isToWrite($key);
    }

    private function templates(): RequestEmailTemplateService
    {
        return new RequestEmailTemplateService($this->editableContentService);
    }

    /**
     * @throws RegistrationException on a mail delivery failure — unlike
     *         the best-effort submission emails (itération 4), a staff-
     *         triggered send must surface its own failure so the button
     *         doesn't silently claim success (Controller\
     *         RequestDecisionEmailController shows the error, deliver()
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

        $template = (string) $this->templates()->bodyFor($contentKey);
        $body = RequestEmailTemplateService::substitute($template, [
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
                bodyText: self::toPlainText($body),
                cc: $this->familyEmailCopy?->copyAddress()
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
}
