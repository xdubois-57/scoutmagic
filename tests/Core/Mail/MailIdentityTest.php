<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use Core\Mail\MailIdentity;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * The four roles one address plays, and the one that decides the SPF
 * domain (roadmap IT-03).
 */
class MailIdentityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mailidentity_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testOneAddressAnswersAllFourRoles(): void
    {
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple');

        $this->assertSame('info@unite.be', $identity->fromAddress);
        $this->assertSame('info@unite.be', $identity->replyAddress());
        $this->assertSame('info@unite.be', $identity->bounceAddress());
        $this->assertSame('info@unite.be', $identity->dmarcReportAddress());
    }

    public function testAConfiguredReplyAddressReplacesTheFallbackWithoutTouchingTheEnvelope(): void
    {
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple', 'secretariat@unite.be');

        $this->assertSame('secretariat@unite.be', $identity->replyAddress());
        $this->assertSame('info@unite.be', $identity->bounceAddress());
        $this->assertSame('info@unite.be', $identity->envelopeSender());
    }

    /**
     * The trap the roadmap names: SPF is evaluated on the envelope
     * sender's domain. A reply address or a DMARC report address pointing
     * at another provider must not move it.
     */
    public function testTheSpfDomainFollowsTheEnvelopeSenderAndNothingElse(): void
    {
        $identity = new MailIdentity(
            'info@unite.be',
            'Unité Exemple',
            'reponses@gmail.com',
            'dmarc@rapports.example.net'
        );

        $this->assertSame('unite.be', $identity->spfDomain());
        $this->assertSame('unite.be', $identity->dkimDomain());
    }

    /**
     * The two domains are equal by construction — the envelope sender IS
     * the From address — and this says so rather than leaving it implied.
     *
     * There used to be an `isAligned()` here and a « ces deux domaines
     * diffèrent » warning on the screen fed by it. Both were unreachable:
     * `spfDomain()` and `dkimDomain()` are the same expression, so the
     * method really answered « an address is configured » under a name
     * that promised something else, and the support package reported
     * `alignés : oui` on every installation that ever existed. An alarm
     * that cannot ring reads as a check somebody is doing.
     */
    public function testTheTwoDomainsCannotDivergeWhileTheIdentityComesFromTheSiteSettings(): void
    {
        foreach (['info@unite.be', 'INFO@Unite.BE', 'contact@autre.example', ''] as $address) {
            $identity = new MailIdentity($address, 'Unité', 'reponses@gmail.com', 'dmarc@ailleurs.example');

            $this->assertSame(
                $identity->spfDomain(),
                $identity->dkimDomain(),
                'The envelope sender is the From address, so neither a reply nor a DMARC address moves either one.'
            );
        }
    }

    public function testTheDomainIsLowercasedAndAnAddressWithoutOneAnswersEmpty(): void
    {
        $this->assertSame('unite.be', MailIdentity::domainOf('Info@Unite.BE'));
        $this->assertSame('', MailIdentity::domainOf('info'));
        $this->assertSame('', MailIdentity::domainOf(''));
        // A quoted local part may itself contain an @ — the last one wins.
        $this->assertSame('unite.be', MailIdentity::domainOf('"a@b"@unite.be'));
    }

    /**
     * The pin that keeps {@see MailIdentity::envelopeSender()} honest: it
     * claims to be the address `MailService` puts in the envelope, and
     * the only way to know is to look at what the transport receives —
     * with a From override in play, which is the case where the two
     * addresses differ.
     */
    public function testTheEnvelopeSenderIsTheAddressMailServiceActuallyUses(): void
    {
        $transport = $this->recordingTransport();
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple');

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            fromAddressOverride: 'section@autre.be',
            fromNameOverride: 'Section'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame($identity->envelopeSender(), $mail->Sender);
        $this->assertSame($identity->spfDomain(), MailIdentity::domainOf($mail->Sender));
    }

    public function testTheConfiguredReplyAddressIsUsedWhenTheCallerNamesNone(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport, 'secretariat@unite.be')->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame(
            [['secretariat@unite.be', '']],
            array_values($mail->getReplyToAddresses())
        );
    }

    public function testACallerThatNamesItsOwnReplyAddressKeepsIt(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport, 'secretariat@unite.be')->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            replyTo: 'loc-2027-0001@unite.be'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame(
            [['loc-2027-0001@unite.be', '']],
            array_values($mail->getReplyToAddresses())
        );
    }

    /**
     * A mailing sent « au nom de » a section carries that section's
     * address as its From and, before this setting existed, no
     * `Reply-To:` — so « Répondre » reached the section. The site-wide
     * reply address answers for the site's own From and must not divert
     * a section's replies to it, which nothing in `mass_mail` would have
     * said out loud.
     */
    public function testTheSiteReplyAddressNeverOverridesAFromSentOnSomebodyElsesBehalf(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport, 'secretariat@unite.be')->send(
            to: 'parent@exemple.be',
            subject: 'La newsletter',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            fromAddressOverride: 'louveteaux@unite.be',
            fromNameOverride: 'Les Louveteaux'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame('louveteaux@unite.be', $mail->From);
        $this->assertSame([], $mail->getReplyToAddresses(), 'A reply must go back to the section that wrote.');
    }

    /**
     * And a caller that names its own reply address keeps it, override or
     * not — that is `inbound_mail` routing a reply onto a booking.
     */
    public function testAnExplicitReplyAddressSurvivesAFromOverride(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport, 'secretariat@unite.be')->send(
            to: 'parent@exemple.be',
            subject: 'La newsletter',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            replyTo: 'loc-2027-0001@unite.be',
            fromAddressOverride: 'louveteaux@unite.be'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame(
            [['loc-2027-0001@unite.be', '']],
            array_values($mail->getReplyToAddresses())
        );
    }

    public function testWithoutAConfiguredReplyAddressNoReplyToHeaderIsAdded(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame([], $mail->getReplyToAddresses());
    }

    /**
     * The row says where the site ASKS for reports. Without an address it
     * asks for none — no `rua=` is proposed and nothing is ever sent — so
     * showing the expédition address there would put an address next to a
     * sentence saying nothing is collected, and leave the reader to
     * choose which half to believe.
     */
    public function testWithoutADmarcAddressThatRowIsEmptyRatherThanTheExpeditionAddress(): void
    {
        $roles = array_column((new MailIdentity('info@unite.be', 'Unité'))->roles(), null, 'role');

        $this->assertSame('', $roles[MailIdentity::ROLE_DMARC]['address']);
        $this->assertSame('Aucun rapport demandé', $roles[MailIdentity::ROLE_DMARC]['source']);
        // The fallback itself stays — it answers « if reports were asked
        // for, where would they go », which is what builds the suggested
        // record the day somebody asks for them.
        $this->assertSame('info@unite.be', (new MailIdentity('info@unite.be'))->dmarcReportAddress());
    }

    public function testTheFourRolesTableNamesWhereEachAddressComesFrom(): void
    {
        $roles = (new MailIdentity('info@unite.be', 'Unité', '', 'dmarc@unite.be'))->roles();

        $this->assertCount(4, $roles);
        $byRole = array_column($roles, null, 'role');

        // Every row is a table header cell: one silently missing was a
        // real edit, caught by PHPStan's shape and not by this test.
        foreach ($byRole as $role => $row) {
            $this->assertNotSame('', $row['label'], "The role « {$role} » has no label.");
        }

        $this->assertSame('info@unite.be', $byRole[MailIdentity::ROLE_FROM]['address']);
        $this->assertSame('info@unite.be', $byRole[MailIdentity::ROLE_REPLY]['address']);
        $this->assertSame('Reprend l\'adresse d\'expédition', $byRole[MailIdentity::ROLE_REPLY]['source']);
        $this->assertSame('dmarc@unite.be', $byRole[MailIdentity::ROLE_DMARC]['address']);
        $this->assertSame('Adresse renseignée', $byRole[MailIdentity::ROLE_DMARC]['source']);
        // The line the whole table exists for.
        $this->assertStringContainsString('SPF', $byRole[MailIdentity::ROLE_BOUNCE]['explanation']);
    }

    private function recordingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public ?PHPMailer $received = null;

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->received = $mail;
            }
        };
    }

    // ── Can this site sign for that From:? (issue #418) ───────────────

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function fromAddresses(): iterable
    {
        yield 'the site\'s own domain' => ['baladins@unite.be', true];
        yield 'a subdomain of it' => ['baladins@baladins.unite.be', true];
        yield 'a subdomain two levels down' => ['x@a.b.unite.be', true];
        yield 'the same domain shouted' => ['Baladins@UNITE.BE', true];
        // **The two traps, and they are the reason the check is not a
        // substring search.** Both carry the site's domain inside them and
        // neither is under it; one is a look-alike somebody can register.
        yield 'a look-alike registered elsewhere' => ['x@unite.be.evil.com', false];
        yield 'a neighbour that merely ends the same way' => ['x@autre-unite.be', false];
        yield 'a consumer provider' => ['baladins@telenet.be', false];
        yield 'the parent domain, deliberately refused' => ['x@be', false];
        yield 'no domain at all' => ['baladins', false];
        yield 'nothing' => ['', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fromAddresses')]
    public function testWhetherTheSiteCanAlignDmarcForAnAddress(string $address, bool $expected): void
    {
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple');

        $this->assertSame($expected, $identity->canAlignFrom($address));
    }

    /**
     * A site whose sending address is on a subdomain signs for that
     * subdomain and nothing above it — the asymmetry the docblock owns.
     */
    public function testASiteSendingFromASubdomainCannotSignForItsParent(): void
    {
        $identity = new MailIdentity('noreply@mail.unite.be', 'Unité Exemple');

        $this->assertTrue($identity->canAlignFrom('x@mail.unite.be'));
        $this->assertTrue($identity->canAlignFrom('x@deep.mail.unite.be'));
        $this->assertFalse($identity->canAlignFrom('baladins@unite.be'));
    }

    /**
     * An installation with no sending address configured signs for
     * nothing, so it can align nothing — never « everything ».
     */
    public function testASiteWithNoSendingAddressCanAlignNothing(): void
    {
        $identity = new MailIdentity('', '');

        $this->assertFalse($identity->canAlignFrom('baladins@unite.be'));
        $this->assertFalse($identity->canAlignFrom(''));
    }

    // ── The name a substituted From: carries (issue #418) ─────────────

    /**
     * **The name the recipient of a substituted mailing reads.** It lives on
     * `MailIdentity` rather than in the mailing because the send and the two
     * configuration screens all have to say the same thing — a warning that
     * promised « Baladins (Unité Exemple) » while the message went out as
     * something else would be worse than no warning.
     */
    public function testASubstitutedFromCarriesTheSectionAndTheUnit(): void
    {
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple');

        $this->assertSame('Baladins (Unité Exemple)', $identity->substitutedFromName('Baladins'));
    }

    /**
     * The unit's part is dropped rather than rendered empty: « Baladins () »
     * reads as a bug to the one person the name was meant to reassure.
     */
    public function testASiteWithNoSendingNameLeavesTheSectionAlone(): void
    {
        $identity = new MailIdentity('info@unite.be', '');

        $this->assertSame('Baladins', $identity->substitutedFromName('Baladins'));
    }

    /**
     * And a section with no name of its own leaves the site's, which is what
     * `MailService` would have used had nothing been substituted at all —
     * never the empty string, which would blank the display name.
     */
    public function testASectionWithoutANameLeavesTheSites(): void
    {
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple');

        $this->assertSame('Unité Exemple', $identity->substitutedFromName(null));
        $this->assertSame('Unité Exemple', $identity->substitutedFromName('   '));
        $this->assertNull((new MailIdentity('info@unite.be', ''))->substitutedFromName(null));
    }

    /**
     * **Both halves are trimmed, and the site's half is the one that needs
     * it.** This object is also built straight from
     * `MailService::getDefaultSender()`, which hands back what is stored
     * without tidying it, where `fromSettings()` trims — so one of the two
     * callers would otherwise produce « Baladins ( Unité Exemple ) ».
     */
    public function testNeitherHalfKeepsItsSurroundingSpace(): void
    {
        $identity = new MailIdentity('info@unite.be', '  Unité Exemple  ');

        $this->assertSame('Baladins (Unité Exemple)', $identity->substitutedFromName('  Baladins  '));
    }

    private function serviceWith(MailTransportInterface $transport, string $replyAddress = ''): MailService
    {
        return new MailService(
            mode: 'local',
            fromAddress: 'info@unite.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            replyAddress: $replyAddress,
            transport: $transport
        );
    }
}
