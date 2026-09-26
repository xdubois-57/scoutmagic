<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Mail\Feedback\Seed\Task\PurgeSeedCopiesHandler;
use Core\Mail\Feedback\Seed\Task\ResolveMailboxProvidersHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the site tells a family about the copies of a mailing that land in
 * third-party mailboxes (roadmap IT-07).
 *
 * AGENTS.md § RGPD page maintenance asks two different things of this
 * iteration, and the second was missed until a reviewer said so: a new
 * external integration reaches the « Sous-traitants » section, and
 * **changed retention logic reaches the « Durée de conservation »
 * section**. Adding `GIVE_UP_AFTER_DAYS` and `RETENTION_DAYS` is changed
 * retention logic, and §3.1 lists every other window the site holds —
 * being absent from a list like that reads as « nothing is kept », which
 * is the one thing it does not mean.
 *
 * **Deliberately about the words, and about the numbers.** A promise a
 * family reads is not enforced by a unit test of a service; it is
 * enforced by the sentence being there and being true. What is mechanical
 * is the sentence deleted in a refactor — and the window quietly changed
 * in the handler while the notice goes on stating the old one, which is
 * the worse of the two because the document still reads perfectly.
 */
final class SeedMailboxesRgpdCoverageTest extends TestCase
{
    private const NOTICE = 'core/View/rgpd_default.html';
    private const PROMPT = 'core/View/RgpdContentService.php';

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /**
     * The facts of this processing, each in both documents.
     *
     * @return array<string, array{list<string>}>
     */
    public static function factProvider(): array
    {
        return [
            'the copy is the real message, so it carries the same data' => [['boîtes témoins']],
            'the providers hosting those boxes are processors' => [['sous-traitant']],
            'the copies only go to the unit\'s own boxes' => [['appartenant à l\'unité']],
            'the site deletes the copy after reading its folder' => [['efface', 'dossier']],
            'and the option starts off' => [['désactivé']],
        ];
    }

    /**
     * @param list<string> $needles
     */
    #[DataProvider('factProvider')]
    public function testBothDocumentsCarryTheFact(array $needles): void
    {
        foreach ([self::NOTICE, self::PROMPT] as $path) {
            $content = self::read($path);
            foreach ($needles as $needle) {
                // Case-insensitively: the prompt shouts some of these at
                // the model in capitals, and which half is emphasised is
                // not the fact being pinned.
                $this->assertStringContainsStringIgnoringCase(
                    $needle,
                    $content,
                    $path . ' no longer says this about the seed mailboxes, and a family reading it would be '
                    . 'told less than what actually happens to their data.'
                );
            }
        }
    }

    /**
     * **The retention section names both windows, in the handler's own
     * numbers.**
     *
     * Read off the constants rather than written down here, because the
     * failure this exists for is not the section disappearing — somebody
     * would notice that — but the window being raised to a hundred and
     * eighty days in the handler while §3.1 goes on promising ninety. The
     * document still reads perfectly, and it is untrue.
     */
    public function testTheRetentionSectionStatesTheWindowsTheHandlerActuallyApplies(): void
    {
        $notice = self::read(self::NOTICE);

        $this->assertStringContainsString(
            'Résultats des boîtes témoins',
            $notice,
            'AGENTS.md: « When changing data retention logic → update the "Durée de conservation" section ». '
            . 'A window absent from a list of every other window reads as « nothing is kept ».'
        );
        $this->assertMatchesRegularExpression(
            '/Résultats des boîtes témoins.{0,2000}?<strong>' . PurgeSeedCopiesHandler::GIVE_UP_AFTER_DAYS
                . ' jours<\/strong>/su',
            $notice,
            'The notice no longer states how long a copy may go unfound before it is given up on.'
        );
        $this->assertMatchesRegularExpression(
            '/Résultats des boîtes témoins.{0,2000}?<strong>' . PurgeSeedCopiesHandler::RETENTION_DAYS
                . ' jours<\/strong>/su',
            $notice,
            'The notice states a retention window the handler no longer applies.'
        );
    }

    /**
     * And it says what is left once the message is gone: a provider, a
     * folder, a date — never an address. That is the sentence that makes
     * ninety days of retention unremarkable, and losing it would leave a
     * reader assuming the kept record is the mailing itself.
     */
    public function testTheNoticeSaysWhatSurvivesCarriesNoAddress(): void
    {
        $notice = self::read(self::NOTICE);

        $this->assertMatchesRegularExpression(
            '/Résultats des boîtes témoins.{0,2000}?aucune adresse/su',
            $notice
        );
    }

    /**
     * **The routing is not a processor, and saying otherwise would be a
     * different kind of error** — an over-disclosure that sends a reader
     * looking for a third party who does not exist. It only names which
     * already-declared relay is tried first.
     */
    public function testBothDocumentsDenyThatRoutingAddsAProcessor(): void
    {
        foreach ([self::NOTICE, self::PROMPT] as $path) {
            $this->assertStringContainsStringIgnoringCase(
                'aucun nouveau sous-traitant',
                self::read($path),
                $path . ': routing by provider must stay described as introducing no processor.'
            );
        }
    }

    /**
     * **The MX reading is an outbound flow, and both documents say so**
     * (issue #422, AGENTS.md § RGPD — a new outbound flow is a
     * documentation change). What leaves is a domain name, never an
     * address, and what stays is the domain → provider pairing.
     */
    public function testBothDocumentsDescribeTheMxReading(): void
    {
        foreach ([self::NOTICE, self::PROMPT] as $path) {
            $content = self::read($path);
            foreach (['enregistrements MX', 'seul le nom de domaine est interrogé, jamais l\'adresse'] as $needle) {
                $this->assertStringContainsStringIgnoringCase(
                    $needle,
                    $content,
                    $path . ' no longer describes the DNS reading of recipient domains.'
                );
            }
        }
    }

    /** And its retention, in the task's own numbers. */
    public function testTheRetentionSectionStatesTheMxWindowsTheTaskApplies(): void
    {
        $notice = self::read(self::NOTICE);

        $this->assertMatchesRegularExpression(
            '/Domaines destinataires rattachés à un fournisseur.{0,600}?tous les '
                . ResolveMailboxProvidersHandler::TTL_DAYS . ' jours.{0,300}?<strong>'
                . ResolveMailboxProvidersHandler::RETENTION_DAYS . ' jours<\/strong>.{0,300}?aucune adresse/su',
            $notice,
            'The notice states an MX refresh or retention window the task no longer applies.'
        );
    }
}
