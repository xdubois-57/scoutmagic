<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationService;
use Core\Notification\NotificationType;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Twig\Environment;

/**
 * Notification preferences (Core\Notification, Lot 2) — one page, three
 * columns of toggles (Site/Push/Mail) per declared type, grouped, auto-
 * saved. `GET /notifications/preferences`, role_min identified.
 */
class NotificationPreferenceController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private NotificationService $notificationService,
        private NotificationPreferenceRepository $preferenceRepository,
        private UserAccountRepository $userAccountRepository,
        private RoleResolver $roleResolver,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $userId = (int) AuthSession::getUserAccountId();
        $account = $this->userAccountRepository->findById($userId);
        $viewerRole = $this->viewerRole($account?->email ?? '');

        $preferences = $this->preferenceRepository->findAllForUser($userId);

        $groups = [];
        foreach ($this->notificationService->getAllDeclaredTypes() as $type) {
            if (!$viewerRole->hasAccess(Role::fromString($type->roleMin))) {
                continue;
            }

            $preference = $preferences[$type->id] ?? null;
            $groups[$type->group][] = [
                'type' => $type,
                'channels' => [
                    'in_app' => $this->channelViewModel($type, 'in_app', $preference?->inApp),
                    'push' => $this->channelViewModel($type, 'push', $preference?->push),
                    'email' => $this->channelViewModel($type, 'email', $preference?->email),
                ],
            ];
        }

        return $this->render('notifications/preferences.html.twig', [
            'groups' => $groups,
            'vapid_public_key' => $this->twig->getGlobals()['vapid_public_key'] ?? '',
            'notification_discretion' => $account?->notificationDiscretion ?? false,
            'quiet_hours_start' => $account?->quietHoursStart,
            'quiet_hours_end' => $account?->quietHoursEnd,
        ]);
    }

    /**
     * POST /notifications/preferences — auto-save one channel toggle
     * (AJAX, JSON body). $value is "on"/"off"/"" (empty string clears the
     * override back to "follow the type default").
     *
     * @param array<string, string> $params
     */
    public function updateChannel(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $typeId = (string) ($data['type_id'] ?? '');
        $channel = (string) ($data['channel'] ?? '');
        $rawValue = $data['value'] ?? null;

        $type = $this->notificationService->findType($typeId);
        if ($type === null || !in_array($channel, ['in_app', 'push', 'email'], true)) {
            return $this->json(['success' => false, 'error' => 'Type ou canal inconnu.'], 400);
        }
        if ($type->isChannelLocked($channel)) {
            return $this->json(['success' => false, 'error' => 'Ce canal est verrouillé pour ce type.'], 400);
        }

        $userId = (int) AuthSession::getUserAccountId();
        $account = $this->userAccountRepository->findById($userId);
        $viewerRole = $this->viewerRole($account?->email ?? '');
        if (!$viewerRole->hasAccess(Role::fromString($type->roleMin))) {
            return $this->json(['success' => false, 'error' => 'Non autorisé.'], 403);
        }

        $value = match ($rawValue) {
            'on' => true,
            'off' => false,
            default => null,
        };

        $this->preferenceRepository->setChannel($userId, $typeId, $channel, $value);

        return $this->json(['success' => true]);
    }

    /**
     * POST /notifications/quiet-hours — "Mon compte"-style account
     * settings (quiet hours override + discretion mode), auto-saved from
     * this same page's bottom section.
     *
     * @param array<string, string> $params
     */
    public function updateAccountSettings(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $start = isset($data['quiet_hours_start']) && $data['quiet_hours_start'] !== '' ? (string) $data['quiet_hours_start'] : null;
        $end = isset($data['quiet_hours_end']) && $data['quiet_hours_end'] !== '' ? (string) $data['quiet_hours_end'] : null;
        if (($start === null) !== ($end === null)) {
            return $this->json(['success' => false, 'error' => 'Les deux heures doivent être définies, ou aucune.'], 400);
        }
        if ($start !== null && (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end))) {
            return $this->json(['success' => false, 'error' => 'Format d\'heure invalide.'], 400);
        }

        $discretion = (bool) ($data['discretion'] ?? false);

        $userId = (int) AuthSession::getUserAccountId();
        $this->userAccountRepository->updateNotificationSettings($userId, $start, $end, $discretion);

        return $this->json(['success' => true]);
    }

    private function viewerRole(string $email): Role
    {
        if ($email === '') {
            return Role::IDENTIFIED;
        }

        $currentYear = $this->scoutYearService->getCurrentYear();

        return Role::fromString($this->roleResolver->resolve($email, $currentYear['id']));
    }

    /**
     * @return array{locked: bool, value: bool}
     */
    private function channelViewModel(NotificationType $type, string $channel, ?bool $override): array
    {
        if ($type->isChannelLocked($channel)) {
            return ['locked' => true, 'value' => $type->channels[$channel] === 'on'];
        }

        return ['locked' => false, 'value' => $override ?? $type->defaultEnabled($channel)];
    }
}
