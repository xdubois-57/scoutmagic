<?php

declare(strict_types=1);

namespace Tests\Core\Net;

use Core\Net\WhoisRegistration;
use PHPUnit\Framework\TestCase;

/**
 * The handful of facts read out of a WHOIS response — and the ones that
 * are deliberately not read.
 */
final class WhoisRegistrationTest extends TestCase
{
    /** The ICANN gTLD template, which every `.com` registry prints. */
    private const GTLD = <<<'TXT'
        Domain Name: EXAMPLE.COM
        Registry Domain ID: 2336799_DOMAIN_COM-VRSN
        Registrar WHOIS Server: whois.registrar.example
        Registrar: Example Registrar, LLC
        Updated Date: 2025-08-14T07:01:31Z
        Creation Date: 1995-08-14T04:00:00Z
        Registry Expiry Date: 2027-08-13T04:00:00Z
        Domain Status: clientTransferProhibited
        Name Server: NS1.EXAMPLE.NET
        Name Server: NS2.EXAMPLE.NET
        >>> Last update of whois database: 2026-09-06T10:00:00Z <<<
        TXT;

    /** DNS Belgium, which is what most of this fleet actually answers on. */
    private const BE = <<<'TXT'
        Domain:	unite.be
        Status:	NOT AVAILABLE
        Registered:	Tue Mar 4 2014

        Registrar:
        	Name:	 Example Hosting SA
        	Website: https://hosting.example

        Nameservers:
        	ns1.hosting.example
        	ns2.hosting.example
        TXT;

    public function testTheGtldTemplateReadsAsAllFiveFacts(): void
    {
        $registration = WhoisRegistration::parse(self::GTLD);

        $this->assertSame('Example Registrar, LLC', $registration['registrar']);
        $this->assertSame('1995-08-14T04:00:00Z', $registration['created_at']);
        $this->assertSame('2025-08-14T07:01:31Z', $registration['updated_at']);
        $this->assertSame('2027-08-13T04:00:00Z', $registration['expires_at']);
        $this->assertSame('clientTransferProhibited', $registration['status']);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $registration['name_servers']);
    }

    public function testABelgianRecordReadsToo(): void
    {
        // A different vocabulary for the same facts, which is the whole
        // reason the reading is an alias table rather than one regex.
        $registration = WhoisRegistration::parse(self::BE);

        $this->assertSame('Tue Mar 4 2014', $registration['created_at']);
        $this->assertSame('Example Hosting SA', $registration['registrar']);
        $this->assertSame('NOT AVAILABLE', $registration['status']);
        // Bare hostnames under a `Nameservers:` heading, with no key at
        // all — the shape a flat `key: value` reading finds nothing in.
        $this->assertSame(['ns1.hosting.example', 'ns2.hosting.example'], $registration['name_servers']);
    }

    /**
     * `Name:` under `Registrar:` is a company. `Name:` under anything else
     * is somebody, and the rule above says it is never read.
     */
    public function testANameOutsideTheRegistrarBlockIsNotARegistrar(): void
    {
        $registration = WhoisRegistration::parse(implode("\n", [
            'Registrant:',
            "\tName:\t Marie Dupont",
            'Domain: unite.be',
        ]));

        $this->assertNull($registration['registrar']);
        $this->assertStringNotContainsString('Marie', (string) json_encode($registration));
    }

    /**
     * **The rule, pinned.**
     *
     * A unit's domain is often registered by a volunteer in their own
     * name, so the registrant block is a natural person's identity
     * (ARCHITECTURE.md §7.9). A parsed copy would be a clear-text column —
     * sortable, searchable, exportable — which is exactly what a raw
     * response kept encrypted and read by one person is not. What the
     * registry sent is kept whole; what this application *understands*
     * stops at the organisational half.
     */
    public function testTheRegistrantIsNeverRead(): void
    {
        $raw = self::GTLD . "\n" . implode("\n", [
            'Registrant Name: Marie Dupont',
            'Registrant Organization:',
            'Registrant Street: 12 rue des Scouts',
            'Registrant City: Namur',
            'Registrant Phone: +32.81000000',
            'Registrant Email: marie.dupont@example.be',
            'Admin Name: Marie Dupont',
            'Tech Email: marie.dupont@example.be',
        ]);

        $encoded = (string) json_encode(WhoisRegistration::parse($raw));

        $this->assertStringNotContainsString('Marie', $encoded);
        $this->assertStringNotContainsString('Dupont', $encoded);
        $this->assertStringNotContainsString('example.be', $encoded);
        $this->assertStringNotContainsString('rue des Scouts', $encoded);
        $this->assertStringNotContainsString('+32.81', $encoded);
    }

    /**
     * The block-heading formats — AFNIC's, RIPE's — where a contact's
     * fields are printed under a bare `Registrant:` line and carry the
     * SAME names the alias table looks for. `changed:` is a contact's
     * last edit, `State:` is the province they live in, and either in
     * `whois_registration` would be a natural person's data in a
     * clear-text, filterable column (§7.9).
     */
    public function testNothingInsideAPersonalBlockIsReadWhateverItIsCalled(): void
    {
        $registration = WhoisRegistration::parse(implode("\n", [
            'Domain Name: unite.example',
            'Registrar: Example Hosting SA',
            '',
            'Registrant:',
            '    Name: Marie Dupont',
            '    State: Namur',
            '    Changed: 2019-04-01',
            '    Status: retraitée',
            '',
            'Tech Contact:',
            '    Changed: 2018-02-03',
        ]));

        $this->assertSame('Example Hosting SA', $registration['registrar']);
        $this->assertNull($registration['status']);
        $this->assertNull($registration['updated_at']);
        $this->assertStringNotContainsString('Namur', (string) json_encode($registration));
        $this->assertStringNotContainsString('2019-04-01', (string) json_encode($registration));
    }

    /**
     * The same heading, in the dialect that prints its value on the same
     * line. `Registrant:` alone and `Registrant: Marie Dupont` are one
     * heading; reading only the first left the second wide open, because
     * a line with a value never opened a block and `State:` underneath
     * went straight into `status`.
     */
    public function testAPersonalHeadingWithItsValueOnTheSameLineOpensTheBlockToo(): void
    {
        $registration = WhoisRegistration::parse(implode("\n", [
            'Domain Name: unite.example',
            'Registrar: Example Hosting SA',
            'Registrant: Marie Dupont',
            'State: Namur',
            'Changed: 2019-04-01',
        ]));

        $this->assertSame('Example Hosting SA', $registration['registrar']);
        $this->assertNull($registration['status']);
        $this->assertNull($registration['updated_at']);
        $this->assertStringNotContainsString('Namur', (string) json_encode($registration));
        $this->assertStringNotContainsString('Marie', (string) json_encode($registration));
    }

    /** And a heading of the same name outside a personal block still reads. */
    public function testTheDomainBlockKeepsItsOwnDatesAndStatus(): void
    {
        $registration = WhoisRegistration::parse(implode("\n", [
            'Domain Name: unite.example',
            'Status: clientTransferProhibited',
            'Changed: 2026-03-04',
            '',
            'Registrant:',
            '    Changed: 2019-04-01',
        ]));

        $this->assertSame('clientTransferProhibited', $registration['status']);
        $this->assertSame('2026-03-04', $registration['updated_at']);
    }

    public function testTheCommentAndLegalBlockAreNotFields(): void
    {
        $registration = WhoisRegistration::parse(implode("\n", [
            '% This is the DNS Belgium WHOIS server.',
            '# Status: this notice is not a field',
            'Registrar: Example Registrar, LLC',
            'For more information visit: https://example',
        ]));

        $this->assertSame('Example Registrar, LLC', $registration['registrar']);
        $this->assertNull($registration['status']);
    }

    public function testAZoneDumpCannotBecomeARegistration(): void
    {
        $lines = ['Registrar: Example Registrar, LLC'];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = 'Name Server: ns' . $i . '.example.net';
        }

        $registration = WhoisRegistration::parse(implode("\n", $lines));

        $this->assertCount(WhoisRegistration::MAX_NAME_SERVERS, $registration['name_servers']);
    }

    public function testARegistrarNameCannotFillAPage(): void
    {
        $registration = WhoisRegistration::parse('Registrar: ' . str_repeat('a', 5000));

        $this->assertSame(
            WhoisRegistration::MAX_VALUE_LENGTH,
            mb_strlen((string) $registration['registrar'])
        );
    }

    /**
     * A response this alias table does not understand must not be claimed
     * as a registration — it is exactly the one somebody needs to open by
     * hand, and the raw is kept for that.
     */
    public function testAShapeNobodyRecognisedIsEmptyRatherThanInvented(): void
    {
        $this->assertTrue(WhoisRegistration::isEmpty(WhoisRegistration::parse("un texte\nsans deux-points")));
        $this->assertFalse(WhoisRegistration::isEmpty(WhoisRegistration::parse(self::GTLD)));
    }
}
