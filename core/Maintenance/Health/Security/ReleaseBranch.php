<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * One maintained-or-not line of releases, as a reference source publishes
 * it (« 8.4 », latest 8.4.28, security support until 2028-12-31).
 *
 * `supported` is the end of SECURITY support, the one that matters here:
 * a branch past its active support but still receiving security fixes is
 * maintained for this check's purpose. Null when the source does not say.
 */
final class ReleaseBranch
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $latest,
        public readonly ?bool $supported,
        public readonly ?string $supportEnds = null,
        public readonly ?string $link = null,
    ) {
    }

    /** @return array{name: string, latest: ?string, supported: ?bool, support_ends: ?string, link: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'latest' => $this->latest,
            'supported' => $this->supported,
            'support_ends' => $this->supportEnds,
            'link' => $this->link,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['name'] ?? null) || $data['name'] === '') {
            return null;
        }
        $string = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self(
            $data['name'],
            $string($data['latest'] ?? null),
            is_bool($data['supported'] ?? null) ? $data['supported'] : null,
            $string($data['support_ends'] ?? null),
            $string($data['link'] ?? null)
        );
    }
}
