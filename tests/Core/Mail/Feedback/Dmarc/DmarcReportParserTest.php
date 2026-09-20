<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Mail\Feedback\Dmarc\DmarcReportParser;
use PHPUnit\Framework\TestCase;

/**
 * Reading a report a stranger sent (RFC 7489).
 */
class DmarcReportParserTest extends TestCase
{
    private DmarcReportParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DmarcReportParser();
    }

    /**
     * A report in the shape Google actually posts: the unit's own relay
     * authenticating, and one source that is not.
     */
    private static function report(string $extraRecords = ''): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8" ?>
        <feedback>
          <report_metadata>
            <org_name>google.com</org_name>
            <email>noreply-dmarc-support@google.com</email>
            <report_id>18262394710041662899</report_id>
            <date_range><begin>1789344000</begin><end>1789430400</end></date_range>
          </report_metadata>
          <policy_published>
            <domain>unite.be</domain>
            <adkim>r</adkim><aspf>r</aspf>
            <p>none</p><sp>none</sp><pct>100</pct>
          </policy_published>
          <record>
            <row>
              <source_ip>185.12.80.100</source_ip>
              <count>42</count>
              <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
            </row>
            <identifiers><header_from>unite.be</header_from></identifiers>
          </record>
          <record>
            <row>
              <source_ip>203.0.113.77</source_ip>
              <count>3</count>
              <policy_evaluated><disposition>quarantine</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated>
            </row>
            <identifiers><header_from>unite.be</header_from></identifiers>
          </record>
          {$extraRecords}
        </feedback>
        XML;
    }

    public function testAnOrdinaryReportIsRead(): void
    {
        $report = $this->parser->parse(self::report());

        $this->assertNotNull($report);
        $this->assertSame('google.com', $report->organisation);
        $this->assertSame('18262394710041662899', $report->reportId);
        $this->assertSame('unite.be', $report->domain);
        $this->assertSame('none', $report->policy);
        $this->assertCount(2, $report->records);
        // The reporter writes Unix timestamps; 1789344000 is midnight UTC
        // on the 14th, and the window is the day that follows.
        $this->assertSame('2026-09-14 00:00:00', $report->begin->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 00:00:00', $report->end->format('Y-m-d H:i:s'));
    }

    public function testTheCountersAddUp(): void
    {
        $report = $this->parser->parse(self::report());

        $this->assertNotNull($report);
        $this->assertSame(45, $report->totalMessages());
        $this->assertSame(42, $report->authenticatedMessages());
    }

    /**
     * **SPF or DKIM, not both** (RFC 7489 §6.6.2). Demanding both would
     * show a unit's own relay as failing while every message it sends
     * arrives perfectly well, and send somebody chasing a problem that is
     * not there.
     */
    public function testEitherAuthenticationAloneCounts(): void
    {
        $onlySpf = <<<XML
          <record>
            <row>
              <source_ip>185.12.80.101</source_ip><count>7</count>
              <policy_evaluated><disposition>none</disposition><dkim>fail</dkim><spf>pass</spf></policy_evaluated>
            </row>
            <identifiers><header_from>unite.be</header_from></identifiers>
          </record>
        XML;

        $report = $this->parser->parse(self::report($onlySpf));

        $this->assertNotNull($report);
        $this->assertTrue($report->records[2]->authenticated());
        $this->assertSame(49, $report->authenticatedMessages());
    }

    /**
     * **The doctype is refused, not disarmed.** Nothing in a DMARC report
     * needs one, and an XML parser that resolves external entities is how
     * a remote document reads local files.
     */
    public function testADocumentCarryingADoctypeIsRefusedOutright(): void
    {
        $attack = <<<XML
        <?xml version="1.0"?>
        <!DOCTYPE feedback [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <feedback>
          <report_metadata><report_id>1</report_id>
            <date_range><begin>1789344000</begin><end>1789430400</end></date_range></report_metadata>
          <policy_published><domain>unite.be</domain><p>none</p></policy_published>
          <record><row><source_ip>203.0.113.9</source_ip><count>1</count>
            <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
          </row><identifiers><header_from>&xxe;</header_from></identifiers></record>
        </feedback>
        XML;

        $this->assertNull($this->parser->parse($attack));
    }

    /** Anything that will not parse produces nothing, and throws nothing. */
    public function testRubbishProducesNothing(): void
    {
        $this->assertNull($this->parser->parse(''));
        $this->assertNull($this->parser->parse('not xml at all'));
        $this->assertNull($this->parser->parse('<feedback></feedback>'));
    }

    /**
     * **A source address that is not an address is dropped.** It is shown
     * on a screen and grouped on, so a reporter's typo — or an injected
     * string — must never become a row.
     */
    public function testALineWhoseSourceIsNotAnIpIsDropped(): void
    {
        $bad = <<<XML
          <record>
            <row><source_ip>&lt;script&gt;alert(1)&lt;/script&gt;</source_ip><count>9</count>
              <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
            </row>
            <identifiers><header_from>unite.be</header_from></identifiers>
          </record>
        XML;

        $report = $this->parser->parse(self::report($bad));

        $this->assertNotNull($report);
        $this->assertCount(2, $report->records, 'the two real lines remain, the third does not.');
        $this->assertSame(45, $report->totalMessages());
    }

    /** IPv6 is an address like any other. */
    public function testAnIpV6SourceIsKept(): void
    {
        $v6 = <<<XML
          <record>
            <row><source_ip>2a00:1450:400c:c09::22e</source_ip><count>5</count>
              <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>fail</spf></policy_evaluated>
            </row>
            <identifiers><header_from>unite.be</header_from></identifiers>
          </record>
        XML;

        $report = $this->parser->parse(self::report($v6));

        $this->assertNotNull($report);
        $this->assertCount(3, $report->records);
        $this->assertSame('2a00:1450:400c:c09::22e', $report->records[2]->sourceIp);
    }

    /** A report with no usable line at all is no report. */
    public function testAReportWithNoUsableLineIsNothing(): void
    {
        $empty = <<<XML
        <?xml version="1.0"?>
        <feedback>
          <report_metadata><org_name>x</org_name><report_id>1</report_id>
            <date_range><begin>1789344000</begin><end>1789430400</end></date_range></report_metadata>
          <policy_published><domain>unite.be</domain><p>none</p></policy_published>
        </feedback>
        XML;

        $this->assertNull($this->parser->parse($empty));
    }

    /**
     * An unrecognised disposition reads as `none` — what a receiver does
     * by default, and the only safe way to be wrong: saying a message was
     * rejected when it was delivered sends somebody chasing a delivery
     * problem that does not exist.
     */
    public function testAnUnknownDispositionReadsAsNone(): void
    {
        $odd = <<<XML
          <record>
            <row><source_ip>203.0.113.5</source_ip><count>1</count>
              <policy_evaluated><disposition>banana</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated>
            </row>
            <identifiers><header_from>unite.be</header_from></identifiers>
          </record>
        XML;

        $report = $this->parser->parse(self::report($odd));

        $this->assertNotNull($report);
        $this->assertSame('none', $report->records[2]->disposition);
    }

    /** A missing or nonsensical period loses the report rather than filing it under 1970. */
    public function testAReportWithoutAUsablePeriodIsRefused(): void
    {
        $noRange = str_replace(
            '<date_range><begin>1789344000</begin><end>1789430400</end></date_range>',
            '<date_range><begin></begin><end>1789430400</end></date_range>',
            self::report()
        );

        $this->assertNull($this->parser->parse($noRange));
    }

    /**
     * **A period that ends in the future is kept for ever**, which is why
     * it is refused rather than merely odd.
     *
     * `purgeBefore()` cuts on `period_end`, so a report claiming to end in
     * 2099 outlives every retention rule this site has and sits in each
     * thirty-day window until somebody notices — from a document a
     * stranger chose to send, with nothing anywhere saying so. The report
     * is the cheap thing to lose: another arrives tomorrow.
     */
    public function testAPeriodEndingBeyondOurClockIsRefused(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00', new \DateTimeZone('UTC'));
        $far = str_replace(
            '<end>1789430400</end>',
            '<end>4102444800</end>', // 2100-01-01
            self::report()
        );

        $this->assertNull($this->parser->parse($far, $now));
    }

    /**
     * And a few hours ahead is NOT refused: the skew being allowed for is
     * a reporter's clock and timezone handling, not ours, and refusing
     * those would lose real reports to protect against nothing.
     */
    public function testAPeriodEndingSlightlyAheadIsStillRead(): void
    {
        $now = new \DateTimeImmutable('@1789430400');
        $report = $this->parser->parse(self::report(), $now->modify('-6 hours'));

        $this->assertNotNull($report);
    }

    /** An end before its begin describes no window at all. */
    public function testAPeriodThatEndsBeforeItBeginsIsRefused(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00', new \DateTimeZone('UTC'));
        $backwards = str_replace(
            '<date_range><begin>1789344000</begin><end>1789430400</end></date_range>',
            '<date_range><begin>1789430400</begin><end>1789344000</end></date_range>',
            self::report()
        );

        $this->assertNull($this->parser->parse($backwards, $now));
    }

    /** The ordinary case still reads, so the two refusals above are not a blanket one. */
    public function testAnOrdinaryPastPeriodIsRead(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00', new \DateTimeZone('UTC'));

        $this->assertNotNull($this->parser->parse(self::report(), $now));
    }
}
