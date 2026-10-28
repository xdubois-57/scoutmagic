<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Mail\MailException;
use Modules\Registration\Service\DecisionEmailRetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionEmailRetryPolicyTest extends TestCase
{
    #[DataProvider('failures')]
    public function testOnlyKnownTemporaryRefusalsCanBeRetried(string $reason, bool $expected): void
    {
        $this->assertSame($expected, DecisionEmailRetryPolicy::isTemporary(new MailException($reason)));
    }

    public static function failures(): iterable
    {
        yield ['SMTP Error: 421 4.3.0 service unavailable', true];
        yield ['451 temporary failure', true];
        yield ['SMTP code: 452 mailbox full', true];
        yield ['Tous les fournisseurs ont échoué. Dernière raison : 421 4.7.0 retry later', true];
        yield ['SMTP Error: 550 5.1.1 unknown recipient', false];
        yield ['SMTP code: 535 authentication failed', false];
        yield ['SMTP Error: Could not connect to SMTP host.', true];
        yield ['SMTP connect() failed.', true];
        yield ['Connection refused on port 587', true];
        yield ['Timeout waiting for reply after DATA on port 443 through relay', false];
        yield ['Connection reset while writing DATA', false];
        yield ['Unknown transport failure', false];
        yield ['SMTP Error: The following recipients failed: SMTP code: 450 mailbox busy', false];
        yield ['SMTP Error: Could not connect to SMTP host: certificate verify failed', false];
    }

    public function testAnUnexpectedExceptionAndNoExceptionAreNotRetryable(): void
    {
        $this->assertFalse(DecisionEmailRetryPolicy::isTemporary(new \RuntimeException('421 temporary failure')));
        $this->assertFalse(DecisionEmailRetryPolicy::isTemporary(null));
    }
}
