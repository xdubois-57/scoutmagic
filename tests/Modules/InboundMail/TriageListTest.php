<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\TriageList;
use PHPUnit\Framework\TestCase;

/**
 * Mail sent to a list — a newsletter, a notification — is folded away on
 * every triage screen behind « Afficher le courrier automatique (N) ».
 *
 * The screens read that from `InboundMessage::$isBulk`, which for a long
 * time did not exist: the read raised a warning, answered null, and every
 * newsletter was counted and listed as somebody's message.
 */
final class TriageListTest extends TestCase
{
    public function testMailSentToAListIsFoldedAwayAndCounted(): void
    {
        $screen = TriageList::screen($this->rows(), TriageList::STATUS_ALL, false, static fn(): array => [], 0);

        $this->assertSame([7], $this->ids($screen['messages']));
        $this->assertSame(1, $screen['bulk_count']);
    }

    public function testAskingForItShowsItToo(): void
    {
        $screen = TriageList::screen($this->rows(), TriageList::STATUS_ALL, true, static fn(): array => [], 0);

        $this->assertSame([7, 8], $this->ids($screen['messages']));
        $this->assertSame(1, $screen['bulk_count']);
        $this->assertTrue($screen['include_bulk']);
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
