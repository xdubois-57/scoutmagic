<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Mail\MailIdentity;
use Core\Mail\SectionSenderAlignment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What the configuration screens are told about a section this site cannot
 * sign for (issue #418).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionSenderAlignmentTest extends TestCase
{
    private \PDO $pdo;
    private SectionService $sections;
    private int $branchId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->sections = new SectionService(
            new SectionRepository(Connection::withPdo($this->pdo)),
            new MemberProfileRepository(
                Connection::withPdo($this->pdo),
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                new MemberBadgeRepository($this->pdo)
            )
        );

        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $this->branchId = (int) $this->pdo->lastInsertId();

        // The rows themselves, because `setInternal()` updates and never
        // creates: the SQLite schema this helper builds carries no seeded
        // settings.
        $this->declareSetting(MailIdentity::SETTING_FROM_ADDRESS);
        $this->declareSetting(MailIdentity::SETTING_FROM_NAME);
    }

    public function testAnAddressOnTheSitesOwnDomainIsNotWarnedAbout(): void
    {
        $alignment = $this->alignmentFor('info@unite.be', 'Unité Exemple');

        $this->assertNull($alignment->warningFor('baladins@unite.be', 'Baladins'));
        $this->assertNull(
            $alignment->warningFor('baladins@bal.unite.be', 'Baladins'),
            'a subdomain is signable, so there is nothing to say about it'
        );
    }

    /**
     * **The sentence has to carry all four facts**, because each answers a
     * different question the operator is about to ask: which domain the site
     * signs for, what address the mailing will actually leave from, what
     * name the recipient will read, and where a reply lands. A warning that
     * only said « pas aligné » would send them to read the code.
     */
    public function testAnAddressElsewhereSaysWhatTheMailingWillDoInstead(): void
    {
        $warning = $this->alignmentFor('info@unite.be', 'Unité Exemple')
            ->warningFor('baladins@telenet.be', 'Baladins');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('unite.be', $warning, 'the domain the site signs for');
        $this->assertStringContainsString('info@unite.be', $warning, 'the address the mailing leaves from');
        $this->assertStringContainsString('Baladins (Unité Exemple)', $warning, 'the name the recipient reads');
        $this->assertStringContainsString('réponses arriveront', $warning, 'where « Répondre » goes');
    }

    /**
     * The name in the warning is the one the send will really use, not one
     * this class composed: {@see MailIdentity::substitutedFromName()} is
     * asked, so the two cannot drift apart.
     */
    public function testTheNameInTheWarningIsTheOneTheSendWillUse(): void
    {
        $identity = new MailIdentity('info@unite.be', 'Unité Exemple');
        $warning = $this->alignmentFor('info@unite.be', 'Unité Exemple')
            ->warningFor('baladins@telenet.be', 'Baladins');

        $this->assertNotNull($warning);
        $this->assertStringContainsString(
            (string) $identity->substitutedFromName('Baladins'),
            $warning
        );
    }

    /**
     * A section with no address at all has nothing to warn about: its
     * mailing already goes out as the site, and always did — before this
     * rule existed too. Warning here would report a change that is not one.
     */
    public function testASectionWithNoAddressIsNotWarnedAbout(): void
    {
        $alignment = $this->alignmentFor('info@unite.be', 'Unité Exemple');

        $this->assertNull($alignment->warningFor(null, 'Baladins'));
        $this->assertNull($alignment->warningFor('', 'Baladins'));
        $this->assertNull($alignment->warningFor('   ', 'Baladins'));
    }

    /**
     * **A site that sends from nowhere blames nobody.** `canAlignFrom()`
     * answers « no » for every address when no sending address is
     * configured, which would put this warning under all of them — while
     * the real problem is the missing address, which the outbound screens
     * say in their own words. This one would be blaming the sections for it.
     */
    public function testASiteWithNoSendingAddressWarnsAboutNothing(): void
    {
        $alignment = $this->alignmentFor('', '');
        $this->createSection('BAL1', 'Baladins', 'baladins@telenet.be');

        $this->assertNull($alignment->warningFor('baladins@telenet.be', 'Baladins'));
        $this->assertSame([], $alignment->misaligned());
        $this->assertSame('', $alignment->siteDomain());
    }

    public function testTheListNamesEverySectionTheSiteCannotSignFor(): void
    {
        $this->createSection('BAL1', 'Baladins', 'baladins@telenet.be');
        $this->createSection('LOU1', 'Louveteaux', 'louveteaux@unite.be');
        $this->createSection('ECL1', 'Éclaireurs', null);
        $this->createSection('PIO1', 'Pionniers', 'pionniers@yahoo.fr');

        $rows = $this->alignmentFor('info@unite.be', 'Unité Exemple')->misaligned();

        $this->assertSame(
            ['Baladins', 'Pionniers'],
            array_column($rows, 'name'),
            'the signable one and the one with no address are both absent'
        );
        $this->assertSame('baladins@telenet.be', $rows[0]['email']);
        $this->assertSame('Baladins (Unité Exemple)', $rows[0]['substituted_name']);
        $this->assertSame('BAL1', $rows[0]['desk_code']);
        $this->assertNotSame('', $rows[0]['warning']);
    }

    /**
     * A hidden section still has an address and still sends mailings, so
     * leaving it out would hide the one screen that could explain a refusal.
     */
    public function testAHiddenSectionIsStillListed(): void
    {
        $id = $this->createSection('BAL1', 'Baladins', 'baladins@telenet.be');
        $this->pdo->prepare('UPDATE sections SET is_visible = 0 WHERE id = ?')->execute([$id]);

        $rows = $this->alignmentFor('info@unite.be', 'Unité Exemple')->misaligned();

        $this->assertSame(['Baladins'], array_column($rows, 'name'));
    }

    /**
     * **The reading follows the setting, within one request too.** An
     * operator who changes the site's sending address and comes back to
     * either screen has to be told about the NEW domain; a `MailIdentity`
     * kept in the constructor would answer for the address it was built
     * with.
     */
    public function testChangingTheSitesAddressChangesTheAnswerWithinTheRequest(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->setInternal(MailIdentity::SETTING_FROM_ADDRESS, 'info@unite.be');
        $alignment = new SectionSenderAlignment($settings, $this->sections);

        $this->assertNull($alignment->warningFor('baladins@unite.be', 'Baladins'));

        // The save the outbound screen performs, on the very service this
        // object reads through.
        $settings->setInternal(MailIdentity::SETTING_FROM_ADDRESS, 'info@autre.be');

        $this->assertNotNull(
            $alignment->warningFor('baladins@unite.be', 'Baladins'),
            'the address that was signable a moment ago no longer is'
        );
        $this->assertSame('autre.be', $alignment->siteDomain());
    }

    private function alignmentFor(string $address, string $name): SectionSenderAlignment
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->setInternal(MailIdentity::SETTING_FROM_ADDRESS, $address);
        $settings->setInternal(MailIdentity::SETTING_FROM_NAME, $name);

        return new SectionSenderAlignment($settings, $this->sections);
    }

    private function declareSetting(string $key): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description, editable)
             VALUES (NULL, ?, \'\', \'text\', ?, ?, 0)'
        )->execute([$key, $key, $key]);
    }

    private function createSection(string $deskCode, string $name, ?string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$deskCode, $this->branchId, $name, $email]);

        return (int) $this->pdo->lastInsertId();
    }
}
