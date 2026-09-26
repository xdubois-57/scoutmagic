<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Config\SettingService;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;

/**
 * What a publishing page may offer for each connected destination, and
 * what a publishing POST asked for — shared by the share page of an album
 * or an article and by a free communication, so the two never disagree.
 */
final class DestinationStates
{
    public function __construct(
        private readonly PublishingService $publishing,
        private readonly PublicationRepository $publications,
        private readonly ConnectionRepository $connections,
        private readonly SettingService $settings,
        private readonly ?GroupPublishingService $groups = null
    ) {
    }

    /** Whether discussion groups are a destination here at all (the groups module is on). */
    public function offersGroups(): bool
    {
        return $this->groups !== null;
    }

    /**
     * Sends a content where the form asked: the Meta platforms, then each
     * discussion group.
     *
     * @return list<PublishOutcome>
     */
    public function publish(
        ShareSource $source,
        PublishRequest $request,
        string $caption,
        ?string $email,
        string $role,
        ?int $userId,
        \DateTimeImmutable $now
    ): array {
        $outcomes = $request->platforms === [] ? [] : $this->publishing->publish(
            $source,
            $request->platforms,
            $caption,
            $request->platformRetries,
            $userId,
            $now
        );
        if ($request->groupIds !== [] && ($this->groups === null || $userId === null)) {
            // Asked for, but not offered here: said so, never dropped.
            $outcomes[] = new PublishOutcome(
                null,
                false,
                'Les groupes de discussion ne sont pas disponibles.',
                'Groupe de discussion'
            );
        } elseif ($request->groupIds !== []) {
            $groupOutcomes = $this->groups->publish(
                $source,
                $request->groupIds,
                $caption,
                $request->groupRetries,
                $email,
                $role,
                $userId,
                $now
            );
            $outcomes = array_merge($outcomes, $groupOutcomes);
        }

        return $outcomes;
    }

    /**
     * The groups this person may post in, each with its state for this
     * content — what the « Groupe de discussion » dialog lists.
     *
     * @return list<array{
     *     value: string, id: int, name: string, members: int, state: string,
     *     date: ?\DateTimeImmutable, reason: ?string
     * }>
     */
    public function groupsFor(?ShareSource $source, ?string $email, string $role, ?int $userId): array
    {
        if ($this->groups === null) {
            return [];
        }

        $done = $source === null ? [] : $this->publications->forSource($source->kind, $source->id);
        $staleBefore = (new \DateTimeImmutable())->modify('-' . PublishingService::STALE_MINUTES . ' minutes');
        $rows = [];
        foreach ($this->groups->postableGroups($email, $role, $userId) as $group) {
            $key = GroupPublishingService::KEY_PREFIX . $group->id;
            $publication = $done[$key] ?? null;
            [$state, $date, $reason] = match (true) {
                $publication === null => ['available', null, null],
                $publication->isPublished() => ['published', $publication->publishedAt, null],
                $publication->isRetryable($staleBefore)
                    => ['failed', $publication->attemptedAt, $publication->errorMessage
                        ?? 'La publication a été interrompue.'],
                default => ['pending', $publication->attemptedAt, null],
            };
            $rows[] = [
                'value' => $key,
                'id' => $group->id,
                'name' => $group->name,
                'members' => $group->memberCount,
                'state' => $state,
                'date' => $date,
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    /**
     * Each connected destination: available, already published (with its
     * date), failed (with Meta's reason), in progress, or blocked and why.
     *
     * @return list<array{
     *     value: string, label: string, account: string, state: string,
     *     date: ?\DateTimeImmutable, reason: ?string
     * }>
     */
    public function forSource(ShareSource $source): array
    {
        $now = new \DateTimeImmutable();
        $base = rtrim((string) ($this->settings->get('base_url') ?: ''), '/');
        $done = $this->publications->forSource($source->kind, $source->id);
        $staleBefore = $now->modify('-' . PublishingService::STALE_MINUTES . ' minutes');
        $rows = [];

        foreach (SocialPlatform::cases() as $platform) {
            $connection = $this->connections->find($platform);
            if ($connection === null || !$connection->isConnected()) {
                continue;
            }

            $publication = $done[$platform->value] ?? null;
            $refusal = $this->publishing->refusal($source, $platform, $base, $now) ?? $source->blockedReason;
            [$state, $date, $reason] = match (true) {
                $publication?->isPublished() === true => ['published', $publication->publishedAt, null],
                $publication?->isRetryable($staleBefore) === true && $refusal === null
                    => ['failed', $publication->attemptedAt, $publication->errorMessage
                        ?? 'La publication a été interrompue.'],
                $publication !== null && $refusal === null => ['pending', $publication->attemptedAt, null],
                $refusal !== null => ['blocked', null, $refusal],
                default => ['available', null, null],
            };

            $rows[] = [
                'value' => $platform->value,
                'label' => $platform->label(),
                'account' => ($platform === SocialPlatform::Instagram ? '@' : '') . (string) $connection->accountName,
                'state' => $state,
                'date' => $date,
                'reason' => $reason,
            ];
        }

        return $rows;
    }


    /**
     * One flash message for a publication's outcomes, destination by
     * destination: success when everything left, error when nothing did.
     *
     * @param list<PublishOutcome> $outcomes
     * @return array{0: string, 1: string} type and message
     */
    public static function summary(array $outcomes): array
    {
        $lines = [];
        $published = 0;
        foreach ($outcomes as $outcome) {
            $published += $outcome->published ? 1 : 0;
            $lines[] = $outcome->published
                ? 'Publié sur ' . $outcome->label() . '.'
                : $outcome->label() . ' : ' . $outcome->message;
        }

        if ($outcomes === []) {
            // Never a green banner over nothing.
            return ['error', 'Rien n\'a été publié.'];
        }

        return [
            $published === count($outcomes) ? 'success' : ($published === 0 ? 'error' : 'warning'),
            implode(' ', $lines),
        ];
    }

}
