<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Mail\Feedback\Dmarc\BoundedArchive;
use Core\Mail\Feedback\Dmarc\DmarcConsumer;
use Core\Mail\Feedback\Dmarc\DmarcReportParser;
use Core\Mail\Feedback\Dmarc\DmarcReportRepository;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\MessagePayload;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[Group('database')]
class DmarcConsumerTest extends TestCase
{
    private \PDO $pdo;
    private DmarcConsumer $consumer;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->consumer = new DmarcConsumer(
            new DmarcReportParser(),
            new DmarcReportRepository($this->pdo),
            new BoundedArchive()
        );
    }

    private static function xml(string $reportId = 'r-1'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" ?>
        <feedback>
          <report_metadata><org_name>google.com</org_name><report_id>{$reportId}</report_id>
            <date_range><begin>1789344000</begin><end>1789430400</end></date_range></report_metadata>
          <policy_published><domain>unite.be</domain><p>none</p></policy_published>
          <record><row><source_ip>185.12.80.100</source_ip><count>42</count>
            <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
          </row><identifiers><header_from>unite.be</header_from></identifiers></record>
        </feedback>
        XML;
    }

    private function candidate(): CandidateMessage
    {
        return new CandidateMessage(
            mailboxId: 1,
            subject: 'Report domain: unite.be',
            fromEmail: 'noreply-dmarc-support@google.com',
            fromName: null,
            messageId: 'r@google.com',
            inReplyTo: null,
            references: [],
            toEmails: ['dmarc@unite.be'],
            sentAt: new \DateTimeImmutable('-1 hour'),
            bodyText: '',
            bodyHtml: ''
        );
    }

    private function payload(string $bytes, string $mime): MessagePayload
    {
        return new MessagePayload('report.xml.gz', $mime, $bytes);
    }

    /** The ordinary case, end to end: Google's gzipped report. */
    public function testAGzippedReportIsUnpackedParsedAndRecorded(): void
    {
        $this->consumer->analyzePayloads(
            $this->candidate(),
            [$this->payload((string) gzencode(self::xml()), 'application/gzip')]
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_sources')->fetchColumn());
        $this->assertSame(
            42,
            (int) $this->pdo->query('SELECT message_count FROM mail_dmarc_sources')->fetchColumn()
        );
    }

    /** Some reporters post the XML bare. */
    public function testAnUncompressedReportIsRecordedToo(): void
    {
        $this->consumer->analyzePayloads(
            $this->candidate(),
            [$this->payload(self::xml(), 'application/xml')]
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
    }

    /**
     * **The same report read twice is routine**, not exceptional: the sync
     * runs its analysis pass before the Message-ID dedup, so a UIDVALIDITY
     * reset or a message sitting in two watched folders brings it back.
     */
    public function testTheSameReportReadTwiceIsRecordedOnce(): void
    {
        $payload = [$this->payload((string) gzencode(self::xml()), 'application/gzip')];

        $this->consumer->analyzePayloads($this->candidate(), $payload);
        $this->consumer->analyzePayloads($this->candidate(), $payload);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_sources')->fetchColumn());
    }

    /** Rubbish in the attachment records nothing, and throws nothing. */
    public function testAnUnreadablePayloadRecordsNothing(): void
    {
        $this->consumer->analyzePayloads(
            $this->candidate(),
            [$this->payload('not an archive at all', 'application/gzip')]
        );

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
    }

    /**
     * **A decompression bomb records nothing and costs nothing**, which is
     * the whole point of reading a stranger's archive through
     * {@see BoundedArchive}.
     */
    public function testADecompressionBombRecordsNothing(): void
    {
        $bomb = (string) gzencode(str_repeat("\0", 40 * 1024 * 1024));

        $this->consumer->analyzePayloads($this->candidate(), [$this->payload($bomb, 'application/gzip')]);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
    }

    /** It claims no business object — nobody's triage list grows a row. */
    public function testItClaimsNothing(): void
    {
        $result = $this->consumer->analyzePayloads(
            $this->candidate(),
            [$this->payload((string) gzencode(self::xml()), 'application/gzip')]
        );

        $this->assertTrue($result->isEmpty());
        $this->assertTrue($this->consumer->analyze($this->candidate())->isEmpty());
    }

    /**
     * **The ceiling it declares has to be one a real report fits under**,
     * and the types have to be the ones reporters actually send. A
     * consumer that asked for a type nobody posts would be wired,
     * registered, scoped — and silent for ever.
     */
    public function testItAsksForTheTypesReportersActuallySend(): void
    {
        $types = $this->consumer->payloadMimeTypes();

        $this->assertContains('application/gzip', $types, 'Google gzips.');
        $this->assertContains('application/zip', $types, 'several others zip.');
        $this->assertContains('application/xml', $types, 'and a few post it bare.');
        $this->assertGreaterThanOrEqual(1024 * 1024, $this->consumer->maxPayloadBytes());
    }

    public function testItShowsNoMessageToAnybody(): void
    {
        $this->assertSame(0, $this->consumer->triageAudienceCount());
        $this->assertFalse($this->consumer->canRead('anything', [], 'superadmin'));
    }
}
