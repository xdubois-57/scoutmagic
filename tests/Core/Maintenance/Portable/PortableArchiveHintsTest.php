<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Maintenance\Portable\PortableArchiveHints;
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Portable\PortableManifest;
use PHPUnit\Framework\TestCase;

/**
 * The clear hints of a portable archive (#719): mandatory, read back
 * exactly, unknown keys ignored, and every value bounded and stripped of
 * control characters — the comment is editable by whoever holds the file,
 * and a bootstrap shows it on a page.
 */
class PortableArchiveHintsTest extends TestCase
{
    private function comment(?PortableArchiveHints $hints = null): string
    {
        return PortableKeys::comment(
            PortableKeys::newDerivation(),
            $hints ?? PortableArchiveHints::now('2.4.1', 'https://unite.example', PortableArchiveHints::KIND_REMOTE, 3)
        );
    }

    /** @return array<string, mixed> */
    private function document(?PortableArchiveHints $hints = null): array
    {
        $document = json_decode($this->comment($hints), true);
        $this->assertIsArray($document);

        return $document;
    }

    public function testTheCommentCarriesEveryHintInClearUnderTheNewFormat(): void
    {
        $document = $this->document();

        $this->assertSame(2, PortableManifest::FORMAT_VERSION);
        $this->assertSame(PortableManifest::FORMAT_VERSION, $document['format_version']);
        $this->assertSame('2.4.1', $document['scoutmagic_version']);
        $this->assertSame('https://unite.example', $document['site_url']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $document['created_at']);
        $this->assertSame('remote', $document['kind']);
        $this->assertSame(3, $document['passphrase_generation']);
        $this->assertIsArray($document['key_derivation']);
    }

    public function testTheHintsReadBackExactly(): void
    {
        $written = new PortableArchiveHints(
            '1.1.0',
            'https://unite.example',
            new \DateTimeImmutable('2026-09-30 21:15:00', new \DateTimeZone('UTC')),
            PortableArchiveHints::KIND_MANUAL,
            null
        );

        $read = PortableKeys::parseHints($this->comment($written));

        $this->assertSame('1.1.0', $read->version);
        $this->assertSame('https://unite.example', $read->siteUrl);
        $this->assertSame('2026-09-30T21:15:00+00:00', $read->createdAt->format(\DateTimeInterface::ATOM));
        $this->assertSame('Manuelle', $read->kindLabel());
        $this->assertNull($read->passphraseGeneration);
    }

    /** A date written from another zone is stored in UTC. */
    public function testTheDateIsStoredInUtc(): void
    {
        $hints = new PortableArchiveHints(
            '1.1.0',
            '',
            new \DateTimeImmutable('2026-06-01 12:00:00', new \DateTimeZone('Europe/Brussels')),
            PortableArchiveHints::KIND_MANUAL,
            null
        );

        $this->assertSame('2026-06-01T10:00:00Z', $hints->toArray()['created_at']);
    }

    public function testAManualArchiveNeverClaimsAGeneration(): void
    {
        $hints = PortableArchiveHints::now('1.1.0', 'https://u.example', PortableArchiveHints::KIND_MANUAL, 7);

        $this->assertNull($hints->passphraseGeneration);
        $this->assertSame('Automatique, hors site', PortableArchiveHints::now('1', '', 'remote', 2)->kindLabel());
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $document = $this->document();
        $document['a_later_field'] = ['anything' => true];

        $hints = PortableKeys::parseHints((string) json_encode($document));

        $this->assertSame('2.4.1', $hints->version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mandatoryFields(): iterable
    {
        foreach (['scoutmagic_version', 'site_url', 'created_at', 'kind', 'passphrase_generation'] as $field) {
            yield $field => [$field];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mandatoryFields')]
    public function testEveryHintIsMandatory(string $field): void
    {
        $document = $this->document();
        unset($document[$field]);

        $this->expectException(BackupException::class);
        PortableKeys::parseHints((string) json_encode($document));
    }

    public function testAMalformedHintIsRefused(): void
    {
        foreach (
            [
                ['kind' => 'other'],
                ['passphrase_generation' => 0],
                ['passphrase_generation' => '3'],
                ['created_at' => 'hier'],
                ['scoutmagic_version' => '<script>'],
            ] as $override
        ) {
            try {
                PortableKeys::parseHints((string) json_encode(array_merge($this->document(), $override)));
                $this->fail('Accepted ' . json_encode($override));
            } catch (BackupException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Shown on the bootstrap's page as plain text: no control characters,
     * no unbounded length. HTML is the page's to escape; this is what a
     * hostile comment cannot smuggle past it.
     */
    public function testAHostileSiteUrlIsBoundedAndStrippedOfControlCharacters(): void
    {
        $document = $this->document();
        $document['site_url'] = "https://a.example/\u{202E}\x07" . str_repeat('x', 1000);

        $hints = PortableKeys::parseHints((string) json_encode($document));

        $this->assertSame(PortableArchiveHints::MAX_TEXT_LENGTH, mb_strlen($hints->siteUrl));
        $this->assertStringStartsWith('https://a.example/xxx', $hints->siteUrl);
    }

    /** A format-1 archive is refused by name, never guessed at. */
    public function testAnArchiveOfTheFirstFormatIsRefused(): void
    {
        $document = $this->document();
        $document['format_version'] = 1;

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('format que cette version ne sait pas lire');
        PortableKeys::parseHints((string) json_encode($document));
    }
}
