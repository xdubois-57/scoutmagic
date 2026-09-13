<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

/**
 * {@see InMemorySettingService} with exactly one row that refuses to be
 * written.
 *
 * **One key, not a blanket refusal.** A double that failed every write
 * would fail the connection's own setup long before a test reached the
 * line it cares about — and the failures worth testing are not "the
 * database is gone", they are one row breaking at one moment: the
 * success stamp that turns a delivered archive into a failed send, or
 * the generation number that cannot follow the phrase it describes.
 *
 * In its own file, named after the class, for the reason its parent's
 * docblock gives: a class at the foot of a test file is found only when
 * PHPUnit happens to have loaded that file first.
 */
final class RefusingSettingService extends InMemorySettingService
{
    public string $refuseKey = '';

    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        if ($this->refuseKey !== '' && $key === $this->refuseKey) {
            throw new \RuntimeException('la table des réglages est indisponible');
        }

        parent::setInternal($key, $value, $moduleId);
    }
}
