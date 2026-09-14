<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Feedback\Bounce\BounceSeverity;
use Core\Mail\Feedback\Bounce\DeliveryStatusReport;
use PHPUnit\Framework\TestCase;

/**
 * Reading a `message/delivery-status` written by somebody else's server
 * (RFC 3464, roadmap IT-05).
 *
 * The bodies below are the shapes these really arrive in — Postfix,
 * Exchange, a Google rejection, a multi-recipient report where one address
 * worked — rather than the shape the RFC would produce if everyone
 * followed it. That is the point of the class: the input is written by
 * software this site does not control and cannot correct.
 *
 * The asymmetry that decides every doubtful case: missing a bounce delays
 * a diagnosis, inventing one suspends a parent's address on a misread
 * line. So every « can't tell » here must answer nothing.
 */
class DeliveryStatusReportTest extends TestCase
{
    public function testAPermanentRejectionNamesTheAddressAndTheCategory(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Reporting-MTA: dns; mail.unite.be\n"
            . "\n"
            . "Final-Recipient: rfc822; parent@exemple.be\n"
            . "Action: failed\n"
            . "Status: 5.1.1\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 <parent@exemple.be>: Recipient address rejected\n"
        );

        $this->assertCount(1, $reports);
        $this->assertSame('parent@exemple.be', $reports[0]->recipient);
        $this->assertSame(BounceSeverity::Permanent, $reports[0]->severity);
        $this->assertSame(BounceCategory::NoSuchAddress, $reports[0]->category);
        $this->assertSame('5.1.1', $reports[0]->statusCode);
    }

    public function testAFullMailboxIsTemporaryAndSaysSo(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\n"
            . "Action: failed\n"
            . "Status: 4.2.2\n"
        );

        $this->assertCount(1, $reports);
        $this->assertSame(BounceSeverity::Transient, $reports[0]->severity);
        $this->assertSame(BounceCategory::MailboxFull, $reports[0]->category);
    }

    /**
     * The same situation reported the other way round. A provider that
     * has given up on a full mailbox sends 5.2.2, and the category must
     * still read « boîte pleine » — the instruction to the parent is the
     * same one, only the blocking differs.
     */
    public function testAFullMailboxReportedPermanentIsStillAFullMailbox(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\nAction: failed\nStatus: 5.2.2\n"
        );

        $this->assertSame(BounceCategory::MailboxFull, $reports[0]->category);
        $this->assertSame(BounceSeverity::Permanent, $reports[0]->severity);
    }

    /**
     * **Only the failures.** One message to three addresses produces one
     * report naming all three, and two of them delivered. Marking those
     * would suspend addresses that work — the worst failure this class
     * can have.
     */
    public function testAddressesThatWorkedAreNotReturned(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Reporting-MTA: dns; mail.unite.be\n"
            . "\n"
            . "Final-Recipient: rfc822; ok@exemple.be\n"
            . "Action: delivered\n"
            . "Status: 2.0.0\n"
            . "\n"
            . "Final-Recipient: rfc822; casse@exemple.be\n"
            . "Action: failed\n"
            . "Status: 5.1.1\n"
            . "\n"
            . "Final-Recipient: rfc822; relaye@exemple.be\n"
            . "Action: relayed\n"
            . "Status: 2.0.0\n"
        );

        $this->assertCount(1, $reports);
        $this->assertSame('casse@exemple.be', $reports[0]->recipient);
    }

    /**
     * `delayed` is the far end still trying. Acting on it would mark an
     * address that is very often delivered to a few minutes later.
     */
    public function testAMessageStillBeingTriedIsNotABounce(): void
    {
        $this->assertSame([], DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\nAction: delayed\nStatus: 4.4.1\n"
        ));
    }

    /**
     * Plenty of servers send no `Status:` field at all and put the code in
     * the diagnostic. Refusing to read those would ignore a large share of
     * real bounces.
     */
    public function testTheCodeIsFoundInTheDiagnosticWhenThereIsNoStatusField(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\n"
            . "Action: failed\n"
            . "Diagnostic-Code: smtp; 552 5.2.2 Over quota\n"
        );

        $this->assertCount(1, $reports);
        $this->assertSame('5.2.2', $reports[0]->statusCode);
        $this->assertSame(BounceCategory::MailboxFull, $reports[0]->category);
    }

    /**
     * A long diagnostic arrives folded across lines (RFC 5322). Without
     * unfolding, the continuation looks like a new field — or like the
     * blank-line boundary between two recipients.
     */
    public function testAFoldedDiagnosticIsReadAsOneField(): void
    {
        // The code sits AFTER the fold, and the first line ends in a
        // colon. Unfolded, this is one field carrying `5.2.2`. Not
        // unfolded, the continuation is a line with no `:` — dropped
        // outright — and the bounce is silently not a bounce.
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\n"
            . "Action: failed\n"
            . "Diagnostic-Code: smtp; 550 Requested action not taken:\r\n"
            . "\tmailbox unavailable 5.2.2\n"
        );

        $this->assertCount(1, $reports);
        $this->assertSame('5.2.2', $reports[0]->statusCode);
        $this->assertSame(BounceCategory::MailboxFull, $reports[0]->category);
    }

    public function testAnUnreachableServerIsItsOwnCategory(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\nAction: failed\nStatus: 4.4.1\n"
        );

        $this->assertSame(BounceCategory::Unreachable, $reports[0]->category);
        $this->assertSame(BounceSeverity::Transient, $reports[0]->severity);
    }

    /**
     * Every shape that cannot be read produces nothing. Each of these has
     * been seen in the wild; none may be allowed to block an address.
     *
     * @return array<string, array{string}>
     */
    public static function unreadableReports(): array
    {
        return [
            'no recipient at all' => ["Action: failed\nStatus: 5.1.1\n"],
            'a recipient that is not an address' => ["Final-Recipient: rfc822; not-an-address\nAction: failed\nStatus: 5.1.1\n"],
            'no status anywhere' => ["Final-Recipient: rfc822; a@b.be\nAction: failed\n"],
            'a success code on a failure' => ["Final-Recipient: rfc822; a@b.be\nAction: failed\nStatus: 2.0.0\n"],
            'a status that is not a code' => ["Final-Recipient: rfc822; a@b.be\nAction: failed\nStatus: permanent failure\n"],
            'an empty body' => [''],
            'prose instead of a report' => ["Votre message n'a pas pu être remis.\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableReports')]
    public function testNothingIsInventedFromAReportThatCannotBeRead(string $body): void
    {
        $this->assertSame(
            [],
            DeliveryStatusReport::parseAll($body),
            'An unreadable bounce is one nobody has diagnosed, never a permanent one.'
        );
    }

    /**
     * `Original-Recipient` is what an alias expansion leaves behind when
     * the final one is missing — rare, but it is the only address in the
     * report when it happens.
     */
    public function testTheOriginalRecipientIsUsedWhenThereIsNoFinalOne(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Original-Recipient: rfc822; parent@exemple.be\nAction: failed\nStatus: 5.1.1\n"
        );

        $this->assertCount(1, $reports);
        $this->assertSame('parent@exemple.be', $reports[0]->recipient);
    }

    public function testAnAddressIsNormalisedForMatching(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; <Parent@Exemple.BE>\nAction: failed\nStatus: 5.1.1\n"
        );

        $this->assertSame('parent@exemple.be', $reports[0]->recipient);
    }

    /**
     * The whole of what a member is shown, and what they are not. The
     * server's own sentence never becomes a property, so nothing
     * downstream can leak it onto a screen or into a log.
     */
    public function testTheServersOwnWordsAreNotCarriedAnywhere(): void
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; parent@exemple.be\n"
            . "Action: failed\n"
            . "Status: 5.1.1\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 <parent@exemple.be> user unknown\n"
        );

        $serialised = print_r($reports[0], true);

        $this->assertStringNotContainsString('user unknown', $serialised);
        $this->assertStringNotContainsString('550', $serialised);
    }
}
