<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Config\AppClock;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\MemberProfile;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberService;
use Core\Notification\NotificationService;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Api\ProviderException;

/**
 * The redirect-change sequence (module spec §4): anti-duplicate check,
 * change, post-change verification, handover notifications, journaling.
 * Used both by Task\ApplyRedirectHandler (scheduled transitions) and
 * directly by the admin controller for "application immédiate" (§3).
 *
 * A genuine technical failure is journaled and alerted by email here, then
 * re-thrown as SosException — the caller (SchedulerRunner for scheduled
 * runs, the controller for immediate ones) is what decides how a failure
 * surfaces (scheduled_actions.status = 'failed', an HTTP error, etc.); this
 * service never silently swallows a failure, since that would hide it from
 * the "Actions planifiées" status too.
 *
 * Two audiences, two mechanisms, deliberately:
 *
 *  - The handover pair (whoever takes the duty, whoever ends it) is a
 *    PERSONAL notification about that one person, so it goes through
 *    Core\Notification\NotificationService::dispatch() with the two types
 *    module.json declares. That is what puts it in the notification
 *    centre, in push, and on /notifications/preferences — none of which
 *    it reached while it was a direct MailService::send(). The module's
 *    `email_notifications_enabled` setting stays as the GLOBAL switch in
 *    front of that dispatch; the per-channel choice is each member's.
 *  - sendAdminAlert() is addressed to a ROLE: every chef d'unité of the
 *    year, and the super-administrators (issue #1102 — it used to reach
 *    the first superadmin alone, often a technician outside the unit's
 *    staff). A direct email to each, which no preference can turn off
 *    because it must arrive precisely when the line is wrong, plus the
 *    `sos_staff.redirect_failed` notification for the bell and push.
 *
 * $notificationService is nullable for the same reason every optional
 * dependency in this codebase is (ARCHITECTURE.md §7.5): a narrow unit
 * test, or a caller with no notification stack, degrades to "no handover
 * notification" rather than to a fatal.
 */
class RedirectService
{
    private const TYPE_ONCALL_STARTED = 'sos_staff.oncall_started';
    private const TYPE_ONCALL_ENDED = 'sos_staff.oncall_ended';
    private const TYPE_REDIRECT_FAILED = 'sos_staff.redirect_failed';

    public function __construct(
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private MemberService $memberService,
        private UserAccountRepository $userAccountRepository,
        private MailService $mailService,
        private JournalService $journalService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private ?NotificationService $notificationService,
        private MemberAccountResolver $memberAccounts
    ) {
    }

    /**
     * @throws SosException on any technical failure (already journaled and
     *                       alerted before being thrown)
     */
    public function apply(?int $newMemberId, ?int $previousMemberId, int $scoutYearId): void
    {
        $provider = $this->providerConfigService->getActiveProvider();
        if ($provider === null) {
            $message = 'Aucun fournisseur de téléphonie actif configuré.';
            $this->logOutcome('failure', $message, $newMemberId);
            $this->sendAdminAlert($message, $scoutYearId);
            throw new SosException($message);
        }

        $destination = $this->resolveDestination($newMemberId, $scoutYearId);
        $rawNumber = $destination['number'];
        $unreachableMemberId = $destination['unreachable_member_id'];
        // The Desk fact first, before the operator is asked anything: it is
        // true whether or not the line can be moved (issue #1260).
        $this->noteUnreachablePlannedMember($unreachableMemberId, $scoutYearId);
        if ($rawNumber === null) {
            $message = 'Numéro de redirection introuvable (ni garde ni numéro par défaut configuré).';
            $this->logOutcome('failure', $message, $newMemberId);
            $this->sendAdminAlert($message, $scoutYearId);
            throw new SosException($message);
        }

        // Sent normalised, never as Desk holds it (issue #1125). A number
        // that is no phone number is refused here rather than handed to the
        // operator to guess at. Since #1260 a PERSON's GSM can no longer
        // reach this point — resolveDestination() already set it aside for
        // the default number — so what is refused is the one number nothing
        // can replace: a default number that is itself no number. The
        // holder is named in the alert to the chefs d'unité only: the
        // journal and the exception carry no personal data (SECURITY.md
        // §11), the member id is in the context.
        $targetNumber = PhoneNumber::normalize($rawNumber);
        if ($targetNumber === null) {
            $message = "Le GSM du destinataire prévu dans Desk n'est pas un numéro de téléphone valide : la "
                . "redirection du numéro SOS n'a pas été modifiée. Corrigez le GSM dans Desk, puis réimportez.";
            $this->logOutcome('failure', $message, $newMemberId);
            $this->sendAdminAlert(
                $this->unusableNumberMessage($unreachableMemberId === null ? $newMemberId : null, $scoutYearId),
                $scoutYearId
            );
            throw new SosException($message);
        }

        try {
            $current = $provider->readForwardingState();
            if ($current->active && PhoneNumber::same($current->number, $targetNumber)) {
                $this->logOutcome('no_change', 'Redirection déjà correcte.', $newMemberId);
                $this->settingsService->recordRedirectOutcome(false, AppClock::now());
                return;
            }

            $provider->setForwarding($targetNumber);

            $confirmed = $provider->readForwardingState();
            // The operator answers in its own format: compared as numbers,
            // not as strings (issue #1125).
            if (!$confirmed->active || !PhoneNumber::same($confirmed->number, $targetNumber)) {
                throw new ProviderException("La redirection n'a pas été appliquée correctement (état non confirmé).");
            }
        } catch (ProviderException $e) {
            // Two different audiences, deliberately two different strings.
            // The journal entry and the alert mail are read by someone
            // debugging the telephony provider, so they keep the provider's
            // own account of the failure; the exception carries only what a
            // page may render, with $e as $previous so nothing is lost.
            $detailed = "Échec du changement de redirection : {$e->getMessage()}";
            $this->logOutcome('failure', $detailed, $newMemberId);
            // One alert per outage, not one per attempt: the retries and the
            // hourly check (Task\ReconcileRedirectHandler) would otherwise
            // mail every chef d'unité several times an hour for as long as
            // the operator refuses. The attention point stays up, urgent,
            // until a change succeeds or the line reads back as expected.
            $alreadyFailing = $this->settingsService->lastRedirectFailureAt() !== null;
            // Kept for the attention page (#718 § C point 9) — this branch
            // only: the operator was asked and refused. No operator, or no
            // number to send calls to, is a configuration gap the attention
            // page reads from the configuration itself, and recording it
            // here would surface later as « l'opérateur a refusé ».
            $this->settingsService->recordRedirectOutcome(true, AppClock::now());
            if (!$alreadyFailing) {
                $this->sendAdminAlert($detailed, $scoutYearId);
            }
            throw new SosException(
                'Le changement de redirection du numéro SOS a échoué — vérifiez la configuration du fournisseur de '
                    . 'téléphonie, puis réessayez.',
                0,
                $e
            );
        }

        $this->logOutcome('success', 'Redirection modifiée.', $newMemberId);
        // Only once the operator confirmed the line really moved: a failed or
        // unchanged redirection is no fallback transition (review of #1194).
        $this->journalIgnoredDefaultNumber($newMemberId, $unreachableMemberId, $scoutYearId);
        $this->settingsService->recordRedirectOutcome(false, AppClock::now());
        // The member whose GSM is no number is not told the line points to
        // their phone: it points to the default number (issue #1260).
        $this->notifyHandover($newMemberId, $previousMemberId, $scoutYearId, $unreachableMemberId);
    }

    /**
     * The operator could not even be read (expired credentials, an API
     * down): an outage like a refused change, reported the same way — the
     * urgent attention point, and the alert to every chef d'unité once per
     * outage. Cleared by the next redirection that succeeds or the next
     * reading that finds the line where it should be.
     */
    public function reportUnreadableLine(int $scoutYearId): void
    {
        $alreadyFailing = $this->settingsService->lastRedirectFailureAt() !== null;
        $this->settingsService->recordRedirectOutcome(true, AppClock::now());
        if (!$alreadyFailing) {
            $this->sendAdminAlert(
                "L'état de la redirection du numéro SOS n'a pas pu être lu chez l'opérateur : le site ne peut "
                    . 'ni vérifier ni modifier la redirection. Vérifiez la configuration du fournisseur de '
                    . 'téléphonie.',
                $scoutYearId
            );
        }
    }

    /**
     * The number a redirection to $memberId means — the member's mobile when
     * it is a phone number, else the default number (the member is null, has
     * no mobile, or a mobile that is no number: issue #1260). apply() takes
     * its target from here, and so does the dashboard when it decides
     * whether the provider's real state is the expected one (issue #1026):
     * two readings of « where should the calls go » would be two rules.
     * Null means no valid destination exists at all.
     */
    public function resolveNumber(?int $memberId, int $scoutYearId): ?string
    {
        return $this->resolveDestination($memberId, $scoutYearId)['number'];
    }

    /**
     * resolveNumber(), and WHO the calls could not follow: the planned
     * member whose Desk GSM is there but is no phone number (issue #1260).
     * An absent GSM falls back to the default number as it always did, and
     * says nothing more — nobody typed a wrong number — which is also why
     * the one case that is a Desk mistake is the one the chefs d'unité hear
     * about.
     *
     * The default number is only ever a Staff d'U GSM PhoneNumber can
     * normalise, else the first such member's (SosSettingsService::
     * getDefaultNumber()), so what a person's invalid GSM falls back to is
     * a number the operator can be given.
     *
     * @return array{number: ?string, unreachable_member_id: ?int}
     */
    public function resolveDestination(?int $memberId, int $scoutYearId): array
    {
        if ($memberId === null) {
            return [
                'number' => $this->settingsService->getDefaultNumber($scoutYearId),
                'unreachable_member_id' => null,
            ];
        }

        $mobile = $this->memberService->findProfileByMemberAndYear($memberId, $scoutYearId)?->mobile;
        if ($mobile !== null && PhoneNumber::normalize($mobile) !== null) {
            return ['number' => $mobile, 'unreachable_member_id' => null];
        }

        return [
            'number' => $this->settingsService->getDefaultNumber($scoutYearId),
            'unreachable_member_id' => $mobile !== null && trim($mobile) !== '' ? $memberId : null,
        ];
    }

    /**
     * Keeps what resolveDestination() found about the person on duty (issue
     * #1260): the urgent attention point reads it, and the chefs d'unité are
     * alerted the first time a given member is found unreachable — not on
     * every hourly pass while Desk still holds the wrong GSM, and not again
     * after a redirection that only restated it. Called from apply() and from
     * every reconciliation, because the line can already sit on the default
     * number, in which case no change is made and apply() never runs.
     *
     * A reachable person on duty clears it: the next pass after Desk was
     * corrected and re-imported.
     */
    public function noteUnreachablePlannedMember(?int $unreachableMemberId, int $scoutYearId): void
    {
        if ($this->settingsService->unreachablePlannedMemberId() === $unreachableMemberId) {
            return;
        }

        $this->settingsService->recordUnreachablePlannedMember($unreachableMemberId);
        if ($unreachableMemberId === null) {
            return;
        }

        // The member's id only: no name, no number (SECURITY.md §11).
        $this->journalService->log(
            'sos_staff',
            'redirect_planned_gsm_unusable',
            'warning',
            "Le GSM de la personne de garde n'est pas un numéro valide : "
                . 'le numéro SOS ne peut pas lui être redirigé.',
            ['member_id' => $unreachableMemberId]
        );
        $label = $this->settingsService->labelForMember($unreachableMemberId, $scoutYearId);
        $this->sendAdminAlert(
            'Le GSM de ' . ($label ?? 'la personne de garde') . " dans Desk n'est pas un numéro de téléphone "
                . 'valide : cette personne est de garde, mais le numéro SOS ne peut pas lui être redirigé (il '
                . 'reste sur le numéro par défaut, ou sur son précédent destinataire à défaut). '
                . 'Corrigez le GSM dans Desk, puis réimportez.',
            $scoutYearId
        );
    }

    /**
     * The sentence a refused GSM is alerted with: who, never the number. It
     * goes to the e-mail every chef d'unité receives, and nowhere else —
     * never the journal nor an exception (review of #1194).
     */
    private function unusableNumberMessage(?int $memberId, int $scoutYearId): string
    {
        $holderId = $memberId;
        if ($holderId === null) {
            $holderId = $this->settingsService->resolveDefaultNumberMemberId($scoutYearId);
        }
        $label = $holderId !== null ? $this->settingsService->labelForMember($holderId, $scoutYearId) : null;

        return 'Le GSM de ' . ($label ?? 'la personne de garde') . " dans Desk n'est pas un numéro de "
            . 'téléphone valide : la redirection du numéro SOS n\'a pas été modifiée. Corrigez le GSM dans Desk, '
            . 'puis réimportez.';
    }

    /**
     * A default number a chef d'unité chose, set aside because its holder
     * left the Staff d'U (issue #1125), is journaled when the line actually
     * moved to the fallback, confirmed by the operator — the attention
     * point says it on the page.
     */
    private function journalIgnoredDefaultNumber(?int $memberId, ?int $unreachableMemberId, int $scoutYearId): void
    {
        // The line used the member's OWN number only when they have one the
        // site can read: a mobile that is there but is no phone number sent
        // the line to the default number all the same (issue #1260, review
        // of #1375), so « has a mobile » is not the test it used to be.
        $usedOwnNumber = $memberId !== null
            && $unreachableMemberId === null
            && $this->memberService->findProfileByMemberAndYear($memberId, $scoutYearId)?->mobile;
        if ($usedOwnNumber) {
            return;
        }

        $stale = $this->settingsService->staleDefaultNumberMemberId($scoutYearId);
        if ($stale === null) {
            return;
        }

        $this->journalService->log(
            'sos_staff',
            'default_number_ignored',
            'warning',
            "Le numéro par défaut choisi n'est plus au Staff d'U de l'année : la ligne utilise le numéro de repli.",
            [
                'chosen_member_id' => $stale,
                'used_member_id' => $this->settingsService->resolveDefaultNumberMemberId($scoutYearId),
            ]
        );
    }

    /**
     * The handover pair. Unchanged rule, changed output channel: nobody is
     * told when the two are the same person, and a null side is nobody at
     * all (the default number governs that day, and a number has no inbox).
     */
    private function notifyHandover(
        ?int $newMemberId,
        ?int $previousMemberId,
        int $scoutYearId,
        ?int $unreachableMemberId = null
    ): void {
        if (!$this->settingsService->isEmailNotificationsEnabled()) {
            return;
        }

        // « Vous êtes de garde » is skipped for the person planned on duty whose
        // GSM could not be used: the line went to the default number, and saying
        // it points to them would be false. The previous holder is still told
        // their duty ended.
        if ($newMemberId !== null && $newMemberId !== $previousMemberId && $newMemberId !== $unreachableMemberId) {
            $this->dispatchHandover(
                self::TYPE_ONCALL_STARTED,
                $this->memberService->findProfileByMemberAndYear($newMemberId, $scoutYearId),
                'Vous êtes de garde SOS',
                "La redirection du numéro SOS de l'unité pointe vers vous."
            );
        }

        if ($previousMemberId !== null && $previousMemberId !== $newMemberId) {
            $this->dispatchHandover(
                self::TYPE_ONCALL_ENDED,
                $this->memberService->findProfileByMemberAndYear($previousMemberId, $scoutYearId),
                'Fin de votre garde SOS',
                "Le numéro SOS de l'unité n'est plus redirigé vers votre téléphone."
            );
        }
    }

    /**
     * One handover notification for each account linked to this member,
     * through its Desk address or a currently validated secondary address.
     *
     * The payload deliberately carries NO phone number, where the emails
     * this replaced named the target one. A push notification renders on a
     * lock screen, outside the site's access control (SECURITY.md §19), and
     * the number adds nothing the recipient does not already know: it is
     * their own mobile, and the page names it anyway.
     *
     * A member with no account on this site is not a recipient — the honest
     * outcome, and the same one the direct email had for a member with no
     * address.
     */
    private function dispatchHandover(string $typeId, ?MemberProfile $profile, string $title, string $body): void
    {
        if ($this->notificationService === null || $profile === null) {
            return;
        }

        try {
            $accountIds = $this->memberAccounts->accountIdsForMember($profile->memberId);
            if ($accountIds === []) {
                $this->journalService->log(
                    'sos_staff',
                    'notification_skipped',
                    'info',
                    'Notification de changement de garde non créée : aucun compte lié.',
                    ['member_id' => $profile->memberId, 'type_id' => $typeId, 'reason' => 'no_account']
                );
                return;
            }

            $this->notificationService->dispatch(
                $typeId,
                array_map(
                    static fn(int $id): array => ['userAccountId' => $id, 'memberId' => $profile->memberId],
                    $accountIds
                ),
                ['title' => $title, 'body' => $body, 'url' => '/admin/sos']
            );
        } catch (\Throwable $e) {
            // A notification failing is not itself a redirect failure — the
            // phone forwarding already succeeded — but it's worth a journal
            // entry so it doesn't vanish silently. Exception text can carry
            // secondary addresses or phone numbers: keep only identifiers
            // and the exception class, never the message (SECURITY.md §11).
            $this->journalService->log(
                'sos_staff',
                'notification_failed',
                'error',
                'Échec de la notification de changement de garde.',
                ['member_id' => $profile->memberId, 'type_id' => $typeId, 'exception_class' => $e::class]
            );
        }
    }

    /**
     * Every chef d'unité of the year and every super-administrator — the
     * people who can act on the planning or the operator — by email, and
     * by notification for those who follow the site on their phone.
     *
     * Best-effort for the caller, which has already journaled the failure
     * and is about to report it: an alert that cannot be sent must never
     * mask it. But not silent — an alert nobody received is itself worth a
     * journal line, or « the chefs were warned » is a belief nobody can
     * check (issue #1102).
     */
    private function sendAdminAlert(string $message, int $scoutYearId): void
    {
        try {
            $accountIds = $this->userAccountRepository->findStaffAndSuperAdminIds([$scoutYearId], ['admin']);
        } catch (\Throwable $e) {
            $this->logAlertFailure('recipients', $e);
            return;
        }
        if ($accountIds === []) {
            $this->journalService->log(
                'sos_staff',
                'admin_alert_skipped',
                'warning',
                "Alerte de redirection SOS non envoyée : aucun chef d'unité ni superadmin.",
                []
            );
            return;
        }

        try {
            // Through the register (ARCHITECTURE.md §8.7bis) rather than
            // Twig directly, so a unit that reworded this alert gets its
            // own words.
            $email = $this->emailTemplateRenderer->render('sos_staff.admin_alert', ['message' => $message]);
            foreach ($accountIds as $accountId) {
                // One recipient at a time, the lookup included: an address
                // that cannot be decrypted must not cost every chef after it
                // their alert.
                try {
                    $account = $this->userAccountRepository->findById($accountId);
                    if ($account === null) {
                        continue;
                    }
                    $this->mailService->send(
                        to: $account->email,
                        subject: $email->subject,
                        bodyHtml: $email->bodyHtml,
                        bodyText: $email->bodyText
                    );
                } catch (\Throwable $e) {
                    $this->logAlertFailure('email', $e);
                }
            }
        } catch (\Throwable $e) {
            $this->logAlertFailure('email', $e);
        }

        if ($this->notificationService === null) {
            return;
        }
        try {
            // No operator message in the payload: a push renders on a lock
            // screen, and the provider's text can carry a phone number.
            $this->notificationService->dispatch(
                self::TYPE_REDIRECT_FAILED,
                array_map(static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null], $accountIds),
                [
                    'title' => 'Redirection du numéro SOS en échec',
                    'body' => "Le numéro SOS n'a pas pu être redirigé vers la personne de garde. "
                        . 'Vérifiez la redirection.',
                    'url' => '/admin/sos',
                ]
            );
        } catch (\Throwable $e) {
            $this->logAlertFailure('notification', $e);
        }
    }

    private function logAlertFailure(string $channel, \Throwable $e): void
    {
        // The class only, never the message: it can carry an address.
        $this->journalService->log(
            'sos_staff',
            'admin_alert_failed',
            'warning',
            "Échec de l'envoi de l'alerte de redirection SOS.",
            ['channel' => $channel, 'exception_class' => $e::class]
        );
    }

    private function logOutcome(string $outcome, string $description, ?int $memberId): void
    {
        $this->journalService->log(
            'sos_staff',
            "redirect_{$outcome}",
            // A failed redirection is the emergency line ringing in the
            // wrong place: never an `info` among the routine lines.
            $outcome === 'failure' ? 'warning' : 'info',
            $description,
            ['member_id' => $memberId]
        );
    }
}
