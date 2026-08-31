<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Notification\NotificationService;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Provider\ProviderException;

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
 *  - sendAdminAlert() stays a direct email. It is a technical alert
 *    addressed to a ROLE (the first superadmin), not to a person, and it
 *    must reach somebody precisely when the module is misconfigured.
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

    public function __construct(
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private MemberService $memberService,
        private UserAccountRepository $userAccountRepository,
        private MailService $mailService,
        private JournalService $journalService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private ?NotificationService $notificationService = null
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
            $this->sendAdminAlert($message);
            throw new SosException($message);
        }

        $targetNumber = $this->resolveNumber($newMemberId, $scoutYearId);
        if ($targetNumber === null) {
            $message = 'Numéro de redirection introuvable (ni garde ni numéro par défaut configuré).';
            $this->logOutcome('failure', $message, $newMemberId);
            $this->sendAdminAlert($message);
            throw new SosException($message);
        }

        try {
            $current = $provider->readForwardingState();
            if ($current->active && $current->number === $targetNumber) {
                $this->logOutcome('no_change', "Redirection déjà correcte ({$targetNumber}).", $newMemberId);
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
            $this->sendAdminAlert($detailed);
            throw new SosException(
                'Le changement de redirection du numéro SOS a échoué — vérifiez la configuration du fournisseur de téléphonie, puis réessayez.',
                0,
                $e
            );
        }

        $this->logOutcome('success', "Redirection changée vers {$targetNumber}.", $newMemberId);
        $this->notifyHandover($newMemberId, $previousMemberId, $scoutYearId);
    }

    private function resolveNumber(?int $memberId, int $scoutYearId): ?string
    {
        if ($memberId === null) {
            return $this->settingsService->getDefaultNumber($scoutYearId);
        }

        $profile = $this->memberService->findProfileByMemberAndYear($memberId, $scoutYearId);
        return $profile?->mobile ?? $this->settingsService->getDefaultNumber($scoutYearId);
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
     * One handover notification, for the member behind one profile.
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

        $email = $profile->email;
        if ($email === null || trim($email) === '') {
            return;
        }

        try {
            $account = $this->userAccountRepository->findByEmail($email);
            if ($account === null) {
                return;
            }

            $this->notificationService->dispatch(
                $typeId,
                [['userAccountId' => $account->id, 'memberId' => $profile->memberId]],
                ['title' => $title, 'body' => $body, 'url' => '/admin/sos']
            );
        } catch (\Throwable $e) {
            // A notification failing is not itself a redirect failure — the
            // phone forwarding already succeeded — but it's worth a journal
            // entry so it doesn't vanish silently. Scrub the recipient
            // address out of the reason first: the journal must never carry
            // personal data (SECURITY.md §11), same pattern as
            // MemberEmailService/AuthService.
            $reason = str_replace($email, '[adresse]', $e->getMessage());
            $this->journalService->log(
                'sos_staff',
                'notification_failed',
                'info',
                "Échec de la notification de changement de garde : {$reason}",
                ['member_id' => $profile->memberId, 'type_id' => $typeId]
            );
        }
    }

    private function sendAdminAlert(string $message): void
    {
        try {
            $admin = $this->userAccountRepository->findFirstSuperAdmin();
            if ($admin === null) {
                return;
            }

            // Through the register (ARCHITECTURE.md §8.7bis) rather than
            // Twig directly, so a unit that reworded this alert gets its
            // own words. With no customisation the renderer renders the
            // same two templates with the same context, which is what
            // makes this a switch of path and not of behaviour.
            $email = $this->emailTemplateRenderer->render('sos_staff.admin_alert', ['message' => $message]);

            $this->mailService->send(
                to: $admin->email,
                subject: $email->subject,
                bodyHtml: $email->bodyHtml,
                bodyText: $email->bodyText
            );
        } catch (\Throwable $e) {
            // Best-effort — already journaled by the caller regardless; a
            // failure to resolve/notify the admin must never mask the
            // original failure being reported here.
        }
    }

    private function logOutcome(string $outcome, string $description, ?int $memberId): void
    {
        $this->journalService->log(
            'sos_staff',
            "redirect_{$outcome}",
            'info',
            $description,
            ['member_id' => $memberId]
        );
    }
}
