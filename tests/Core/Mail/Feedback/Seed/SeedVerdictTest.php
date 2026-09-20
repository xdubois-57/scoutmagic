<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Mail\Feedback\Seed\SeedVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a provider's folder name as a verdict (roadmap IT-07).
 */
class SeedVerdictTest extends TestCase
{
    /**
     * @return array<string, array{?string, SeedVerdict}>
     */
    public static function folders(): array
    {
        return [
            'Google' => ['Junk', SeedVerdict::Spam],
            'most providers' => ['Spam', SeedVerdict::Spam],
            'a French provider' => ['Indésirables', SeedVerdict::Spam],
            'the same one unaccented' => ['Indesirables', SeedVerdict::Spam],
            'an older server' => ['Bulk Mail', SeedVerdict::Spam],
            'nested under the inbox' => ['INBOX/Junk', SeedVerdict::Spam],
            'the inbox itself' => ['INBOX', SeedVerdict::Inbox],
            'the inbox, lower case' => ['inbox', SeedVerdict::Inbox],
            // Neither shelf: a folder the operator made, or one this list
            // has never met. Guessing either way would be inventing a
            // measurement, so it stays « not yet ».
            'a folder nobody can place' => ['Archives 2026', SeedVerdict::Pending],
            'nothing at all' => [null, SeedVerdict::Pending],
            'an empty string' => ['   ', SeedVerdict::Pending],
        ];
    }

    #[DataProvider('folders')]
    public function testAFolderNameReadsAsItsVerdict(?string $folder, SeedVerdict $expected): void
    {
        $this->assertSame($expected, SeedVerdict::fromFolder($folder));
    }

    /**
     * **`Missing` is graver than `Spam`, and the colours say so.** A
     * message filed as spam was at least accepted and can be found by
     * somebody who looks; one that never arrived was refused in silence,
     * which is what this whole chantier exists to end.
     */
    public function testTheBadgesRankSilenceAboveTheSpamFolder(): void
    {
        $this->assertSame('warning', SeedVerdict::Spam->badge());
        $this->assertSame('danger', SeedVerdict::Missing->badge());
        $this->assertSame('success', SeedVerdict::Inbox->badge());
        $this->assertSame('neutral', SeedVerdict::Pending->badge());
    }

    /** Every case says something in ordinary French. */
    public function testEveryVerdictHasALabel(): void
    {
        foreach (SeedVerdict::cases() as $verdict) {
            $this->assertNotSame('', $verdict->label());
        }
    }
}
