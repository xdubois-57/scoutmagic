<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\ReanalysisReport;
use Modules\InboundMail\Api\TriageFilter;
use Modules\InboundMail\Api\TriageScreen;
use PHPUnit\Framework\TestCase;

/**
 * Mail sent to a list — a newsletter, a notification — is folded away on
 * every triage screen behind « Afficher le courrier automatique (N) ».
 *
 * The screen reads that from `InboundMessage::$isBulk`, which for a long
 * time did not exist: the read raised a warning, answered null, and every
 * newsletter was counted and listed as somebody's message.
 */
final class TriageScreenTest extends TestCase
{
    public function testMailSentToAListIsFoldedAwayAndCounted(): void
    {
        $screen = TriageScreen::of($this->rows(), TriageFilter::ALL, false, [], 0);

        $this->assertSame([7], $this->ids($screen->messages));
        $this->assertSame(1, $screen->bulkCount);
    }

    public function testAskingForItShowsItToo(): void
    {
        $screen = TriageScreen::of($this->rows(), TriageFilter::ALL, true, [], 0);

        $this->assertSame([7, 8], $this->ids($screen->messages));
        $this->assertSame(1, $screen->bulkCount);
        $this->assertTrue($screen->includeBulk);
    }

    /**
     * The tabs count what they show: « à trier » and « rattachés » split the
     * list on the row's links, and the set-aside count is whatever the
     * consumer read — shown even when its own tab is not the one open.
     */
    public function testEachTabCountsItsOwnRows(): void
    {
        $rows = $this->rows();
        $rows[0]['links'] = ['un lien'];

        $screen = TriageScreen::of($rows, TriageFilter::LINKED, true, [], 3);

        $this->assertSame([7], $this->ids($screen->messages));
        $this->assertSame(['non_rattaches' => 1, 'rattaches' => 1, 'tous' => 2, 'ecartes' => 3], $screen->counts);
        $this->assertSame('rattaches', $screen->toArray()['status']);
    }

    public function testTheSetAsideTabShowsTheSetAsideRows(): void
    {
        $setAside = [$this->rows()[0]];

        $screen = TriageScreen::of($this->rows(), TriageFilter::DISMISSED, false, $setAside, 1);

        $this->assertSame([7], $this->ids($screen->messages));
    }

    public function testAnUnknownFilterIsTheWorkList(): void
    {
        $this->assertSame(TriageFilter::UNLINKED, TriageFilter::fromQuery('nimporte'));
        $this->assertSame(TriageFilter::DISMISSED, TriageFilter::fromQuery('ecartes'));
    }

    public function testTheReanalysisIsSaidPlainly(): void
    {
        $this->assertSame(
            'Aucun message en attente : tout ce qui est conservé est déjà rattaché.',
            (new ReanalysisReport(0, 0, 0))->message()
        );
        $this->assertSame(
            '3 messages réexaminés : 1 rattachement et 2 propositions. '
                . 'La lecture des pièces jointes se poursuit en arrière-plan.',
            ReanalysisReport::fromArray(['examined' => 3, 'linked' => 1, 'proposed' => 2])->message()
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['message' => $this->message(7, false), 'links' => [], 'candidates' => []],
            ['message' => $this->message(8, true), 'links' => [], 'candidates' => []],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn(array $row): int => $row['message']->id, $rows);
    }

    private function message(int $id, bool $isBulk): InboundMessage
    {
        return new InboundMessage(
            id: $id,
            mailboxId: 1,
            consumerId: '',
            businessReference: '',
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Message ' . $id,
            fromEmail: 'quelquun@example.be',
            fromName: null,
            messageId: '<m' . $id . '@example.be>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-09-18 09:12:00'),
            bodyText: 'Bonjour',
            bodyHtml: '',
            isBulk: $isBulk
        );
    }
}
