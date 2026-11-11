<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Api\ImportedAttachment;
use Modules\MassMail\Api\ImportedEmail;
use Modules\MassMail\Api\ImportedRecipient;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\ImportedEmailRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\EmailHistoryImportService;

/**
 * An email of a former site, written the way the data_migration import
 * writes it — through mass_mail's own Api\EmailHistoryImportInterface and
 * nothing of data_migration, which is exactly the state of a site whose
 * migration module has since been disabled (decision D11).
 */
final class ImportedEmailFixture
{
    public const ENCRYPTION_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const BLIND_INDEX_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function encryption(): EncryptionService
    {
        return new EncryptionService(self::ENCRYPTION_KEY, self::BLIND_INDEX_KEY);
    }

    public static function service(\PDO $pdo, string $storagePath): EmailHistoryImportService
    {
        $encryption = self::encryption();

        return new EmailHistoryImportService(
            new ImportedEmailRepository($pdo, $encryption),
            new DeliveryRepository($pdo, $encryption),
            new TargetRepository($pdo),
            new EmailAttachmentRepository($pdo),
            new MemberResolutionRepository($pdo, $encryption),
            new UploadHandler(new FileRepository($pdo), $storagePath),
            new HtmlSanitizer(),
            new JournalService(new JournalRepository($pdo))
        );
    }

    /**
     * The Staff d'U section an email falls back to (D6), created if absent.
     */
    public static function staffSection(\PDO $pdo): int
    {
        $id = $pdo->query("SELECT id FROM sections WHERE desk_code = 'STAFFDU'")->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $branch = $pdo->query('SELECT id FROM age_branches ORDER BY id LIMIT 1')->fetchColumn();
        if ($branch === false) {
            $pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('STAFF', 'Staff d''U', 50)");
            $branch = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('STAFFDU', ?, 'Staff d''U')")
            ->execute([(int) $branch]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<ImportedRecipient>|null $recipients null: one accepted, one unknown, one failed
     * @param list<int> $memberIds
     * @param list<ImportedAttachment> $attachments
     */
    public static function email(
        string $sourceId = 'c-2024-001',
        ?array $recipients = null,
        array $memberIds = [],
        array $attachments = [],
        string $sender = 'eclaireurs@exemple.test'
    ): ImportedEmail {
        return new ImportedEmail(
            "site-exempleville\n" . $sourceId,
            "Infos camp d'été 2024",
            '<p>Chers parents,</p><script>alert(1)</script><p>Le camp aura lieu en juillet.</p>',
            new \DateTimeImmutable('2024-05-21T18:30:00+02:00'),
            $sender,
            'Éclaireurs 99e Exempleville',
            'Parents des Éclaireurs',
            $recipients ?? [
                new ImportedRecipient('famille.alpha@exemple.test', ImportedRecipient::ACCEPTED),
                new ImportedRecipient('famille.beta@exemple.test', ImportedRecipient::UNKNOWN),
                new ImportedRecipient('ancien.chef@exemple.test', ImportedRecipient::FAILED),
            ],
            $memberIds,
            $attachments,
            null
        );
    }

    /** A real one-page PDF in a temporary file, the caller's to remove. */
    public static function pdf(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mm_import_');
        file_put_contents(
            $path,
            "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
                . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
        );

        return $path;
    }
}
