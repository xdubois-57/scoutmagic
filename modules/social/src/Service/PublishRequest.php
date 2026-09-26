<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\Social\Api\SocialPlatform;

/**
 * What a publishing form asked for: Meta platforms and discussion groups,
 * each with the retries confirmed for it.
 */
final class PublishRequest
{
    /**
     * @param list<SocialPlatform> $platforms
     * @param list<SocialPlatform> $platformRetries
     * @param list<int> $groupIds
     * @param list<int> $groupRetries
     */
    public function __construct(
        public readonly array $platforms,
        public readonly array $platformRetries,
        public readonly array $groupIds = [],
        public readonly array $groupRetries = [],
    ) {
    }

    /**
     * Reads a publishing form: `destinations[]` (a platform, or `groups`
     * for « Groupe de discussion »), `groups[]` (the groups chosen in the
     * dialog) and `retry[]` (a platform or `group:{id}`). A confirmed
     * retry is a destination in its own right: the box stands under its
     * destination and can be ticked alone. The groups chosen only count
     * while « Groupe de discussion » is ticked.
     */
    public static function fromForm(mixed $destinations, mixed $groups, mixed $retries): self
    {
        $destinations = self::strings($destinations);
        $retries = self::strings($retries);

        $platformRetries = self::platforms($retries);
        $groupRetries = self::groupIds($retries);
        $groupIds = in_array('groups', $destinations, true)
            ? array_values(array_unique(array_merge(self::ids($groups), $groupRetries)))
            : $groupRetries;

        return new self(
            self::platforms(array_merge($destinations, array_map(
                static fn (SocialPlatform $p): string => $p->value,
                $platformRetries
            ))),
            $platformRetries,
            $groupIds,
            $groupRetries
        );
    }

    public function isEmpty(): bool
    {
        return $this->platforms === [] && $this->groupIds === [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], 'is_string'));
    }

    /**
     * @param list<string> $values
     * @return list<SocialPlatform>
     */
    private static function platforms(array $values): array
    {
        $platforms = [];
        foreach ($values as $value) {
            $platform = SocialPlatform::tryFrom($value);
            if ($platform !== null && !in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private static function groupIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = GroupPublishingService::groupIdOf($value);
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if ((is_string($value) && ctype_digit($value)) || is_int($value)) {
                $id = (int) $value;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
