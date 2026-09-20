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
            // measurement — but « not yet » is not available either, since
            // that is the state the sweep turns into « jamais arrivé ».
            // The copy arrived; we simply cannot name where.
            'a folder nobody can place' => ['Archives 2026', SeedVerdict::Elsewhere],
            'a provider shelf this list has never met' => ['Quarantaine', SeedVerdict::Elsewhere],
            'Yahoo\'s own bulk shelf' => ['Bulk', SeedVerdict::Elsewhere],
            // Only « the relay did not say » is still pending.
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

    /**
     * **A copy that arrived is never `Pending`**, whatever the folder is
     * called.
     *
     * This is the property, not the table above: `Pending` is what the
     * sweep turns into `Missing` after two days, so a named folder
     * answering `Pending` means the site declares an arrival « jamais
     * arrivé » — its gravest badge — beside the very folder name the copy
     * was found in, and feeds that fabricated `missing` to the routing's
     * counters. Any new folder name added to the list below must keep
     * this true.
     */
    public function testAFolderThatWasActuallyNamedIsNeverPending(): void
    {
        foreach (
            [
                'Archives 2026',
                'Quarantaine',
                'Bulk',
                'INBOX',
                'Junk',
                'Un dossier créé par le chef',
                'INBOX/Suivi/2026',
            ] as $folder
        ) {
            $this->assertNotSame(
                SeedVerdict::Pending,
                SeedVerdict::fromFolder($folder),
                $folder . ' was named by the provider, so the copy arrived — and « en attente » is what '
                . 'the sweep turns into « jamais arrivé ».'
            );
        }
    }

    /** « Arrivé, dossier inconnu » is not an alarm, and its colour says so. */
    public function testAnUnplaceableArrivalIsNeutralRatherThanAWarning(): void
    {
        $this->assertSame('neutral', SeedVerdict::Elsewhere->badge());
        $this->assertStringContainsString('Arrivé', SeedVerdict::Elsewhere->label());
    }
}
