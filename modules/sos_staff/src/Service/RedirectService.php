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

        $targetNumber = $this->resolveNumber($newMemberId, $scoutYearId);
        if ($targetNumber === null) {
            $message = 'Numéro de redirection introuvable (ni garde ni numéro par défaut configuré).';
            $this->logOutcome('failure', $message, $newMemberId);
            $this->sendAdminAlert($message, $scoutYearId);
            throw new SosException($message);
        }

        try {
            $current = $provider->readForwardingState();
            if ($current->active && $current->number === $targetNumber) {
                $this->logOutcome('no_change', 'Redirection déjà correcte.', $newMemberId);
                $this->settingsService->recordRedirectOutcome(false, AppClock::now());
                return;
            }

            $provider->setForwarding($targetNumber);

            $confirmed = $provider->readForwardingState();
            if (!$confirmed->active || $confirmed->number !== $targetNumber) {
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
        $this->settingsService->recordRedirectOutcome(false, AppClock::now());
        $this->notifyHandover($newMemberId, $previousMemberId, $scoutYearId);
    }

    /**
     * The number a redirection to $memberId means — the member's mobile, or
     * the default number when the member is null or has none. apply() takes
     * its target from here, and so does the dashboard when it decides
     * whether the provider's real state is the expected one (issue #1026):
     * two readings of « where should the calls go » would be two rules.
     * Null means no valid destination exists at all.
     */
    public function resolveNumber(?int $memberId, int $scoutYearId): ?string
    {
        if ($memberId === null) {
            return $this->settingsService->getDefaultNumber($scoutYearId);
        }

        $profile = $this->memberService->findProfileByMemberAndYear($memberId, $scoutYearId);
        return $profile->mobile ?? $this->settingsService->getDefaultNumber($scoutYearId);
    }

    /**
     * The handover pair. Unchanged rule, changed output channel: nobody is
     * told when the two are the same person, and a null side is nobody at
     * all (the default number governs that day, and a number has no inbox).
     */
    private function notifyHandover(?int $newMemberId, ?int $previousMemberId, int $scoutYearId): void
    {
        if (!$this->settingsService->isEmailNotificationsEnabled()) {
            return;
        }

        if ($newMemberId !== null && $newMemberId !== $previousMemberId) {
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
                'info',
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
                $account = $this->userAccountRepository->findById($accountId);
                if ($account === null) {
                    continue;
                }
                try {
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
