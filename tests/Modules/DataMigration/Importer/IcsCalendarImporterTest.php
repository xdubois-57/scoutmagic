<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Modules\Calendar\Api\SelectableCalendar;
use Modules\DataMigration\Importer\AcceptedSource;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\IcsCalendarImporter;
use Modules\DataMigration\Importer\ImportContext;
use Modules\DataMigration\Importer\ImporterRegistry;
use Modules\DataMigration\Importer\ImportSource;
use Modules\DataMigration\Importer\ItemVerdict;
use Modules\DataMigration\Importer\PreviewNote;
use Modules\DataMigration\Importer\SourceItem;
use Modules\DataMigration\Importer\SourceRejectedException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\FakeCalendarImportWriter;
use Tests\Modules\DataMigration\Support\ScriptedCalendarFetcher;
use Tests\TestTwig;

/**
 * The ICS import's own half: its form (step 1), the source it accepts,
 * the verdict it gives each event, what it writes through the calendar
 * module's contract and the notes of its preview. The shared path around
 * it — analysis, confirmation, slices, re-run — is driven end to end in
 * IcsCalendarImportOnMysqlTest.
 */
final class IcsCalendarImporterTest extends TestCase
{
    private const ACCOUNT = 7;
    private const ADDRESS = 'https://agenda.exemple.test/private-abc/basic.ics';

    private string $workspace;
    private FakeCalendarImportWriter $calendars;
    private ScriptedCalendarFetcher $fetcher;
    private IcsCalendarImporter $importer;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/ics_ws_' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
        $this->calendars = new FakeCalendarImportWriter([self::ACCOUNT => [
            new SelectableCalendar(3, 'Animateurs', false),
            new SelectableCalendar(5, 'Louveteaux', true),
        ]]);
        $this->fetcher = new ScriptedCalendarFetcher([
            self::ADDRESS => ScriptedCalendarFetcher::fixture('valide.ics'),
            'https://agenda.exemple.test/recurrences.ics' => ScriptedCalendarFetcher::fixture('recurrences.ics'),
            'https://agenda.exemple.test/incomplets.ics' => ScriptedCalendarFetcher::fixture('incomplets.ics'),
            'https://agenda.exemple.test/page.html' => ScriptedCalendarFetcher::fixture('illisible.ics'),
        ]);
        $this->importer = new IcsCalendarImporter(
            $this->calendars,
            $this->fetcher,
            clock: static fn (): \DateTimeImmutable
                => new \DateTimeImmutable('2026-11-11 10:00', new \DateTimeZone('Europe/Brussels'))
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workspace);
    }

    public function testItIsAFormSourceImportOfTheCalendarModule(): void
    {
        $registry = new ImporterRegistry(static fn (string $module): bool => $module === 'calendar');
        $registry->add($this->importer);

        $this->assertSame('calendrier', $this->importer->key());
        $this->assertSame('calendar', $this->importer->requiredModule());
        $this->assertNull($this->importer->archiveFormat());
        $this->assertSame($this->importer, $registry->find('calendrier'));

        $off = new ImporterRegistry(static fn (string $module): bool => false);
        $off->add($this->importer);
        $this->assertNull($off->find('calendrier'));
    }

    public function testTheFormOffersTheWritableCalendarsAndTwoYearsBackByDefault(): void
    {
        $context = $this->importer->sourceFormContext(self::ACCOUNT);

        $ids = array_map(static fn (SelectableCalendar $c): int => $c->id, $context['ics_calendars']);
        $this->assertSame([3, 5], $ids);
        $this->assertSame('2024-11-11', $context['ics_default_min_date']);
        $this->assertSame([], $this->importer->sourceFormContext(99)['ics_calendars']);
    }

    /**
     * The template as the mockup draws it: the three fields, the « Copie
     * unique » notice, the recurring-events warning and the button.
     */
    public function testTheFormTemplateCarriesTheMockupsLabels(): void
    {
        $twig = TestTwig::create(['data_migration']);
        $html = $twig->render($this->importer->sourceFormTemplate(), $this->importer->sourceFormContext(self::ACCOUNT));

        foreach ([
            'Copie unique, pas un abonnement.',
            'Adresse du calendrier (ICS)',
            'Calendrier de destination',
            'Seuls les calendriers que vous pouvez modifier sont proposés.',
            'Importer les événements à partir du',
            'Par défaut, deux ans avant aujourd&#039;hui.',
            'Les événements récurrents ne sont pas importés',
            'Lire le calendrier',
            'name="address"',
            'name="calendar"',
            'name="min_date"',
            'value="2024-11-11"',
            '<option value="5">Louveteaux</option>',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // Through the shared form_field partial: its required marker on
        // each of the three fields (design.md §7.9).
        $this->assertSame(3, substr_count($html, '<span class="text-danger" aria-hidden="true">*</span>'));

        $none = $twig->render($this->importer->sourceFormTemplate(), $this->importer->sourceFormContext(99));
        $this->assertStringContainsString('Vous ne pouvez modifier aucun calendrier.', $none);
        $this->assertStringNotContainsString('name="calendar"', $none);
    }

    public function testAnAcceptedSourceKeepsTheEventsButNotTheAddress(): void
    {
        $accepted = $this->accept(self::ADDRESS);

        $this->assertSame(hash('sha256', self::ADDRESS), $accepted->installationId);
        $this->assertSame('ics', $accepted->format);
        $this->assertSame(
            ['calendar_id' => 5, 'calendar_label' => 'Louveteaux', 'min_date' => '2024-11-11'],
            $accepted->parameters
        );
        $this->assertSame('Le calendrier de agenda.exemple.test', $accepted->label);
        $this->assertSame($this->workspace . '/events.jsonl', $accepted->filePath);
        $this->assertCount(8, file((string) $accepted->filePath) ?: []);
        // The raw download is not kept: the converted lines are all the run needs.
        $this->assertSame(['events.jsonl'], array_map('basename', glob($this->workspace . '/*') ?: []));
        $this->assertStringNotContainsString('private-abc', (string) json_encode($accepted->parameters));
    }

    /**
     * webcal:// is what calendar apps show; it is the same calendar over
     * https, and the same source for deduplication.
     */
    public function testAWebcalAddressIsFetchedOverHttpsAndIsTheSameSource(): void
    {
        $accepted = $this->accept('  webcal://agenda.exemple.test/private-abc/basic.ics ');

        $this->assertSame([self::ADDRESS], $this->fetcher->asked);
        $this->assertSame(hash('sha256', self::ADDRESS), $accepted->installationId);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function refusedForms(): array
    {
        $ok = ['address' => self::ADDRESS, 'calendar' => '5', 'min_date' => '2024-11-11'];

        return [
            'no address' => [['address' => ''] + $ok, 'l\'adresse complète du calendrier'],
            'not a web address' => [
                ['address' => 'ftp://agenda.exemple.test/a.ics'] + $ok,
                'l\'adresse complète du calendrier',
            ],
            'no host' => [['address' => 'https://'] + $ok, 'l\'adresse complète du calendrier'],
            'a calendar of somebody else' => [
                ['calendar' => '4'] + $ok,
                'un calendrier de destination que vous pouvez modifier',
            ],
            'no calendar' => [['calendar' => ''] + $ok, 'un calendrier de destination que vous pouvez modifier'],
            'no date' => [['min_date' => ''] + $ok, 'une date valide'],
            'an impossible date' => [['min_date' => '2024-02-30'] + $ok, 'une date valide'],
            'an address that does not answer' => [
                ['address' => 'https://agenda.exemple.test/absent.ics'] + $ok,
                'n\'a pas pu être téléchargé',
            ],
            'a page that is not a calendar' => [
                ['address' => 'https://agenda.exemple.test/page.html'] + $ok,
                'n\'est pas un calendrier ICS lisible',
            ],
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedForms')]
    public function testARefusedSourceSaysWhyInFrench(array $fields, string $why): void
    {
        try {
            $this->importer->acceptSource($fields, $this->workspace, self::ACCOUNT);
            $this->fail('The source was accepted.');
        } catch (SourceRejectedException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
        $this->assertSame([], glob($this->workspace . '/*') ?: [], 'A refused source left a file behind.');
    }

    public function testNothingIsDownloadedBeforeTheFormIsValid(): void
    {
        try {
            $this->importer->acceptSource(
                ['address' => self::ADDRESS, 'calendar' => '4', 'min_date' => '2024-11-11'],
                $this->workspace,
                self::ACCOUNT
            );
        } catch (SourceRejectedException) {
        }

        $this->assertSame([], $this->fetcher->asked);
    }

    public function testEachEventGetsItsVerdict(): void
    {
        $verdicts = $this->verdicts(self::ADDRESS, '2024-11-11');

        // Seven events from 2025 on; the 2020 one is before the minimum date.
        $this->assertSame(array_fill(0, 7, ItemVerdict::IMPORT), array_slice(array_column($verdicts, 'outcome'), 0, 7));
        $this->assertSame(
            [ItemVerdict::IGNORED, 'Antérieur au 11/11/2024 (date minimale)'],
            [$verdicts[7]['outcome'], $verdicts[7]['reason']]
        );
        $this->assertSame(['destination:Louveteaux'], $verdicts[0]['tags']);

        $recurring = $this->verdicts('https://agenda.exemple.test/recurrences.ics', '2024-11-11');
        $this->assertSame(
            array_fill(0, 4, IcsCalendarImporter::REASON_RECURRING),
            array_slice(array_column($recurring, 'reason'), 0, 4)
        );
        $this->assertSame(ItemVerdict::IMPORT, $recurring[4]['outcome']);
    }

    public function testTheMinimumDateIsInclusiveAndMovable(): void
    {
        $verdicts = $this->verdicts(self::ADDRESS, '2025-07-04');

        $this->assertSame(
            [
                ItemVerdict::IGNORED,
                ItemVerdict::IGNORED,
                ...array_fill(0, 5, ItemVerdict::IMPORT),
                ItemVerdict::IGNORED,
            ],
            array_column($verdicts, 'outcome')
        );
    }

    /**
     * An unreadable event is yielded as such — the module counts it as
     * ignored with its reason — and its reference is a day and a UID,
     * never a title, which may name somebody (D8).
     */
    public function testUnreadableEventsAreItemsWithTheirReasonAndNoTitle(): void
    {
        $accepted = $this->accept('https://agenda.exemple.test/incomplets.ics');
        $items = iterator_to_array($this->importer->items(self::source($accepted), 0));

        $this->assertCount(9, $items);
        $this->assertTrue($items[0]->isUnreadable());
        $this->assertSame('Illisible : pas d\'identifiant (UID)', $items[0]->unreadableReason);
        $this->assertSame('01/03/2025 · événement n° 1', $items[0]->shownReference());
        $this->assertSame('Illisible : fuseau horaire inconnu', $items[2]->unreadableReason);
        $this->assertSame('Illisible : UID en double dans le fichier', $items[8]->unreadableReason);
        $this->assertSame('19/04/2025 · double@exemple.test', $items[8]->shownReference());
        $this->assertSame(
            'Événement annulé',
            $this->importer->inspect($items[6], $this->context($accepted))->reason
        );
        $this->assertSame('12/04/2025 · double@exemple.test', $items[7]->shownReference());
        foreach ($items as $item) {
            $this->assertStringNotContainsString('Premier du nom', $item->shownReference());
        }
    }

    public function testItemsResumeAtAGivenPosition(): void
    {
        $accepted = $this->accept(self::ADDRESS);
        $items = iterator_to_array($this->importer->items(self::source($accepted), 6));

        $this->assertSame([6, 7], array_keys($items));
        $this->assertSame('event', $items[6]->kind);
        $this->assertSame('sha256:' . hash('sha256', 'hike-2026-04-10@exemple.test'), $items[6]->sourceId);
    }

    public function testAnAccountThatLostTheCalendarImportsNothingMore(): void
    {
        $accepted = $this->accept(self::ADDRESS);
        $item = iterator_to_array($this->importer->items(self::source($accepted), 0))[0];
        $this->calendars->writable[self::ACCOUNT] = [new SelectableCalendar(3, 'Animateurs', false)];

        $verdict = $this->importer->inspect($item, $this->context($accepted));

        $this->assertSame(
            [ItemVerdict::IGNORED, 'Calendrier de destination non modifiable'],
            [$verdict->outcome, $verdict->reason]
        );
    }

    public function testWriteHandsTheConvertedEventToTheCalendarModule(): void
    {
        $accepted = $this->accept(self::ADDRESS);
        $items = iterator_to_array($this->importer->items(self::source($accepted), 0));

        $target = $this->importer->write($items[1], $this->context($accepted));
        $this->importer->write($items[4], $this->context($accepted));

        $this->assertSame('calendar_event', $target->type);
        $this->assertSame('1001', $target->id);
        [$utc, $camp] = $this->calendars->created;
        $this->assertSame([self::ACCOUNT, 5], [$utc['account'], $utc['calendar']]);
        $this->assertSame(
            ['Conseil d\'unité (heure UTC)', '2025-06-20', null, '20:00', '22:00', null, null],
            [
                $utc['event']->title,
                $utc['event']->startDate,
                $utc['event']->endDate,
                $utc['event']->startTime,
                $utc['event']->endTime,
                $utc['event']->location,
                $utc['event']->description,
            ]
        );
        $this->assertSame(
            ['2025-07-20', '2025-07-30', null, null],
            [$camp['event']->startDate, $camp['event']->endDate, $camp['event']->startTime, $camp['event']->endTime]
        );
    }

    public function testAVeryLongUidIsStillOneStableKey(): void
    {
        $uid = str_repeat('u', 300);

        $this->assertSame(IcsCalendarImporter::sourceId($uid), IcsCalendarImporter::sourceId($uid));
        $this->assertSame('sha256:' . hash('sha256', $uid), IcsCalendarImporter::sourceId($uid));
        $this->assertLessThanOrEqual(SourceItem::MAX_ID_BYTES, strlen(IcsCalendarImporter::sourceId($uid)));
    }

    /**
     * The correspondence is kept without limit and holds no personal data
     * (§49.4), and a UID may be an address: even a short one is hashed.
     */
    public function testAShortUidIsHashedToo(): void
    {
        $this->assertSame(
            'sha256:' . hash('sha256', 'alice@exemple.test'),
            IcsCalendarImporter::sourceId('alice@exemple.test')
        );
    }

    /**
     * The preview tells the five outcomes apart: created (into which
     * calendar), present (the shared tile), before the minimum date,
     * recurring and unreadable.
     */
    public function testThePreviewNotesNameEachOutcome(): void
    {
        $notes = $this->importer->previewNotes(new AnalysisSummary(
            420,
            288,
            18,
            0,
            [
                'Antérieur au 11/11/2024 (date minimale)' => 61,
                IcsCalendarImporter::REASON_RECURRING => 37,
                'Illisible : pas de titre' => 5,
                'Illisible : fuseau horaire inconnu' => 3,
            ],
            ['destination:Animateurs' => 288],
            []
        ));
        $texts = array_map(static fn (PreviewNote $n): string => $n->level . ' ' . $n->text, $notes);

        $this->assertSame([
            'info 288 événements à importer dans le calendrier « Animateurs ».',
            'info Les heures sont converties à l\'heure de Bruxelles. '
                . 'Les événements sans heure deviennent « toute la journée ».',
            'info 61 événements antérieurs à la date minimale : non importés.',
            'warning 37 événements récurrents ou occurrences modifiées : exclus. '
                . 'Recréez-les dans ScoutMagic si besoin.',
            'warning 8 événements illisibles ou incomplets : ignorés.',
        ], $texts);
    }

    public function testTheEffectsSayItIsACopyWithoutNotification(): void
    {
        $effects = implode(' ', $this->importer->effects());

        $this->assertStringContainsString('ce n\'est pas un abonnement', $effects);
        $this->assertStringContainsString('Aucune notification, aucun rappel pour les événements passés.', $effects);
        $this->assertStringContainsString('l\'import ne recrée pas ces événements', $effects);
    }

    private function accept(string $address, string $minDate = '2024-11-11'): AcceptedSource
    {
        return $this->importer->acceptSource(
            ['address' => $address, 'calendar' => '5', 'min_date' => $minDate, '_csrf_token' => 'x'],
            $this->workspace,
            self::ACCOUNT
        );
    }

    /**
     * @return list<array{outcome: string, reason: ?string, tags: list<string>}>
     */
    private function verdicts(string $address, string $minDate): array
    {
        foreach (glob($this->workspace . '/*') ?: [] as $file) {
            unlink($file);
        }
        $accepted = $this->accept($address, $minDate);
        $context = $this->context($accepted);
        $verdicts = [];
        foreach ($this->importer->items(self::source($accepted), 0) as $item) {
            $verdict = $this->importer->inspect($item, $context);
            $verdicts[] = ['outcome' => $verdict->outcome, 'reason' => $verdict->reason, 'tags' => $verdict->tags];
        }

        return $verdicts;
    }

    private static function source(AcceptedSource $accepted): ImportSource
    {
        return new ImportSource(null, null, $accepted->parameters, $accepted->filePath);
    }

    private function context(AcceptedSource $accepted): ImportContext
    {
        return new ImportContext(
            1,
            $accepted->installationId,
            $accepted->format,
            self::ACCOUNT,
            self::source($accepted),
            static fn (string $label): ?int => null
        );
    }
}
