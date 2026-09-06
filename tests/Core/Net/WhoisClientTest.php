<?php

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\WhoisClient;
use Core\Net\WhoisLookup;
use PHPUnit\Framework\TestCase;

/**
 * The walk from the root to the registry to the registrar.
 *
 * No socket: `ask()` is the one call to the network and it exists alone in
 * its own method so a test can answer for it. A test that opened port 43
 * would be a test of somebody else's registry, on a runner that very
 * probably cannot reach one.
 */
final class WhoisClientTest extends TestCase
{
    public function testTheRootIsAskedWhichRegistryServesTheTld(): void
    {
        $client = new ScriptedWhoisClient([
            'whois.iana.org' => ['be' => "domain:        BE\nwhois:        whois.dnsbelgium.be\n"],
            'whois.dnsbelgium.be' => ['unite.example.be' => "Domain:\tunite.example.be\nRegistered:\tTue Mar 4 2014\n"],
        ]);

        $response = $client->lookup('unite.example.be')->response;

        $this->assertNotNull($response);
        $this->assertSame('unite.example.be', $response->domain);
        $this->assertSame('whois.dnsbelgium.be', $response->server);
        $this->assertStringContainsString('Registered', $response->raw);
    }

    public function testTheRootIsAskedOncePerTldHoweverManyLookups(): void
    {
        // A fleet shares a handful of TLDs, and three hundred identical
        // questions to the root is how an address gets rate-limited out of
        // the answer entirely.
        $client = new ScriptedWhoisClient([
            'whois.iana.org' => ['be' => "whois: whois.dnsbelgium.be\n"],
            'whois.dnsbelgium.be' => [
                'a.example.be' => "Registered: 2014\n",
                'b.example.be' => "Registered: 2015\n",
            ],
        ]);

        $client->lookup('a.example.be');
        $client->lookup('b.example.be');

        $this->assertSame(1, $client->timesAsked('whois.iana.org', 'be'));
    }

    /**
     * The thin-registry hop. `.com` keeps the registrar's name and the
     * dates; the record a maintainer needs is at the registrar.
     */
    public function testAThinRegistryIsFollowedToTheRegistrarOnce(): void
    {
        $client = new ScriptedWhoisClient([
            'whois.iana.org' => ['com' => "whois: whois.verisign-grs.com\n"],
            'whois.verisign-grs.com' => [
                // Verisign answers about every name containing the string
                // unless the query says `domain `.
                'domain unite.example.com' => "Domain Name: UNITE.EXAMPLE.COM\n"
                    . "Registrar WHOIS Server: whois.registrar.example\n",
            ],
            'whois.registrar.example' => [
                'unite.example.com' => "Registrar: Example Registrar, LLC\nCreation Date: 1995\n",
            ],
        ]);

        $response = $client->lookup('unite.example.com')->response;

        $this->assertNotNull($response);
        $this->assertSame('whois.registrar.example', $response->server);
        $this->assertStringContainsString('Example Registrar, LLC', $response->raw);
    }

    public function testTheRegistryAnswerStandsWhenTheRegistrarDoesNot(): void
    {
        $client = new ScriptedWhoisClient([
            'whois.iana.org' => ['com' => "whois: whois.verisign-grs.com\n"],
            'whois.verisign-grs.com' => [
                'domain unite.example.com' => "Domain Name: UNITE.EXAMPLE.COM\n"
                    . "Registrar WHOIS Server: whois.registrar.example\n"
                    . "Creation Date: 1995-08-14T04:00:00Z\n",
            ],
            // The registrar's own server answers nothing at all.
        ]);

        $response = $client->lookup('unite.example.com')->response;

        $this->assertNotNull($response);
        $this->assertSame('whois.verisign-grs.com', $response->server);
    }

    /**
     * The walk up the labels: a subdomain has no registration of its own,
     * and the registry is the authority on what is registrable.
     */
    public function testASubdomainWalksUpUntilARegistryRecognisesSomething(): void
    {
        $client = new ScriptedWhoisClient([
            'whois.iana.org' => ['be' => "whois: whois.dnsbelgium.be\n"],
            'whois.dnsbelgium.be' => [
                'scouts.unite.example.be' => "Status: AVAILABLE\n",
                'unite.example.be' => "Registered: Tue Mar 4 2014\n",
            ],
        ]);

        $response = $client->lookup('scouts.unite.example.be')->response;

        $this->assertNotNull($response);
        $this->assertSame('unite.example.be', $response->domain);
    }

    /**
     * The distinction the whole outcome type exists for: a registry that
     * ANSWERED « ce nom n'est enregistré nulle part » is not a registry
     * that failed to answer. On a domain a site is currently serving, the
     * first is a hijack or a lapse.
     */
    public function testANameTheRegistryCallsFreeIsNotTheSameAsSilence(): void
    {
        $client = new ScriptedWhoisClient([
            'whois.iana.org' => ['be' => "whois: whois.dnsbelgium.be\n"],
            'whois.dnsbelgium.be' => ['example.be' => "%% No match for \"example.be\".\n"],
        ]);

        $lookup = $client->lookup('example.be');

        $this->assertSame(WhoisLookup::NOT_FOUND, $lookup->outcome);
        $this->assertNull($lookup->response);
    }

    public function testAServerThatDoesNotAnswerIsUnavailableRatherThanAnError(): void
    {
        // Port 43 is outbound TCP and plenty of shared hosts simply do not
        // have it. The callers are intake endpoints that already accepted
        // what they were sent.
        $this->assertSame(
            WhoisLookup::UNAVAILABLE,
            (new ScriptedWhoisClient([]))->lookup('unite.example.be')->outcome
        );
    }

    public function testAHostileNameIsNeverSentToAnybody(): void
    {
        $client = new ScriptedWhoisClient([]);

        $this->assertSame(WhoisLookup::UNAVAILABLE, $client->lookup("unite.example.be\r\nversion")->outcome);
        $this->assertSame([], $client->asked());
    }

    public function testASlowChainStopsRatherThanTheRequest(): void
    {
        $client = new SlowWhoisClient(0.02);

        $this->assertSame(WhoisLookup::UNAVAILABLE, $client->lookup('unite.example.be')->outcome);
    }

    public function testTheIanaReferralHasToLookLikeAServer(): void
    {
        $this->assertSame('whois.dnsbelgium.be', WhoisClient::whoisServerIn("whois: whois.dnsbelgium.be\n"));
        $this->assertNull(WhoisClient::whoisServerIn("whois: not a host\n"));
        $this->assertNull(WhoisClient::whoisServerIn("refer: whois.dnsbelgium.be\n"));
    }

    public function testTheNotFoundMarkersAreRecognisedWhateverTheCase(): void
    {
        $this->assertTrue(WhoisClient::readsAsNotFound('NO MATCH FOR "EXAMPLE.BE"'));
        $this->assertTrue(WhoisClient::readsAsNotFound('Status: AVAILABLE'));
        $this->assertFalse(WhoisClient::readsAsNotFound('Domain Status: clientTransferProhibited'));
    }
}

/** A chain slower than the budget allows. */
final class SlowWhoisClient extends WhoisClient
{
    public function __construct(private float $budget)
    {
        parent::__construct($budget);
    }

    protected function ask(string $server, string $query, float $deadline): ?string
    {
        usleep((int) ($this->budget * 1_000_000) + 5_000);

        return "whois: whois.registry.example\n";
    }
}
