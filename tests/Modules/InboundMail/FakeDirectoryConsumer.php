<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\ReferenceDirectory;
use Modules\InboundMail\Api\ReferenceSuggestion;

/**
 * A consumer that also publishes a directory of its objects
 * (`Api\ReferenceDirectory`) — what « Rattacher à… » on the chief's
 * screen is built against.
 */
class FakeDirectoryConsumer extends FakeMessageConsumer implements ReferenceDirectory
{
    /**
     * @param array<string, string> $objects reference => label
     * @param bool $broken a directory whose search and links throw — a
     *                     module whose own tables are unreachable
     */
    public function __construct(string $id, private array $objects = [], private bool $broken = false)
    {
        parent::__construct($id);
    }

    /**
     * @return ReferenceSuggestion[]
     */
    public function searchReferences(string $query, int $limit = 10): array
    {
        if ($this->broken) {
            throw new \RuntimeException('directory unavailable');
        }

        $needle = mb_strtolower(trim($query));
        $found = [];
        foreach ($this->objects as $reference => $label) {
            if ($reference === trim($query) || str_contains(mb_strtolower($label), $needle)) {
                $found[] = new ReferenceSuggestion($reference, $label, 'un détail');
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function referenceUrl(string $businessReference): ?string
    {
        if ($this->broken) {
            throw new \RuntimeException('directory unavailable');
        }

        return isset($this->objects[$businessReference]) ? '/objets/' . $businessReference : null;
    }
}
