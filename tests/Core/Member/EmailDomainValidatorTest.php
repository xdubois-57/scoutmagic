<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\EmailDomainValidator;
use PHPUnit\Framework\TestCase;

/**
 * The one deliberate exception to this codebase's "no real network calls
 * in tests" convention (see Core\Maintenance\GitHubReleaseClientInterface
 * for the usual approach — inject a fake instead) — checkdnsrr() has no
 * seams to fake without over-engineering a one-line wrapper. Uses only
 * RFC 2606 reserved names (.invalid) and a domain stable enough that its
 * absence more likely means "no network in this environment" than "real
 * regression" — skips rather than fails in that case.
 */
class EmailDomainValidatorTest extends TestCase
{
    private EmailDomainValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new EmailDomainValidator();
        if (!$this->validator->hasValidDomain('someone@gmail.com')) {
            $this->markTestSkipped('No outbound DNS resolution available in this environment.');
        }
    }

    public function testAcceptsAWellKnownResolvableDomain(): void
    {
        $this->assertTrue($this->validator->hasValidDomain('someone@gmail.com'));
    }

    public function testRejectsAnRfc2606ReservedInvalidDomain(): void
    {
        $this->assertFalse($this->validator->hasValidDomain('someone@this-does-not-resolve.invalid'));
    }

    public function testRejectsAnAddressWithNoDomainAtAll(): void
    {
        $this->assertFalse($this->validator->hasValidDomain('no-at-sign'));
    }
}
