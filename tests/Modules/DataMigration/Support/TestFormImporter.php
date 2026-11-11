<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Modules\DataMigration\Importer\AcceptedSource;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\ArchiveFormat;
use Modules\DataMigration\Importer\FormSourceImporter;
use Modules\DataMigration\Importer\ImportContext;
use Modules\DataMigration\Importer\ImportSource;
use Modules\DataMigration\Importer\ItemVerdict;
use Modules\DataMigration\Importer\SourceItem;
use Modules\DataMigration\Importer\SourceRejectedException;
use Modules\DataMigration\Importer\WrittenTarget;

/**
 * An import whose step 1 is a form, the shape the ICS calendar (lot B)
 * takes: an « adresse » field, and as many items as its `nombre` says. It
 * writes the fetched « source » into its workspace, as an ICS import would
 * write the calendar it downloaded.
 */
final class TestFormImporter implements FormSourceImporter
{
    public const TEMPLATE = '@data_migration_test/source.html.twig';

    public function key(): string
    {
        return 'formulaire';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function title(): string
    {
        return 'Import par formulaire';
    }

    public function description(): string
    {
        return 'Un import d\'essai dont la source est un formulaire.';
    }

    public function unitLabel(): string
    {
        return 'événements';
    }

    public function archiveFormat(): ?ArchiveFormat
    {
        return null;
    }

    public function sourceFormTemplate(): string
    {
        return self::TEMPLATE;
    }

    public function sourceFormContext(int $userAccountId): array
    {
        return ['default_address' => 'https://exemple.test/agenda.ics'];
    }

    public function acceptSource(array $fields, string $workspace, int $userAccountId): AcceptedSource
    {
        $address = (string) ($fields['adresse'] ?? '');
        if (!str_starts_with($address, 'https://')) {
            throw new SourceRejectedException('L\'adresse doit commencer par https://.');
        }
        $file = $workspace . '/source.txt';
        file_put_contents($file, str_repeat("x\n", max(0, (int) ($fields['nombre'] ?? 0))));

        return new AcceptedSource(hash('sha256', $address), 'test-form', ['address' => $address], $file, 'agenda.ics');
    }

    public function items(ImportSource $source, int $from): iterable
    {
        $count = substr_count((string) file_get_contents((string) $source->filePath), "\n");
        for ($i = $from; $i < $count; $i++) {
            yield $i => new SourceItem('event', 'evt-' . $i);
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        return ItemVerdict::import();
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        return new WrittenTarget('test_event', $item->sourceId);
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        return [];
    }

    public function effects(): array
    {
        return ['Copie unique, pas un abonnement.'];
    }
}
