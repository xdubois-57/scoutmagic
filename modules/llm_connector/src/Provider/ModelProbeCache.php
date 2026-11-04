<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

use Core\Config\AppClock;

/** Evidence belongs to an endpoint/key pair and expires conservatively. */
final class ModelProbeCache
{
    private const VERIFIED_TTL = 7 * 86400;
    private const RETRY_TTL = 3600;
    /** @var array<string, mixed> */
    private array $results;
    private int $now;

    /** @param array<string, mixed> $stored */
    public function __construct(array $stored, private string $scope, ?int $now = null)
    {
        $this->now = $now ?? AppClock::now()->getTimestamp();
        $this->results = self::contexts($stored)[$scope] ?? [];
    }

    public function resultFor(string $modelId): ?ModelProbeStatus
    {
        $result = $this->results[$modelId] ?? null;
        if (!is_array($result) || !is_string($result['status'] ?? null) || !is_int($result['checked_at'] ?? null)) {
            return null;
        }
        $status = ModelProbeStatus::tryFrom($result['status']);
        $age = $this->now - $result['checked_at'];
        $ttl = $status === ModelProbeStatus::UNVERIFIED ? self::RETRY_TTL : self::VERIFIED_TTL;
        return $status !== null && $age >= 0 && ($status === ModelProbeStatus::CHAT_UNSUPPORTED || $age < $ttl)
            ? $status : null;
    }

    public function needsProbe(string $modelId): bool
    {
        $status = $this->resultFor($modelId);
        return $status === null || ($status === ModelProbeStatus::CHAT_UNSUPPORTED
            && $this->now - $this->results[$modelId]['checked_at'] >= self::VERIFIED_TTL);
    }

    public function record(string $modelId, ModelProbeStatus $status): void
    {
        // Timeouts and wrong answers cannot erase an established endpoint refusal.
        if ($status === ModelProbeStatus::UNVERIFIED
            && $this->resultFor($modelId) === ModelProbeStatus::CHAT_UNSUPPORTED) {
            $status = ModelProbeStatus::CHAT_UNSUPPORTED;
        }
        $this->results[$modelId] = ['status' => $status->value, 'checked_at' => $this->now];
    }

    /** @return array{version: int, scope: string, results: array<string, mixed>} */
    public function toArray(): array
    {
        return ['version' => 1, 'scope' => $this->scope, 'results' => $this->results];
    }

    /**
     * Merge under the provider row lock. Stale refreshes cannot erase a newer
     * refusal, nor evidence belonging to another endpoint/key configuration.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function mergeForStorage(array $stored, array $incoming): array
    {
        $contexts = self::contexts($stored);
        foreach (self::contexts($incoming) as $scope => $results) {
            foreach ($results as $id => $result) {
                $contexts[$scope][$id] = self::newestEvidence($contexts[$scope][$id] ?? null, $result);
            }
        }
        $scope = (string) $incoming['scope'];
        $results = $contexts[$scope] ?? [];
        unset($contexts[$scope]);
        return ['version' => 1, 'scope' => $scope, 'results' => $results, 'other_scopes' => $contexts];
    }

    /** @param array<string, mixed> $stored
     *  @return array<string, array<string, mixed>>
     */
    private static function contexts(array $stored): array
    {
        if (($stored['version'] ?? null) !== 1 || !is_string($stored['scope'] ?? null)) {
            return [];
        }
        $contexts = [];
        foreach (is_array($stored['other_scopes'] ?? null) ? $stored['other_scopes'] : [] as $scope => $results) {
            if (is_string($scope) && is_array($results)) {
                $contexts[$scope] = $results;
            }
        }
        $contexts[$stored['scope']] = is_array($stored['results'] ?? null) ? $stored['results'] : [];
        return $contexts;
    }

    private static function newestEvidence(mixed $current, mixed $incoming): mixed
    {
        if (!is_array($current) || !is_int($current['checked_at'] ?? null)) {
            return $incoming;
        }
        if (!is_array($incoming) || !is_int($incoming['checked_at'] ?? null)
            || $incoming['checked_at'] < $current['checked_at']) {
            return $current;
        }
        if (($current['status'] ?? null) === ModelProbeStatus::CHAT_UNSUPPORTED->value
            && (($incoming['status'] ?? null) !== ModelProbeStatus::VISION_VERIFIED->value
                || $incoming['checked_at'] === $current['checked_at'])) {
            return ['status' => ModelProbeStatus::CHAT_UNSUPPORTED->value, 'checked_at' => $incoming['checked_at']];
        }
        return $incoming;
    }
}
