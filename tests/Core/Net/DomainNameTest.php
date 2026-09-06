<?php

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The host of a URL, and what may be asked about it.
 *
 * **The validation is a security boundary, not a formatting rule.** Every
 * name this application looks up came out of a remote installation's JSON,
 * and a WHOIS query is a line terminated by CRLF sent to a stranger's
 * socket: a "domain" carrying a newline is a second command on somebody
 * else's server. Nothing downstream escapes anything, and this is why.
 */
final class DomainNameTest extends TestCase
{
    public function testTheHostComesOutOfAUrlLowercasedAndWithoutItsTrailingDot(): void
    {
        $this->assertSame('unite.example.be', DomainName::hostOf('https://Unite.Example.BE./chefs'));
    }

    public function testABareHostnameIsAHostname(): void
    {
        // `instance_url` is whatever an administrator configured, and
        // parse_url() reads a bare name as a path.
        $this->assertSame('unite.example.be', DomainName::hostOf('unite.example.be'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableUrls(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'no host at all' => ['https:///chefs'],
            // An IP literal has no registration and no zone of its own to
            // read; asking a registry about one asks a different question.
            'an IPv4 literal' => ['https://192.0.2.10/'],
            'an IPv6 literal' => ['https://[2001:db8::1]/'],
            'a single label' => ['https://localhost/'],
        ];
    }

    #[DataProvider('unusableUrls')]
    public function testNothingUsableAnswersNull(string $url): void
    {
        $this->assertNull(DomainName::hostOf($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileNames(): array
    {
        return [
            // The one that matters: a WHOIS query is CRLF-terminated.
            'a carriage return' => ["unite.example.be\r\nversion"],
            'a newline' => ["unite.example.be\nhelp"],
            'a space and a second query' => ['unite.example.be help'],
            'a null byte' => ["unite.example.be\0"],
            'a shell metacharacter' => ['unite.example.be;id'],
            'an underscore' => ['_dmarc.unite.example.be'],
            'a leading hyphen' => ['-unite.example.be'],
            'an empty label' => ['unite..be'],
            'a label past 63 characters' => [str_repeat('a', 64) . '.be'],
            'a name past 253 characters' => [str_repeat('a.', 130) . 'be'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function testANameThatCouldCarryASecondCommandIsNeverQueryable(string $host): void
    {
        $this->assertFalse(DomainName::isQueryable($host));
        $this->assertNull(DomainName::hostOf('https://' . $host . '/'));
    }

    public function testTheCandidatesAreMostSpecificFirstAndStopAtTwoLabels(): void
    {
        // No public-suffix list: the registry is the authority on what is
        // registrable, so the walk asks it and the first answer wins.
        $this->assertSame(
            ['scouts.unite.example.be', 'unite.example.be', 'example.be'],
            DomainName::whoisCandidates('scouts.unite.example.be')
        );
    }

    public function testWwwIsNeverACandidate(): void
    {
        // It is a host, never a registration, and asking about it would
        // spend the most likely candidate on a certain miss.
        $this->assertSame(['unite.example.be', 'example.be'], DomainName::whoisCandidates('www.unite.example.be'));
    }

    public function testADeepSubdomainCostsThreeQueriesAndNotOnePerLevel(): void
    {
        $candidates = DomainName::whoisCandidates('a.b.c.d.example.be');

        $this->assertCount(DomainName::MAX_WHOIS_CANDIDATES, $candidates);
        $this->assertSame('a.b.c.d.example.be', $candidates[0]);
    }

    public function testATwoLabelHostIsItsOwnAndOnlyCandidate(): void
    {
        $this->assertSame(['example.be'], DomainName::whoisCandidates('example.be'));
    }

    public function testAHostileNameHasNoCandidatesAtAll(): void
    {
        $this->assertSame([], DomainName::whoisCandidates("example.be\r\nversion"));
    }

    public function testTheTldIsTheLastLabel(): void
    {
        $this->assertSame('be', DomainName::tldOf('unite.example.be'));
        $this->assertNull(DomainName::tldOf('localhost'));
    }
}
