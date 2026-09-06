<?php

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\DnsRecordReader;
use PHPUnit\Framework\TestCase;

/**
 * The zone of one host, read at a moment and kept.
 *
 * No network: `query()` is the one call to the resolver and it exists
 * alone in its own method precisely so a test can answer for it. A test
 * that resolved a real name would be a test of somebody else's DNS.
 */
final class DnsRecordReaderTest extends TestCase
{
    private const NOW = '2026-09-06 12:00:00';

    public function testARecordComesOutAsTheLineADigUserWouldRecognise(): void
    {
        $reader = new ScriptedDnsReader([
            DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 300]],
            DNS_MX => [['type' => 'MX', 'pri' => 10, 'target' => 'mx.example.be', 'ttl' => 3600]],
        ]);

        $snapshot = $reader->read('unite.example.be', new \DateTimeImmutable(self::NOW));

        $this->assertSame(["300\t192.0.2.10"], $snapshot['records']['A']['values']);
        $this->assertSame(["3600\t10 mx.example.be"], $snapshot['records']['MX']['values']);
        $this->assertSame('unite.example.be', $snapshot['host']);
    }

    /**
     * The distinction the whole snapshot hangs on.
     *
     * « ce domaine n'a pas de MX » is a diagnosis. « je n'ai pas pu
     * demander » is a gap. A reader that returned an empty list for both
     * would let somebody chase a mail problem that is a resolver problem.
     */
    public function testNothingThereAndCouldNotAskAreDifferentAnswers(): void
    {
        $reader = new ScriptedDnsReader([
            DNS_A => [],
            DNS_MX => false,
        ]);

        $snapshot = $reader->read('unite.example.be', new \DateTimeImmutable(self::NOW));

        $this->assertSame(DnsRecordReader::STATUS_NONE, $snapshot['records']['A']['status']);
        $this->assertSame(DnsRecordReader::STATUS_FAILED, $snapshot['records']['MX']['status']);
    }

    /**
     * `dns_get_record()` takes no timeout: it obeys the system resolver,
     * which on an unreachable network sits for ten seconds per type. Seven
     * types would outlive the request a sender allows twenty seconds for.
     */
    public function testASlowResolverCostsTheLastTypesRatherThanTheRequest(): void
    {
        // A budget of a few milliseconds rather than the real eight
        // seconds: what is under test is that the walk stops, not how long
        // it is willing to wait.
        $reader = new SlowDnsReader(0.02);

        $snapshot = $reader->read('unite.example.be', new \DateTimeImmutable(self::NOW));

        $this->assertSame(DnsRecordReader::STATUS_FOUND, $snapshot['records']['A']['status']);
        $this->assertSame(
            DnsRecordReader::STATUS_SKIPPED,
            $snapshot['records']['SOA']['status'],
            'the budget has to end the walk rather than the request'
        );
        // Named rather than omitted, or the reader of the archive cannot
        // tell a skipped type from a type with nothing in it.
        $this->assertArrayHasKey('SOA', $snapshot['records']);
    }

    public function testOneTypeCannotContributeAWholeZone(): void
    {
        $many = [];
        for ($i = 0; $i < 200; $i++) {
            $many[] = ['type' => 'TXT', 'txt' => 'v=spf1 ip4:192.0.2.' . $i, 'ttl' => 60];
        }

        $reader = new ScriptedDnsReader([DNS_TXT => $many]);
        $snapshot = $reader->read('unite.example.be', new \DateTimeImmutable(self::NOW));

        $this->assertCount(DnsRecordReader::MAX_RECORDS_PER_TYPE, $snapshot['records']['TXT']['values']);
    }

    public function testTheTextSaysWhichSilenceEachOneIs(): void
    {
        $reader = new ScriptedDnsReader([
            DNS_A => [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 300]],
            DNS_MX => [],
            DNS_NS => false,
        ]);

        $text = DnsRecordReader::asText($reader->read('unite.example.be', new \DateTimeImmutable(self::NOW)));

        $this->assertStringContainsString('192.0.2.10', $text);
        $this->assertStringContainsString('aucun enregistrement de ce type', $text);
        $this->assertStringContainsString('la résolution a échoué', $text);
        // The whole reason the snapshot exists, said in the file itself.
        $this->assertStringContainsString('moment où le ticket est arrivé', $text);
    }

    /**
     * The text is written from a snapshot that has been through the
     * database and `json_decode()` since it was built, so its shape is a
     * claim about the past rather than a value in hand.
     */
    public function testTheTextSurvivesASnapshotThatLostItsShape(): void
    {
        $text = DnsRecordReader::asText(['host' => 'x.example.be', 'records' => ['A' => 'not an array']]);

        $this->assertStringContainsString('x.example.be', $text);
        $this->assertStringContainsString('--- A ---', $text);
    }
}

/** A resolver that takes longer than the budget allows. */
final class SlowDnsReader extends DnsRecordReader
{
    private int $calls = 0;

    public function __construct(private float $budget)
    {
        parent::__construct($budget);
    }

    protected function query(string $host, int $type): array|false
    {
        // The first type answers at once; the second spends the budget, so
        // everything after it must be reported as skipped rather than
        // asked for.
        if (++$this->calls > 1) {
            usleep((int) ($this->budget * 1_000_000) + 5_000);
        }

        return [['type' => 'A', 'ip' => '192.0.2.10', 'ttl' => 60]];
    }
}
