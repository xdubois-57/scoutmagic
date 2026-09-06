<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use PHPUnit\Framework\TestCase;

/**
 * A receipt that arrives by email and is not filed says so, on BOTH paths.
 *
 * #175 was reported as « le reçu n'apparaît jamais dans les finances », and
 * the first thing anybody does with that is open /admin/journal. Two
 * different failures land there differently, and only one of them was
 * wired: the bytes could not be read (`inbound_receipt_unreadable`, added
 * with the encryption fix), and the bytes were read but finance refused to
 * file them — `FinanceMessageConsumer::onLinked()` catches that on purpose,
 * so the message stays associated, and until #175 it caught it in silence.
 * The courrier screen then shows the message as filed while the receipts
 * screen is empty and nothing anywhere says why.
 *
 * The reporter is a closure the COMPOSITION ROOTS install, so no unit test
 * reaches it: {@see UnattendedReceiptFilingTest} proves the consumer calls
 * whatever it was handed, and this file proves that both entry points hand
 * it something. Both, because the two are wired separately and the one
 * that matters is the scheduler — the relève runs there, with nobody
 * watching a request fail.
 */
final class InboundReceiptIsNotSilentTest extends TestCase
{
    /**
     * @return string[] the two roots that build a FinanceMessageConsumer
     */
    public static function compositionRoots(): array
    {
        return [
            'the web request' => ['public/index.php'],
            'the scheduler' => ['public/scheduler-bootstrap.php'],
        ];
    }

    private static function source(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/' . $relativePath);
        self::assertIsString($contents, $relativePath . ' is unreadable');

        return $contents;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('compositionRoots')]
    public function testTheRootReportsAReceiptItCouldNotRead(string $root): void
    {
        $this->assertStringContainsString(
            'inbound_receipt_unreadable',
            self::source($root),
            $root . ' no longer journals an attachment whose bytes it could not read'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('compositionRoots')]
    public function testTheRootReportsAReceiptFinanceRefused(string $root): void
    {
        $contents = self::source($root);

        $this->assertStringContainsString(
            'inbound_receipt_not_filed',
            $contents,
            $root . ' no longer journals a receipt finance refused to file (#175)'
        );
        // The closure, not merely the string: an event type in a comment
        // would satisfy the assertion above and report nothing.
        $this->assertMatchesRegularExpression(
            '/function \(\\\\Throwable \$e, string \$mimeType, int \$attachmentId\)/',
            $contents,
            $root . ' no longer installs the reporter FinanceMessageConsumer calls'
        );
    }

    /**
     * The one thing the journal must NOT carry.
     *
     * A filename is personal data (ARCHITECTURE.md §7.9) — « facture
     * Dupont.pdf » names a family — and it is the single most tempting
     * field to add here, because it is what a treasurer would recognise.
     * The attachment id finds the same row without naming anybody.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('compositionRoots')]
    public function testTheReportCarriesNoFilename(string $root): void
    {
        $contents = self::source($root);
        $start = strpos($contents, 'inbound_receipt_not_filed');
        $this->assertIsInt($start);

        // The log() call this sits in, generously bounded.
        $call = substr($contents, $start, 600);

        $this->assertStringNotContainsString('filename', $call, $root . ' journals a filename with the failure');
        $this->assertStringNotContainsString('->filename', $call);
    }
}
