<?php

declare(strict_types=1);

namespace Tests\Modules\News;

class NewsTestHelper
{
    public static function createTables(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE news_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            visibility TEXT NOT NULL DEFAULT "public",
            has_form INTEGER NOT NULL DEFAULT 0,
            is_indexed INTEGER NOT NULL DEFAULT 0,
            seo_keywords TEXT NULL,
            seo_stop_date TEXT NULL,
            short_url_code TEXT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES user_accounts(id)
        )');

        $pdo->exec('CREATE TABLE news_forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            news_article_id INTEGER NOT NULL UNIQUE,
            access TEXT NOT NULL DEFAULT "identified",
            response_limit TEXT NOT NULL DEFAULT "unlimited",
            opens_at TEXT NULL,
            closes_at TEXT NULL,
            is_force_closed INTEGER NOT NULL DEFAULT 0,
            response_role_min TEXT NOT NULL DEFAULT "chief",
            daily_digest_enabled INTEGER NOT NULL DEFAULT 0,
            last_digest_sent_at TEXT NULL,
            finance_account_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (news_article_id) REFERENCES news_articles(id)
        )');

        $pdo->exec('CREATE TABLE news_form_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            field_type TEXT NOT NULL,
            label TEXT NULL,
            is_required INTEGER NOT NULL DEFAULT 0,
            options_source TEXT NULL,
            options_manual TEXT NULL,
            capacity_max INTEGER NULL,
            price_per_unit REAL NULL,
            confirmation_text TEXT NULL,
            FOREIGN KEY (form_id) REFERENCES news_forms(id)
        )');

        $pdo->exec('CREATE TABLE news_form_responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
            user_account_id INTEGER NULL,
            member_year_id INTEGER NULL,
            contact_email BLOB NOT NULL,
            contact_email_blind_index TEXT NOT NULL,
            structured_communication TEXT NULL,
            receivable_id INTEGER NULL,
            submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NULL,
            FOREIGN KEY (form_id) REFERENCES news_forms(id)
        )');

        $pdo->exec('CREATE TABLE news_form_response_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            response_id INTEGER NOT NULL,
            field_id INTEGER NOT NULL,
            value BLOB NULL,
            FOREIGN KEY (response_id) REFERENCES news_form_responses(id),
            FOREIGN KEY (field_id) REFERENCES news_form_fields(id)
        )');
    }
}
