<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Modules\Finance\Service\EpcPayload;
use PHPUnit\Framework\TestCase;

/**
 * The EPC069-12 payload, field by field.
 *
 * It is tested at this level rather than through the QR image because a
 * QR is a lossless encoding of these eleven lines: everything that can be
 * wrong with a payment request is wrong here first, and reading it back
 * out of a PNG would prove nothing extra while making every failure
 * unreadable.
 */
class EpcPayloadTest extends TestCase
{
    public function testTheElevenLinesAreInTheOrderTheSpecificationFixes(): void
    {
        $lines = explode("\n", EpcPayload::build(
            'Unité SV025 Ottignies',
            'BE71 0961 2345 6769',
            'GKCCBEBB',
            4500,
            '+++123/4567/89012+++'
        ));

        $this->assertCount(11, $lines);
        $this->assertSame('BCD', $lines[0], 'service tag');
        $this->assertSame('002', $lines[1], 'version');
        $this->assertSame('1', $lines[2], 'UTF-8 character set');
        $this->assertSame('SCT', $lines[3], 'SEPA credit transfer');
        $this->assertSame('GKCCBEBB', $lines[4]);
        $this->assertSame('Unité SV025 Ottignies', $lines[5]);
        $this->assertSame('BE71096123456769', $lines[6], 'the IBAN travels without its display spaces');
        $this->assertSame('EUR45.00', $lines[7]);
        $this->assertSame('', $lines[8], 'purpose');
        $this->assertSame('', $lines[9], 'structured creditor reference — see the class docblock');
        $this->assertSame('+++123/4567/89012+++', $lines[10]);
    }

    /**
     * A Belgian structured communication is not an ISO 11649 "RF"
     * reference, so it goes in the UNSTRUCTURED remittance field. Putting
     * it in line 10 would make banking apps reject the payload.
     */
    public function testTheBelgianCommunicationTravelsUnstructured(): void
    {
        $lines = explode("\n", EpcPayload::build('U', 'BE71096123456769', null, 100, '+++123/4567/89012+++'));

        $this->assertSame('', $lines[9]);
        $this->assertSame('+++123/4567/89012+++', $lines[10]);
    }

    public function testWithoutABicTheFieldIsEmptyRatherThanAbsent(): void
    {
        $lines = explode("\n", EpcPayload::build('U', 'BE71096123456769', null, 100, 'REF'));

        $this->assertCount(11, $lines);
        $this->assertSame('', $lines[4]);
    }

    /**
     * The amount is a decimal point and exactly two decimals, whatever a
     * French-speaking screen shows elsewhere: this is a machine field.
     */
    public function testTheAmountIsWrittenTheWayABankReadsIt(): void
    {
        $this->assertSame('EUR0.05', explode("\n", EpcPayload::build('U', 'BE1', null, 5, 'R'))[7]);
        $this->assertSame('EUR12.50', explode("\n", EpcPayload::build('U', 'BE1', null, 1250, 'R'))[7]);
        $this->assertSame('EUR1234.00', explode("\n", EpcPayload::build('U', 'BE1', null, 123400, 'R'))[7]);
    }

    /**
     * The specification's own field lengths. Truncating here rather than
     * at each call site is what stops one surface producing a payload a
     * scanner refuses while another produces a valid one.
     */
    public function testTheSpecificationsFieldLengthsAreEnforcedHere(): void
    {
        $lines = explode("\n", EpcPayload::build(
            str_repeat('N', 100),
            'BE71096123456769',
            'GKCCBEBBXXXTOOLONG',
            100,
            str_repeat('C', 200)
        ));

        $this->assertSame(11, strlen($lines[4]), 'BIC is 11 characters');
        $this->assertSame(70, strlen($lines[5]), 'beneficiary name is 70');
        $this->assertSame(140, strlen($lines[10]), 'unstructured remittance is 140');
    }

    // --- Reading one back: the door scanner's tolerant half ---

    public function testAPayloadRoundTripsItsCommunication(): void
    {
        // The one field a reader ever wants back, read off the same line
        // the builder writes it to — which is the whole reason both
        // directions live in this file.
        $payload = EpcPayload::build('Unité SV025', 'BE71096123456769', null, 4600, '+++123/4567/89412+++');

        $this->assertSame('+++123/4567/89412+++', EpcPayload::communicationFrom($payload));
    }

    public function testCarriageReturnsAndTrailingBlanksAreTolerated(): void
    {
        // A QR decoder may hand back either line ending, and the payload
        // arrives as whatever the camera read.
        $payload = "BCD\r\n002\r\n1\r\nSCT\r\n\r\nUnité\r\nBE71096123456769\r\nEUR46.00\r\n\r\n\r\n+++123/4567/89412+++\r\n\r\n";

        $this->assertSame('+++123/4567/89412+++', EpcPayload::communicationFrom($payload));
    }

    public function testWhatIsNotATransferPayloadReadsAsNull(): void
    {
        // Null is the ORDINARY answer — most of what a door scanner reads
        // is a bare ticket reference — so it means « not one of these »
        // rather than « something went wrong ».
        $this->assertNull(EpcPayload::communicationFrom('X7K2-9QMF-A3'));
        $this->assertNull(EpcPayload::communicationFrom(''));
        // The right shape, the wrong service tag.
        $this->assertNull(EpcPayload::communicationFrom("ABC\n002\n1\nSCT\n\nU\nBE1\nEUR1.00\n\n\n+++1+++"));
        // Truncated: the communication is line 11 and there is no line 11.
        $this->assertNull(EpcPayload::communicationFrom("BCD\n002\n1\nSCT"));
    }

    public function testAnEmptyCommunicationReadsAsNullRatherThanAnEmptyString(): void
    {
        // A caller looks the answer up; an empty string would be a lookup
        // for nothing that could match the wrong row on a lax comparison.
        $this->assertNull(EpcPayload::communicationFrom("BCD\n002\n1\nSCT\n\nU\nBE1\nEUR1.00\n\n\n   "));
    }
}
