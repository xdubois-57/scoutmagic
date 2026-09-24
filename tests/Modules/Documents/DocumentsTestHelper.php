<?php

declare(strict_types=1);

namespace Tests\Modules\Documents;

use Core\File\AttachedFileRemover;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Documents\Repository\DocumentRepository;
use Modules\Documents\Repository\DocumentVersionRepository;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentVisibility;

/**
 * The documents module's SQLite test table (mirrors
 * modules/documents/schema.sql) on top of the shared core test database,
 * plus the pieces every test of the module builds the same way.
 */
final class DocumentsTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            slug_is_random INTEGER NOT NULL DEFAULT 0,
            title TEXT NOT NULL,
            description TEXT NULL,
            visibility TEXT NOT NULL DEFAULT 'public',
            file_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE document_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            version_number INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            size_bytes INTEGER NOT NULL,
            uploaded_at TEXT NOT NULL,
            uploaded_by INTEGER NULL,
            archived_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (document_id, version_number)
        )");
    }

    /** A fresh storage root under the system temp directory. */
    public static function storage(): string
    {
        $path = sys_get_temp_dir() . '/documents_test_' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);
        return $path;
    }

    public static function service(
        \PDO $pdo,
        string $storage,
        ?FileRepository $files = null,
        ?DocumentRepository $documents = null,
        ?DocumentVersionRepository $versions = null
    ): DocumentService {
        $files ??= new FileRepository($pdo);
        return new DocumentService(
            $documents ?? new DocumentRepository($pdo),
            $versions ?? new DocumentVersionRepository($pdo),
            new UploadHandler($files, $storage),
            $files,
            new AttachedFileRemover($files, $storage),
            new JournalService(new JournalRepository($pdo)),
            $storage
        );
    }

    /**
     * A $_FILES entry for a small real file — a PDF by default, which
     * finfo recognises from its header alone.
     *
     * @return array<string, mixed>
     */
    public static function upload(string $name = 'reglement.pdf', ?string $content = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, $content ?? "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)];
    }

    /** No file chosen, as PHP reports it. */
    public static function noUpload(): array
    {
        return ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
    }

    public static function create(DocumentService $service, string $title, DocumentVisibility $visibility): int
    {
        return $service->create($title, null, $visibility->value, self::upload(), null)->id;
    }

    public static function fileRoleMin(\PDO $pdo, int $fileId): ?string
    {
        $stmt = $pdo->prepare('SELECT role_min FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
