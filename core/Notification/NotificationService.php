<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Core\Security\DecryptionException;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Notification centre + Web Push (RFC 8030), Lot 2. dispatch() is the
 * spec-compliant, multi-recipient, type-validated entry point every
 * feature notification (calendar, gallery, mass_mail, and the newer core
 * types) goes through. notify() is the original, simpler Iteration 1
 * mechanism — kept unchanged in behavior (single account, no role/channel/
 * quiet-hours resolution) for the handful of Maintenance task handlers
 * (reset/restore/update) that ping the one admin who requested them and
 * aren't part of this lot's declared-type list; it writes under the
 * internal SYSTEM_TYPE_ID, never exposed on the preferences page.
 *
 * $roleResolver/$scoutYearService are optional: without them, dispatch()
 * skips its role_min re-check rather than reject every recipient — a
 * documented, deliberate degradation (§7.5 pattern) for callers that
 * construct this service without the full Core\Member/Security stack
 * (e.g. a narrow unit test), never the real composition root.
 *
 * $webPush is nullable for the same reason WebPush's own construction at
 * the composition root is wrapped in try/catch: an invalid VAPID config
 * must never take the rest of the site down. With it null, push delivery
 * is silently skipped (in-app/email notifications are unaffected).
 *
 * The email channel takes its Core\Notification\NotificationMailer as an
 * ARGUMENT to sendEmailsForNotifications() rather than a constructor
 * dependency: the mailer needs Twig, which `public/cron.php` has no
 * reason to build, and threading it through both composition roots is
 * exactly how `create_backup` once ended up registered in one entry point
 * and not the other (§8.17). The scheduled handler builds it, so web and
 * cron cannot drift.
 */
class NotificationService
{
    private const SYSTEM_TYPE_ID = 'core.system';
    private const DISCRETION_TITLE = 'Nouvelle notification';
    private const PUSH_BATCH_SIZE = 20;

    /**
     * @var array<
     *     string,
     *     array<
     *         int,
     *         array{
     *             id: string,
     *             label: string,
     *             description: string,
     *             group: string,
     *             role_min: string,
     *             channels: array{in_app: string, push: string, email: string},
     *             default_on_role_min?: ?string
     *         }
     *     >
     * >
     */
    private array $moduleTypes = [];

    /** @var array<string, NotificationType>|null */
    private ?array $typeCache = null;

    public function __construct(
        private NotificationRepository $notificationRepository,
        private PushSubscriptionRepository $pushSubscriptionRepository,
        private NotificationPreferenceRepository $preferenceRepository,
        private ?WebPush $webPush,
        private SettingService $settingService,
        private JournalService $journalService,
        private SchedulerService $schedulerService,
        private UserAccountRepository $userAccountRepository,
        private ?RoleResolver $roleResolver = null,
        private ?ScoutYearService $scoutYearService = null
    ) {
    }

    /**
     * Registers a module's declared notification types (Core\Module\
     * ModuleManager::loadModule(), module.json "notifications" section).
     * Invalidates the type cache so a later getAllDeclaredTypes()/
     * findType() call picks them up.
     *
     * @param array<
     *     int,
     *     array{
     *         id: string,
     *         label: string,
     *         description: string,
     *         group: string,
     *         role_min: string,
     *         channels: array{in_app: string, push: string, email: string},
     *         default_on_role_min?: ?string
     *     }
     * > $notifications
     */
    public function registerModuleTypes(string $moduleId, array $notifications): void
    {
        $this->moduleTypes[$moduleId] = $notifications;
        $this->typeCache = null;
    }

    /**
     * Every declared type, core + all active modules — the single source
     * of truth for the preferences page and for dispatch()'s own
     * validation.
     *
     * @return NotificationType[]
     */
    public function getAllDeclaredTypes(): array
    {
        $this->buildTypeCache();

        return array_values($this->typeCache);
    }

    public function findType(string $typeId): ?NotificationType
    {
        $this->buildTypeCache();

        return $this->typeCache[$typeId] ?? null;
    }

    /**
     * The spec-compliant multi-recipient send. Rejects an undeclared type
     * outright — same fail-safe spirit as a route with no role_min.
     *
     * 1. Re-checks $type->roleMin against each recipient's CURRENT role
     *    (never the role at whatever moment the caller built the list).
     * 2. Always creates a `notifications` row (the in-app centre), even
     *    for a recipient whose push/email channel is off — "the row is
     *    created and appears in their centre" holds regardless of channel.
     * 3. Never pushes OR emails $actorUserAccountId — the row is still
     *    created, only the two outbound copies are skipped: nobody needs
     *    telling about the thing they just did.
     * 4. Never sends push synchronously here — grouped by their (quiet-
     *    hours-adjusted) target time and handed to SchedulerService, one
     *    `core/send_notifications` task per distinct target time.
     * 5. Never sends email synchronously here either, for the same reason
     *    (a section mailing is hundreds of SMTP round trips): the
     *    email-eligible ids go to one `core/send_notification_emails`
     *    task, with no delay. Quiet hours deliberately do NOT apply to
     *    email — they hold back what makes a device buzz *now*, and the
     *    setting is specified as a push behaviour (specifications.md
     *    §13.3); delaying a mail by up to nine hours would make a
     *    time-sensitive one useless without making anybody's night
     *    quieter.
     *
     * @param array<int, array{userAccountId: int, memberId: ?int}> $recipients
     * @param array{title: string, body: string, url?: ?string} $payload
     */
    public function dispatch(string $typeId, array $recipients, array $payload, ?int $actorUserAccountId = null): void
    {
        $type = $this->findType($typeId);
        if ($type === null) {
            throw new \InvalidArgumentException(
                "Undeclared notification type '{$typeId}' — every type must be declared in "
                    . "Core\\Notification\\NotificationRegistry or a module's module.json \"notifications\" section "
                    . "before it can be dispatched."
            );
        }

        $title = (string) $payload['title'];
        $body = (string) $payload['body'];
        $url = isset($payload['url']) ? (string) $payload['url'] : null;

        $currentScoutYearId = $this->scoutYearService?->getCurrentYear()['id'] ?? null;

        /** @var array<string, array{runAt: \DateTimeImmutable, ids: int[]}> $pushBuckets */
        $pushBuckets = [];
        /** @var int[] $emailIds */
        $emailIds = [];

        foreach ($recipients as $recipient) {
            $userAccountId = (int) $recipient['userAccountId'];
            $memberId = isset($recipient['memberId']) && $recipient['memberId'] !== null
                ? (int) $recipient['memberId']
                : null;

            // One role resolution per recipient, used twice: the role_min
            // re-check below and, further down, whether a type declaring
            // default_on_role_min counts this recipient as one of the
            // audiences its "default_on" channels are on for.
            $role = $this->resolveRole($userAccountId, $currentScoutYearId);
            if (!$this->isRoleAllowed($role, $type->roleMin)) {
                continue;
            }

            // Unknown role — the documented degradation for a service
            // built without RoleResolver/ScoutYearService. It already
            // means "skip the role_min check" rather than "reject
            // everybody", so per-role defaults follow the same reading and
            // apply as declared.
            $defaultsOn = $role === null || $type->defaultsOnForRole($role);

            $notificationId = $this->notificationRepository->create($userAccountId, $memberId, $typeId, $title, $body,
                $url);

            $this->journalService->log(
                'core',
                'notification_sent',
                'info',
                'Notification envoyée',
                ['type_id' => $typeId],
                $actorUserAccountId
            );

            // The actor never gets a push or an email for their own
            // action — the row above still exists, only the outbound
            // copies are skipped.
            if ($actorUserAccountId !== null && $userAccountId === $actorUserAccountId) {
                continue;
            }

            // One lookup, both channels — see resolveChannel().
            $preference = $this->preferenceRepository->find($userAccountId, $type->id);

            if ($this->resolveChannel($type, 'push', $preference, $defaultsOn)) {
                $runAt = $this->resolvePushRunAt($userAccountId);
                $bucketKey = $runAt->format('YmdHi');
                $pushBuckets[$bucketKey]['runAt'] ??= $runAt;
                $pushBuckets[$bucketKey]['ids'][] = $notificationId;
            }

            if ($this->resolveChannel($type, 'email', $preference, $defaultsOn)) {
                $emailIds[] = $notificationId;
            }
        }

        foreach ($pushBuckets as $bucket) {
            $delaySeconds = max(0, $bucket['runAt']->getTimestamp() - time());
            $this->schedulerService->scheduleAfter('core', 'send_notifications', $delaySeconds,
                ['notification_ids' => $bucket['ids']]);
        }

        if ($emailIds !== []) {
            $this->schedulerService->scheduleAfter('core', 'send_notification_emails', 0,
                ['notification_ids' => $emailIds]);
        }
    }

    /**
     * Iteration-1 direct send: one account, no type/role/channel/quiet-
     * hours resolution, immediate. See class docblock for why this stays
     * separate from dispatch().
     */
    public function notify(int $userAccountId, string $title, string $body, ?string $url = null): void
    {
        $notificationId = $this->notificationRepository->create($userAccountId, null, self::SYSTEM_TYPE_ID, $title,
            $body, $url);

        $record = new NotificationRecord(
            id: $notificationId,
            userAccountId: $userAccountId,
            memberId: null,
            typeId: self::SYSTEM_TYPE_ID,
            title: $title,
            body: $body,
            url: $url,
            readAt: null,
            createdAt: (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        );

        $endpointMap = [];
        $this->queuePushForAccount($record, $endpointMap);
        if ($endpointMap !== []) {
            $this->flushQueuedPush($endpointMap);
        }
    }

    /**
     * Called by Core\Notification\Task\SendNotificationsHandler for a
     * batch of notification ids whose push channel was resolved eligible
     * at dispatch() time. $shouldContinue is the handler's time-budget
     * check — queueing is cheap (no network), so it's polled once per
     * notification id; the actual curl_multi network round trip happens
     * once at the end, batched (Minishlink\WebPush\WebPush::flush(),
     * batches of PUSH_BATCH_SIZE — this IS the "curl_multi in batches of
     * ~20" the module spec asks for, via the already-approved library
     * rather than hand-rolled).
     *
     * @param int[] $notificationIds
     * @return int[] ids actually attempted, in order — the handler
     *         reschedules array_diff($notificationIds, $attempted) for
     *         its next run when $shouldContinue cut the batch short.
     */
    public function sendPushForNotifications(array $notificationIds, \Closure $shouldContinue): array
    {
        $attempted = [];
        $endpointMap = [];

        foreach ($notificationIds as $notificationId) {
            if (!$shouldContinue()) {
                break;
            }
            $attempted[] = $notificationId;

            $record = $this->notificationRepository->findById($notificationId);
            if ($record !== null) {
                $this->queuePushForAccount($record, $endpointMap);
            }
        }

        if ($endpointMap !== []) {
            $this->flushQueuedPush($endpointMap);
        }

        return $attempted;
    }

    /**
     * Called by Core\Notification\Task\SendNotificationEmailsHandler for
     * a batch of notification ids whose email channel was resolved
     * eligible at dispatch() time. Same contract as
     * sendPushForNotifications(): $shouldContinue is the handler's time
     * budget, and the return value is the ids actually taken on, so the
     * handler can reschedule the remainder.
     *
     * Unlike push, every send here is a network round trip of its own —
     * there is no batching transport to flush — so the budget is polled
     * per message rather than per queue.
     *
     * Each id is CLAIMED before it is rendered
     * (NotificationRepository::claimForEmail()): a row already stamped is
     * silently skipped, which is what makes a re-run, or two overlapping
     * scheduler runs, incapable of mailing anybody twice. A transport
     * failure is journaled and NOT retried, deliberately — a retry cannot
     * tell "never left" from "left, then the connection dropped", and the
     * notification is in the recipient's centre either way.
     *
     * @param int[] $notificationIds
     * @param NotificationMailer $mailer built by the caller — see the
     *        class docblock for why it is not a constructor dependency
     * @return int[] ids taken on, in order
     */
    public function sendEmailsForNotifications(
        array $notificationIds,
        \Closure $shouldContinue,
        NotificationMailer $mailer
    ): array {
        $attempted = [];

        foreach ($notificationIds as $notificationId) {
            if (!$shouldContinue()) {
                break;
            }
            $attempted[] = $notificationId;

            if (!$this->notificationRepository->claimForEmail($notificationId)) {
                continue;
            }

            $record = $this->notificationRepository->findById($notificationId);
            if ($record === null) {
                continue;
            }

            $account = $this->findAccountSafely($record->userAccountId);
            $to = trim((string) $account?->email);
            if ($account === null || $to === '') {
                $this->notificationRepository->releaseEmailClaim($notificationId);
                continue;
            }

            if (!$mailer->send($record, $to, $account->notificationDiscretion)) {
                // The address itself never reaches the journal
                // (SECURITY.md §11) — the type and the row id are enough
                // to find the send again.
                $this->journalService->log(
                    'core',
                    'notification_email_failed',
                    'info',
                    "Échec de l'envoi d'une notification par email",
                    ['notification_id' => $notificationId, 'type_id' => $record->typeId],
                    null
                );
            }
        }

        return $attempted;
    }

    /**
     * Registers a device for push notifications. Idempotent: re-
     * subscribing the same endpoint (e.g. the browser rotated its keys)
     * replaces the previous row rather than duplicating it.
     */
    public function subscribe(
        int $userAccountId,
        string $endpoint,
        string $authKey,
        string $p256dhKey,
        ?string $deviceLabel = null
    ): void
    {
        $this->pushSubscriptionRepository->deleteByEndpoint($userAccountId, $endpoint);
        $this->pushSubscriptionRepository->create($userAccountId, $endpoint, $authKey, $p256dhKey, $deviceLabel);
    }

    public function unsubscribe(int $userAccountId, string $endpoint): void
    {
        $this->pushSubscriptionRepository->deleteByEndpoint($userAccountId, $endpoint);
    }

    /**
     * Removes every device registered for this account (toggle off in
     * "Mon compte" — the account-wide preference, as opposed to
     * unsubscribe() which drops a single device).
     */
    public function unsubscribeAll(int $userAccountId): void
    {
        $this->pushSubscriptionRepository->deleteAllForUser($userAccountId);
    }

    /**
     * Resolves (in_app/push/email) → bool for one account/type — member
     * preference override else the type's own declared default. A locked
     * channel ("on"/"off") ignores any preference row entirely.
     */
    public function channelEnabled(
        int $userAccountId,
        NotificationType $type,
        string $channel,
        ?Role $role = null
    ): bool
    {
        if ($type->isChannelLocked($channel)) {
            return $type->channels[$channel] === 'on';
        }

        $role ??= $this->resolveRole($userAccountId, $this->scoutYearService?->getCurrentYear()['id'] ?? null);

        return $this->resolveChannel(
            $type,
            $channel,
            $this->preferenceRepository->find($userAccountId, $type->id),
            $role === null || $type->defaultsOnForRole($role)
        );
    }

    /**
     * channelEnabled() without the lookup — for a caller that already has
     * the recipient's preference row in hand.
     *
     * dispatch() asks about two channels per recipient, and the public
     * method fetches the same row for each: on a section-wide send that is
     * one wasted query per member, every send. This is the one place that
     * matters enough to hand the row in instead.
     */
    private function resolveChannel(
        NotificationType $type,
        string $channel,
        ?NotificationPreference $preference,
        bool $defaultsOn = true
    ): bool {
        if ($type->isChannelLocked($channel)) {
            return $type->channels[$channel] === 'on';
        }

        $override = match ($channel) {
            'in_app' => $preference?->inApp,
            'push' => $preference?->push,
            'email' => $preference?->email,
            default => null,
        };

        return $override ?? $type->defaultEnabled($channel, $defaultsOn);
    }

    /**
     * Effective quiet-hours window for an account — its own override if
     * both start/end are set, else the global SettingService default.
     *
     * @return array{0: string, 1: string} [start, end] as "HH:MM"
     */
    public function resolveQuietHours(int $userAccountId): array
    {
        $account = $this->findAccountSafely($userAccountId);
        if ($account !== null && $account->quietHoursStart !== null && $account->quietHoursEnd !== null) {
            return [substr($account->quietHoursStart, 0, 5), substr($account->quietHoursEnd, 0, 5)];
        }

        $start = (string) ($this->settingService->get('notifications_quiet_hours_start') ?: '22:00');
        $end = (string) ($this->settingService->get('notifications_quiet_hours_end') ?: '07:00');

        return [$start, $end];
    }

    /**
     * "Now" if outside the account's quiet-hours window, else the exact
     * instant the window ends (today's if still ahead, tomorrow's if the
     * window wraps past midnight and already has). Computed server-side,
     * per the module spec — never trusts a client-supplied time.
     */
    public function resolvePushRunAt(int $userAccountId): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        [$start, $end] = $this->resolveQuietHours($userAccountId);

        if (!$this->isWithinQuietHours($now, $start, $end)) {
            return $now;
        }

        return $this->quietHoursEndInstant($now, $end);
    }

    private function isWithinQuietHours(\DateTimeImmutable $now, string $start, string $end): bool
    {
        $nowMinutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');
        $startMinutes = $this->minutesFromHm($start);
        $endMinutes = $this->minutesFromHm($end);

        if ($startMinutes === $endMinutes) {
            return false; // zero-width window — treated as disabled
        }

        if ($startMinutes < $endMinutes) {
            // Same-day window, e.g. 09:00–17:00.
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        // Overnight window, e.g. 22:00–07:00.
        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }

    private function quietHoursEndInstant(\DateTimeImmutable $now, string $end): \DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $end));
        $todayEnd = $now->setTime($hour, $minute, 0);

        // now is inside the window and past today's clock-time instant of
        // "end" only when the window wraps past midnight (e.g. now=23:00,
        // end=07:00) — the real target is tomorrow's occurrence.
        return $todayEnd <= $now ? $todayEnd->modify('+1 day') : $todayEnd;
    }

    private function minutesFromHm(string $hm): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $hm));

        return $hour * 60 + $minute;
    }

    /**
     * `UserAccountRepository::findById()` decrypts the account's email on
     * every call — a single corrupted or key-mismatched row (e.g. an
     * account whose data predates a master key rotation) must never take
     * down an entire dispatch() to potentially hundreds of other, healthy
     * recipients. Treated the same as "account not found": the affected
     * recipient is silently skipped for this send, and the failure is
     * journaled once so it stays discoverable rather than swallowed.
     */
    private function findAccountSafely(int $userAccountId): ?UserAccount
    {
        try {
            return $this->userAccountRepository->findById($userAccountId);
        } catch (DecryptionException $e) {
            $this->journalService->log(
                'core',
                'notification_account_decrypt_failed',
                'security',
                'Compte illisible ignoré lors d\'un envoi de notification',
                ['user_account_id' => $userAccountId, 'error' => $e->getMessage()],
                null
            );

            return null;
        }
    }

    /**
     * The account's current role, or null when this service was built
     * without RoleResolver/ScoutYearService (the documented narrow-test
     * degradation — see the class docblock) or when the account itself is
     * gone. Callers read null as "no role information", never as "no
     * role": isRoleAllowed() below is what turns it into a decision.
     */
    private function resolveRole(int $userAccountId, ?int $currentScoutYearId): ?Role
    {
        if ($this->roleResolver === null || $currentScoutYearId === null) {
            return null;
        }

        $account = $this->findAccountSafely($userAccountId);
        if ($account === null) {
            return null;
        }

        return Role::fromString($this->roleResolver->resolve($account->email, $currentScoutYearId));
    }

    /**
     * $role null means either "this service can't resolve roles at all"
     * (degrade to allowed, as before) or "the account no longer exists"
     * — the second was, and stays, a rejection, so the two are told apart
     * by the caller having asked for a role at all rather than by the
     * null itself. Kept as one method so both dispatch() and
     * recipientsForType() apply exactly the same rule.
     */
    private function isRoleAllowed(?Role $role, string $roleMin): bool
    {
        if ($role === null) {
            return $this->roleResolver === null || $this->scoutYearService === null;
        }

        return $role->hasAccess(Role::fromString($roleMin));
    }

    /**
     * Everyone a type-wide announcement should reach: every account the
     * type's role_min allows, whose in-app channel resolves enabled for
     * their own role and preferences. For a broadcast with no natural
     * recipient list — nobody asked for an automatic update, so nobody is
     * "the requester" — this IS the list.
     *
     * Why in_app decides: dispatch() always writes the notifications row,
     * deliberately (a recipient with push off still gets it in their
     * centre), so a broadcast that handed it every account would put a row
     * in the centre of a member who had switched the type off. Resolving
     * the in-app channel here, before dispatch(), is what makes that
     * switch mean something for a type nobody individually opted into.
     *
     * @return array<int, array{userAccountId: int, memberId: null}>
     */
    public function recipientsForType(string $typeId): array
    {
        $type = $this->findType($typeId);
        if ($type === null) {
            return [];
        }

        $currentScoutYearId = $this->scoutYearService?->getCurrentYear()['id'] ?? null;

        $recipients = [];
        foreach ($this->userAccountRepository->findAllIds() as $userAccountId) {
            $role = $this->resolveRole($userAccountId, $currentScoutYearId);
            if (!$this->isRoleAllowed($role, $type->roleMin)) {
                continue;
            }

            if (!$this->channelEnabled($userAccountId, $type, 'in_app', $role)) {
                continue;
            }

            $recipients[] = ['userAccountId' => $userAccountId, 'memberId' => null];
        }

        return $recipients;
    }

    /**
     * @param array<
     *     string,
     *     int
     * > $endpointMap mutated: plaintext endpoint => push_subscriptions.id, for flushQueuedPush() to correlate reports
     * back afterward
     */
    private function queuePushForAccount(NotificationRecord $record, array &$endpointMap): void
    {
        if ($this->webPush === null) {
            return;
        }

        $subscriptions = $this->pushSubscriptionRepository->findByUserAccountId($record->userAccountId);
        if ($subscriptions === []) {
            return;
        }

        $account = $this->findAccountSafely($record->userAccountId);
        $discretion = $account?->notificationDiscretion ?? false;

        try {
            $payload = json_encode([
                'title' => $discretion ? self::DISCRETION_TITLE : $record->title,
                'body' => $discretion ? '' : $record->body,
                'url' => $record->url,
                'unread_count' => $this->notificationRepository->countUnread($record->userAccountId),
                'notification_id' => $record->id,
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            try {
                $this->webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'keys' => [
                            'p256dh' => $subscription->p256dhKey,
                            'auth' => $subscription->authKey,
                        ],
                    ]),
                    $payload
                );
                $endpointMap[$subscription->endpoint] = $subscription->id;
            } catch (\Throwable) {
                // Malformed subscription — skip, never block the rest of
                // the batch (matches Iteration 1's per-device isolation).
            }
        }
    }

    /**
     * @param array<string, int> $endpointMap plaintext endpoint => push_subscriptions.id
     */
    private function flushQueuedPush(array $endpointMap): void
    {
        if ($this->webPush === null) {
            return;
        }

        foreach ($this->webPush->flush(self::PUSH_BATCH_SIZE) as $report) {
            $subscriptionId = $endpointMap[$report->getEndpoint()] ?? null;
            if ($subscriptionId === null) {
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $this->pushSubscriptionRepository->delete($subscriptionId);
            } elseif ($report->isSuccess()) {
                $this->pushSubscriptionRepository->recordSuccess($subscriptionId);
            } else {
                // Covers both a transient failure and a 429 — the module
                // spec's "reschedule" for 429 is satisfied naturally here:
                // the next dispatch to this account retries the same
                // device, and repeated 429s eventually cross
                // FAILURE_THRESHOLD like any other repeated failure,
                // without needing a distinct requeue path.
                $this->pushSubscriptionRepository->recordFailure($subscriptionId);
            }
        }
    }

    private function buildTypeCache(): void
    {
        if ($this->typeCache !== null) {
            return;
        }

        $this->typeCache = [];
        foreach (NotificationRegistry::getCoreTypes() as $type) {
            $this->typeCache[$type->id] = $type;
        }

        foreach ($this->moduleTypes as $notifications) {
            foreach ($notifications as $declaration) {
                $this->typeCache[$declaration['id']] = new NotificationType(
                    id: $declaration['id'],
                    label: $declaration['label'],
                    description: $declaration['description'],
                    group: $declaration['group'],
                    roleMin: $declaration['role_min'],
                    channels: $declaration['channels'],
                    defaultOnRoleMin: $declaration['default_on_role_min'] ?? null
                );
            }
        }
    }
}
