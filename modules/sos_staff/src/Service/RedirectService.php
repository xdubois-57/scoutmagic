<?php

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Provider\ProviderException;
use Twig\Environment;

/**
 * The redirect-change sequence (module spec §4): anti-duplicate check,
 * change, post-change verification, email notifications, journaling.
 * Used both by Task\ApplyRedirectHandler (scheduled transitions) and
 * directly by the admin controller for "application immédiate" (§3).
 *
 * A genuine technical failure is journaled and alerted by email here, then
 * re-thrown as SosException — the caller (SchedulerRunner for scheduled
 * runs, the controller for immediate ones) is what decides how a failure
 * surfaces (scheduled_actions.status = 'failed', an HTTP error, etc.); this
 * service never silently swallows a failure, since that would hide it from
 * the "Actions planifiées" status too.
 */
class RedirectService
{
    public function __construct(
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private MemberService $memberService,
        private UserAccountRepository $userAccountRepository,
        private MailService $mailService,
        private JournalService $journalService,
        private Environment $twig
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
            $message = "Échec du changement de redirection : {$e->getMessage()}";
            $this->logOutcome('failure', $message, $newMemberId);
            $this->sendAdminAlert($message);
            throw new SosException($message, 0, $e);
        }

        $this->logOutcome('success', "Redirection changée vers {$targetNumber}.", $newMemberId);
        $this->notifyHandover($newMemberId, $previousMemberId, $targetNumber, $scoutYearId);
    }

    private function resolveNumber(?int $memberId, int $scoutYearId): ?string
    {
        if ($memberId === null) {
            return $this->settingsService->getDefaultNumber($scoutYearId);
        }

        $profile = $this->memberService->findProfileByMemberAndYear($memberId, $scoutYearId);
        return $profile?->mobile ?? $this->settingsService->getDefaultNumber($scoutYearId);
    }

    private function notifyHandover(?int $newMemberId, ?int $previousMemberId, string $number, int $scoutYearId): void
    {
        if (!$this->settingsService->isEmailNotificationsEnabled()) {
            return;
        }

        if ($newMemberId !== null && $newMemberId !== $previousMemberId) {
            $profile = $this->memberService->findProfileByMemberAndYear($newMemberId, $scoutYearId);
            $this->sendHandoverEmail($profile, 'new_oncall', 'Numéro SOS redirigé vers vous', ['number' => $number]);
        }

        if ($previousMemberId !== null && $previousMemberId !== $newMemberId) {
            $profile = $this->memberService->findProfileByMemberAndYear($previousMemberId, $scoutYearId);
            $this->sendHandoverEmail($profile, 'ended_oncall', "Fin de votre garde SOS", []);
        }
    }

    /**
     * @param array<string, mixed> $extraContext
     */
    private function sendHandoverEmail(?MemberProfile $profile, string $template, string $subject, array $extraContext): void
    {
        if ($profile === null || $profile->email === null || $profile->email === '') {
            return;
        }

        $context = array_merge(['display_name' => $profile->getDisplayName()], $extraContext);

        try {
            $this->mailService->send(
                to: $profile->email,
                subject: $subject,
                bodyHtml: $this->twig->render("@sos_staff/email/{$template}.html.twig", $context),
                bodyText: $this->twig->render("@sos_staff/email/{$template}.text.twig", $context)
            );
        } catch (\Throwable $e) {
            // A notification failing to send is not itself a redirect
            // failure — the phone forwarding already succeeded — but it's
            // worth a journal entry so it doesn't vanish silently.
            $this->journalService->log(
                'sos_staff',
                'notification_failed',
                'info',
                "Échec d'envoi de l'email de notification de garde : {$e->getMessage()}",
                ['member_id' => $profile->memberId]
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

            $this->mailService->send(
                to: $admin->email,
                subject: 'Alerte — Redirection SOS Staff d\'U',
                bodyHtml: $this->twig->render('@sos_staff/email/admin_alert.html.twig', ['message' => $message]),
                bodyText: $this->twig->render('@sos_staff/email/admin_alert.text.twig', ['message' => $message])
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
