<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Client\FetchedMessage;
use Modules\InboundMail\Mime\MimeMessageParser;
use PHPUnit\Framework\TestCase;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The fake carries the same per-message guard as the real client, so a
 * test of the sync loses one message where production loses one message.
 *
 * `MimeMessageParser` is written never to throw, and nothing found makes it
 * — so the parser is the double here: a parse that fails IS the subject.
 */
class FakeMailboxClientTest extends TestCase
{
    public function testAMessageTheParserCannotReadCostsThatMessageAlone(): void
    {
        $client = new FakeMailboxClient(new class extends MimeMessageParser {
            public function parse(string $raw, int $uid, string $folder): FetchedMessage
            {
                if ($uid === 6) {
                    throw new \ValueError('unknown charset');
                }

                return parent::parse($raw, $uid, $folder);
            }
        });
        foreach ([5, 6, 7] as $uid) {
            $client->addRawMessage('INBOX', $uid, InboundMailTestHelper::rawMessage([
                'From' => 'jeanne@example.be',
                'Message-ID' => '<m' . $uid . '@example.be>',
                'Subject' => 'Message ' . $uid,
            ], 'Bonjour'));
        }

        $fetched = $client->fetchSince('INBOX', 0, 10);

        $this->assertSame(['Message 5', 'Message 7'], array_map(
            static fn(FetchedMessage $message): string => $message->subject,
            $fetched
        ));
    }
}
