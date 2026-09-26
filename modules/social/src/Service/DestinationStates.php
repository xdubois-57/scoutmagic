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
        private readonly SettingService $settings
    ) {
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
     * The destinations a publishing form asked for, and the retries it
     * confirmed. A confirmed retry is a destination in its own right: the
     * retry box stands under its destination and can be ticked alone.
     *
     * @return array{0: list<SocialPlatform>, 1: list<SocialPlatform>}
     */
    public static function requested(mixed $destinations, mixed $retries): array
    {
        $retried = self::platforms($retries);

        return [
            self::platforms(array_merge(
                is_array($destinations) ? $destinations : [],
                array_map(static fn (SocialPlatform $p): string => $p->value, $retried)
            )),
            $retried,
        ];
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
                ? 'Publié sur ' . $outcome->platform->label() . '.'
                : $outcome->platform->label() . ' : ' . $outcome->message;
        }

        return [
            $published === count($outcomes) ? 'success' : ($published === 0 ? 'error' : 'warning'),
            implode(' ', $lines),
        ];
    }

    /**
     * @return list<SocialPlatform>
     */
    private static function platforms(mixed $values): array
    {
        $platforms = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $platform = is_string($value) ? SocialPlatform::tryFrom($value) : null;
            if ($platform !== null && !in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }
}
