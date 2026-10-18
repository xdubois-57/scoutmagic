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
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * Acceptance/refusal emails — sent by hand from the fiche, or, when the
 * automatic sending is on, at the due date planned when the decision was
 * taken (issue #843, IT-09: sendScheduled(), called by Task\
 * SendDecisionEmailsHandler). Both ship with a default body (issue #932), so
 * there is never a « Gabarit à rédiger » to wait on — but never without the
 * family's tracking link either: a stored body that lacks `{{ lien_suivi }}`
 * is a FAILED attempt (hasLink() is the guard), not an e-mail that leaves
 * silently without the one way back to the request.
 *
 * **The tracking link is the request's own, read back and never renewed**
 * (issue #932): the token was generated when the request was filed and is
 * the very one the receipt carried, so a decision e-mail, a resend, a retry
 * or an automatic send all carry the same URL — and none of them stores
 * anything on the request. Sent after the link has expired, the e-mail
 * still leaves, without extending anything, and says the public tracking is
 * no longer accessible.
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
     * @var array<string, array{key: string, subject: string, event: string, journal: string}>
     */
    private const EMAILS = [
        DecisionEmail::DECISION_ACCEPTED => [
            'key' => self::ACCEPTED_KEY,
            'subject' => "Votre demande d'inscription a été acceptée",
            'event' => 'registration_accepted_email_sent',
            'journal' => "Email d'acceptation envoyé",
        ],
        DecisionEmail::DECISION_REFUSED => [
            'key' => self::REFUSED_KEY,
            'subject' => "Votre demande d'inscription a été refusée",
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

    /** The sentence a chief reads when the stored body has lost its `{{ lien_suivi }}`. */
    private const BODY_WITHOUT_LINK_MESSAGE = "Le texte de cet e-mail ne contient pas le lien de suivi : il n'a pas "
        . "été envoyé. Ajoutez la variable {{ lien_suivi }} dans « Gabarits », ou rétablissez le texte par défaut.";

    /** The request has no readable tracking link (a row from before the link was stored). */
    private const NO_LINK_MESSAGE = "Le lien de suivi de cette demande n'est plus disponible : l'e-mail n'a pas été "
        . "envoyé, car il ne pourrait pas le contenir.";

    /** Appended to a decision e-mail sent once the family's link has expired (issue #932). */
    private const EXPIRED_LINK_NOTICE = "<p><strong>Information :</strong> le suivi public de cette demande n'est "
        . "plus accessible : le lien ci-dessus a expiré.</p>";

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
     *         another send of it is in flight, the body lacks the tracking
     *         link, or the transport failed — the last two recorded as a
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
     * send: the link guard (a body without the tracking link is a FAILED
     * attempt, never a success), the transport with the unit in copy (D9), the
     * outcome recorded, journaled as automatic.
     *
     * **One e-mail never stops the others**: anything that goes wrong is
     * recorded on that row and answered as FAILED, so the task moves on. A
     * failure before the transport says nothing left; one during it says
     * it is not certain whether the family received it — the fiche offers
     * « Réessayer » either way, and nothing is resent on its own.
     *
     * @param callable(int): string $yearLabel
     * @return string self::SCHEDULED_* — sent, failed (recorded on the
     *         row), or skipped (not ours to send: claimed or cancelled by
     *         somebody else, and left as it is)
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

                // Recorded failed, so counted failed: the run's journal
                // says what the rows say.
                return self::SCHEDULED_FAILED;
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
     * @throws RegistrationException when the body lacks the tracking link,
     *         the request has none to give, or the transport fails — all
     *         recorded as a failed attempt
     */
    private function deliverAttempt(
        RegistrationRequest $request,
        string $decision,
        DecisionEmail $attempt,
        string $targetYearLabel,
        bool $automatic
    ): void {
        $email = self::EMAILS[$decision];

        // The guards are checked on the claimed attempt, so a send that
        // cannot happen leaves a failed attempt behind — the automatic
        // sending included — and never a success. `body_not_written` is the
        // stored category for "this body cannot go out as it is".
        $template = $this->templates()->bodyFor($email['key']);
        if (!RequestEmailTemplateService::hasTrackingLink($template)) {
            $this->decisionEmails->recordFailure(
                $attempt,
                DecisionEmail::FAILURE_BODY_NOT_WRITTEN,
                self::BODY_WITHOUT_LINK_MESSAGE
            );

            throw new RegistrationException(self::BODY_WITHOUT_LINK_MESSAGE);
        }
        $link = $this->repository->trackingLinkOf($request->id);
        if ($link === null) {
            $this->decisionEmails->recordFailure(
                $attempt,
                DecisionEmail::FAILURE_BODY_NOT_WRITTEN,
                self::NO_LINK_MESSAGE
            );

            throw new RegistrationException(self::NO_LINK_MESSAGE);
        }

        try {
            $this->send($request, $template, $link, $email['key'], $email['subject'], $targetYearLabel);
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

        // The message is out: that is recorded, so nothing after it can
        // leave a delivered e-mail looking unsent. Nothing else is written
        // on the request — the family's link is the one it already had.
        try {
            $recorded = $this->decisionEmails->recordSuccess($attempt);
        } catch (\Throwable) {
            // A database error here must not look like a failed send: the
            // message is out, and the lost record is journaled below.
            $recorded = false;
        }

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

    private function templates(): RequestEmailTemplateService
    {
        return new RequestEmailTemplateService($this->editableContentService);
    }

    /**
     * @param array{token: string, expires_at: ?\DateTimeImmutable} $link the
     *        request's own tracking link (RegistrationRequestRepository::
     *        trackingLinkOf()) — read, never minted: the URL is the one the
     *        receipt carried
     *
     * @throws RegistrationException on a mail delivery failure — unlike
     *         the best-effort submission emails (itération 4), a staff-
     *         triggered send must surface its own failure so the button
     *         doesn't silently claim success (Controller\
     *         RequestDecisionEmailController shows the error, deliver()
     *         records the attempt as failed, and retrying is always
     *         available).
     */
    private function send(
        RegistrationRequest $request,
        string $template,
        array $link,
        string $contentKey,
        string $subject,
        string $targetYearLabel
    ): void {
        $trackingUrl = rtrim($this->baseUrl, '/') . "/inscriptions/suivi/{$request->id}/{$link['token']}";

        $body = RequestEmailTemplateService::substitute($template, [
            'prenom_enfant' => $request->childFirstName,
            'annee_scoute' => $targetYearLabel,
            'lien_suivi' => $trackingUrl,
            'nom_unite' => $this->siteName,
        ]);
        // Sent after the link expired: the message still leaves, the link
        // is not renewed and not extended, and the family is told so instead
        // of being left to find a dead page.
        if ($link['expires_at'] === null || new \DateTimeImmutable() >= $link['expires_at']) {
            $body .= self::EXPIRED_LINK_NOTICE;
        }

        try {
            $this->mailService->send(
                to: $request->email,
                subject: $subject,
                bodyHtml: $body,
                bodyText: self::toPlainText($body),
                cc: $this->familyEmailCopy?->copyAddress()
            );
        } catch (MailException $e) {
            // Nothing was written: the tracking link is the same one, and
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
                "L'email n'a pas pu être envoyé à la famille — réessayez dans quelques instants.",
                0,
                $e
            );
        }
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
