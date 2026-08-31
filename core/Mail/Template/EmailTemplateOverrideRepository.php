<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

/**
 * The customised subject and body of an automatic e-mail, when one
 * exists.
 *
 * No row means no customisation — the e-mail is rendered from the Twig
 * template shipped with the application and keeps benefiting from every
 * update. That is a state, not an absence to be filled in: nothing here
 * ever writes a row holding the shipped wording.
 *
 * Not personal data, so nothing here is encrypted (schema/core.sql says
 * why). What IS a rule is that `body_html` arrives sanitised —
 * EmailTemplateCustomisationService does that before calling save(),
 * exactly as every other rich text is sanitised before storage
 * (SECURITY.md §7).
 */
class EmailTemplateOverrideRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{subject: string, body_html: string, updated_at: string, updated_by: ?int}|null
     */
    public function find(string $templateId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT subject, body_html, updated_at, updated_by FROM email_template_overrides WHERE template_id = ?'
        );
        $stmt->execute([$templateId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'subject' => (string) $row['subject'],
            'body_html' => (string) $row['body_html'],
            'updated_at' => (string) $row['updated_at'],
            'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
        ];
    }

    /**
     * Every customised template id — what the Configuration > E-mails
     * page reads to mark a row « Personnalisé », in one query rather than
     * one per template.
     *
     * @return list<string>
     */
    public function customisedTemplateIds(): array
    {
        $stmt = $this->pdo->query('SELECT template_id FROM email_template_overrides');

        return array_map('strval', $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Insert or replace one customisation.
     *
     * Portable upsert (SELECT then INSERT/UPDATE) rather than MySQL's own
     * `ON DUPLICATE KEY`, because the test database is SQLite — the same
     * two-step shape `Core\Member\UnitStaffSectionService` uses and for
     * the same reason.
     */
    public function save(string $templateId, string $subject, string $bodyHtml, ?int $updatedBy): void
    {
        $now = date('Y-m-d H:i:s');

        if ($this->find($templateId) !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE email_template_overrides
                 SET subject = ?, body_html = ?, updated_at = ?, updated_by = ?
                 WHERE template_id = ?'
            );
            $stmt->execute([$subject, $bodyHtml, $now, $updatedBy, $templateId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_template_overrides (template_id, subject, body_html, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$templateId, $subject, $bodyHtml, $now, $updatedBy]);
    }

    /**
     * Remove a customisation — « Revenir au gabarit par défaut ». The
     * e-mail goes back to the shipped template, and back onto the path of
     * every future update.
     */
    public function delete(string $templateId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_template_overrides WHERE template_id = ?');
        $stmt->execute([$templateId]);

        return $stmt->rowCount() > 0;
    }
}
