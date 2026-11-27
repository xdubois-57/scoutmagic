<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\SandboxLossState;
use Twig\Environment;

/**
 * Configuration › Capture suspendue (issue #1469): where a superadmin
 * decides about a test sandbox that is still armed and no longer acting.
 *
 * Both routes are `role_min: superadmin` (public/index.php). The page
 * states, per blocked channel, why it is blocked and what fixing it means
 * — fixing it is the normal way out, and lifts the block by itself on the
 * next request. Reopening real sending without fixing it is the
 * exceptional one, so it is a deliberate POST, CSRF-guarded, confirmed in
 * the browser and journaled at level `security` with the acting account.
 */
final class SandboxLossController extends AbstractController
{
    public const RELEASE_URL = SandboxLossState::PAGE_URL . '/reouverture';

    public function __construct(
        Environment $twig,
        private readonly SandboxLossState $state,
        private readonly JournalService $journalService
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /config/capture-suspendue
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $channels = [];
        foreach (SandboxLossState::CHANNELS as $channel) {
            $reason = $this->state->reason($channel);
            if ($reason === null) {
                continue;
            }

            $channels[] = [
                'id' => $channel,
                'title' => $channel === SandboxLossState::CHANNEL_MAIL
                    ? 'Capture des e-mails'
                    : 'Téléphonie simulée',
                'blocked_effect' => $channel === SandboxLossState::CHANNEL_MAIL
                    ? 'Aucun e-mail ne part : chaque envoi échoue et est noté au journal.'
                    : 'Le site ne pilote plus la ligne SOS : il ne la lit plus et ne la redirige plus.',
                'released_effect' => $channel === SandboxLossState::CHANNEL_MAIL
                    ? 'L\'envoi réel est rouvert : les e-mails partent vers leurs vrais destinataires.'
                    : 'La vraie ligne SOS est de nouveau pilotée par l\'opérateur configuré.',
                'reason' => SandboxLossState::reasonLabel($reason),
                'remedy' => SandboxLossState::remedy($reason),
                'released' => $this->state->isReleased($channel),
                'release_label' => $channel === SandboxLossState::CHANNEL_MAIL
                    ? 'Rouvrir l\'envoi réel'
                    : 'Rouvrir la vraie ligne',
                'confirm' => $channel === SandboxLossState::CHANNEL_MAIL
                    ? 'Rouvrir l\'envoi réel ? Les e-mails partiront vers les vraies adresses des membres et '
                        . 'des parents, alors que la capture est toujours armée.'
                    : 'Rouvrir la vraie ligne ? Le site pilotera de nouveau le vrai téléphone d\'urgence, alors '
                        . 'que la téléphonie simulée est toujours armée.',
            ];
        }

        return $this->render('config/sandbox_loss.html.twig', [
            'channels' => $channels,
            'release_url' => self::RELEASE_URL,
        ]);
    }

    /**
     * POST /config/capture-suspendue/reouverture — the superadmin's decision.
     *
     * @param array<string, string> $params
     */
    public function release(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, SandboxLossState::PAGE_URL)) !== null) {
            return $guard;
        }

        $channel = (string) $request->getBody('channel', '');
        if (!in_array($channel, SandboxLossState::CHANNELS, true)) {
            FlashMessage::set('error', 'Ce choix n\'existe pas sur cette page.');

            return $this->redirect(SandboxLossState::PAGE_URL);
        }

        if (!$this->state->release($channel, AuthSession::getUserAccountId(), $this->journalService)) {
            FlashMessage::set('warning', 'Il n\'y avait rien à rouvrir : la situation a changé entre-temps.');

            return $this->redirect(SandboxLossState::PAGE_URL);
        }

        FlashMessage::set(
            'success',
            $channel === SandboxLossState::CHANNEL_MAIL
                ? 'Envoi réel rouvert. Il le restera jusqu\'à ce que la capture agisse de nouveau ou soit désarmée.'
                : 'Vraie ligne rouverte. Elle le restera jusqu\'à ce que la téléphonie simulée agisse de nouveau '
                    . 'ou soit désarmée.'
        );

        return $this->redirect(SandboxLossState::PAGE_URL);
    }
}
