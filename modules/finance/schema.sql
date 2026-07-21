-- finance module
--
-- A finance "exercice" (fiscal year) is simply a scout year — the core
-- `scout_years` table — not a module-specific entity an admin creates by
-- hand. There is no finance_fiscal_years table: finance_transactions
-- references scout_years(id) directly (see fk_ft_scout_year below).
-- Repository\FiscalYearRepository is a thin adapter over
-- Core\Config\ScoutYearService.

-- finance_accounts: a bank or cash account, optionally tied to a section
-- (e.g. one account per section, per the module spec's "un compte par
-- section est créé par défaut"). iban/holder_name are encrypted
-- (Core\Security\EncryptionService) like any other sensitive field —
-- iban_blind_index (HMAC) lets the import flow verify a bank statement's
-- IBAN against the target account without ever decrypting on a WHERE
-- clause. role_min_view/status are validated in Service\FinanceService,
-- not just the form: role_min_view can never go below 'intendant' (the
-- module's own access floor), and status can only become 'active' once
-- iban and holder_name are both set.
CREATE TABLE IF NOT EXISTS finance_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    account_type ENUM('bank', 'cash') NOT NULL,
    section_id INT UNSIGNED NULL,
    iban BLOB NULL,
    iban_blind_index VARCHAR(64) NULL,
    holder_name BLOB NULL,
    role_min_view ENUM('intendant', 'chief', 'admin') NOT NULL DEFAULT 'intendant',
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fa_iban_blind (iban_blind_index),
    CONSTRAINT fk_fa_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_categories: simple, flat category list for movement
-- classification. Deactivated (never deleted) once referenced by a
-- transaction, same "used elsewhere, deactivate instead" pattern as
-- Core\Badge.
CREATE TABLE IF NOT EXISTS finance_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_category_rules: auto-categorization rules evaluated in
-- ascending priority order by Service\CategoryRuleEngine during import
-- (module spec follow-up "itération 3") — the first matching rule wins.
-- condition_value's meaning depends on condition_type: a keyword to
-- search in the movement's label, an IBAN (full or partial) to match
-- against the counterparty account, or a numeric range like ">100" or
-- "50-200" for amount_range (evaluated against the absolute amount).
CREATE TABLE IF NOT EXISTS finance_category_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    condition_type ENUM('keyword', 'counterparty_account', 'amount_range') NOT NULL,
    condition_value VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fcr_priority (priority),
    CONSTRAINT fk_fcr_category FOREIGN KEY (category_id) REFERENCES finance_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_transactions: one row per bank/cash movement. label is
-- encrypted — a bank communication line can contain personal or
-- otherwise confidential information (e.g. a member's name in a payment
-- reference). amount is signed (positive = credit, negative = debit),
-- unlike most of this codebase's convention of separate signed/unsigned
-- fields, because a bank statement's own sign convention is what a
-- reconciling admin expects to see reproduced as-is. The unique
-- (account_id, bank_reference) pair is the import deduplication key
-- (module spec follow-up "itération 3": re-importing an overlapping
-- statement range must silently skip already-known lines) — manually
-- entered movements (source = 'manual') have no bank_reference, so it is
-- nullable and only enforced unique when present (see
-- Repository\TransactionRepository's insert-or-skip).
CREATE TABLE IF NOT EXISTS finance_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    fiscal_year_id INT UNSIGNED NOT NULL,
    bank_reference VARCHAR(100) NULL,
    transaction_date DATE NOT NULL,
    label BLOB NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    category_id INT UNSIGNED NULL,
    comment TEXT NULL,
    source ENUM('import', 'manual') NOT NULL,
    imported_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ft_account_reference (account_id, bank_reference),
    INDEX idx_ft_date (transaction_date),
    CONSTRAINT fk_ft_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ft_scout_year FOREIGN KEY (fiscal_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ft_category FOREIGN KEY (category_id) REFERENCES finance_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_balance_checkpoints: a known-good balance at a point in time,
-- so Service\BalanceService never has to sum every transaction since the
-- account's creation — it finds the closest checkpoint at or before the
-- requested date and adds/subtracts only the transactions in between.
-- The first import for an account must create one (module spec: "premier
-- import... solde obligatoire"); later imports create one only when a
-- balance was actually supplied.
CREATE TABLE IF NOT EXISTS finance_balance_checkpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    checkpoint_date DATE NOT NULL,
    balance DECIMAL(12, 2) NOT NULL,
    source ENUM('import', 'manual') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fbc_account_date (account_id, checkpoint_date),
    CONSTRAINT fk_fbc_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_statement_imports: bookkeeping for every bank statement import
-- run (module spec follow-up "itération 3") — shown on the import
-- result page and kept as an audit trail; never contains bank data
-- itself, only counts and the original filename.
CREATE TABLE IF NOT EXISTS finance_statement_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    bank_code VARCHAR(20) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    lines_total INT UNSIGNED NOT NULL DEFAULT 0,
    lines_new INT UNSIGNED NOT NULL DEFAULT 0,
    lines_duplicate INT UNSIGNED NOT NULL DEFAULT 0,
    imported_by INT UNSIGNED NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fsi_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_attachments: a receipt (or other supporting document). A
-- user-facing delete only ever archives it (status = 'archived'), so a
-- mistaken deletion never loses proof of an expense — the sole
-- exception is Task\PurgeOldMovementsHandler, which physically deletes
-- an attachment (row + encrypted file) once its fiscal year has been
-- purged past the retention period AND it has no remaining movement
-- association (active or archived). file_id references
-- the core files table (Core\File\FileAccessGuard); the file itself is
-- stored encrypted at rest via the generic Core\File\
-- EncryptedFileStorageService (files.encrypted = true for every
-- attachment's file row). parent_attachment_id chains a replacement to
-- the receipt it replaces, so the full version history of a given
-- expense's proof is always reconstructable. suggested_source
-- distinguishes a manually-typed suggestion (module spec: optional
-- amount/date fields on the upload form) from one written by
-- Task\ExtractReceiptDataHandler, so the UI can show "(IA)" only for the
-- latter — NULL when no suggestion has ever been set. account_id is
-- enforced NOT NULL at the application level (Service\ReceiptService::
-- upload()/replace() always require one) — kept nullable in the schema
-- itself only so any pre-existing row from before this requirement
-- never breaks a migration. A receipt's file inherits its account's
-- role_min_view as the underlying files.role_min at upload time (and is
-- re-synced whenever the account's role_min_view changes — see
-- Controller\ConfigAccountController), so downloading it via
-- Core\File\FileAccessGuard enforces the same floor as the account
-- itself. suggested_label is the AI-extracted merchant/reason (e.g.
-- "Delhaize") — only ever written by Task\ExtractReceiptDataHandler, no
-- manual-entry counterpart on the upload form (unlike suggested_amount/
-- suggested_date), so it is always NULL for a suggested_source='manual'
-- row. matching_ai_attempted_at marks that Service\ReceiptMatchingService
-- has already spent its one allowed AI-assisted matching attempt on this
-- receipt (rule-based matching has no such limit and is retried on every
-- bank import) — NULL means the AI fallback hasn't been tried yet.
CREATE TABLE IF NOT EXISTS finance_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NULL,
    file_id INT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    suggested_amount DECIMAL(12, 2) NULL,
    suggested_date DATE NULL,
    suggested_label VARCHAR(255) NULL,
    suggested_source ENUM('manual', 'ai') NULL,
    matching_ai_attempted_at DATETIME NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    parent_attachment_id INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fatt_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_fatt_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_fatt_parent FOREIGN KEY (parent_attachment_id) REFERENCES finance_attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_transaction_attachments: many-to-many join — a receipt can
-- support several movements (e.g. one invoice covering both a bank fee
-- and a purchase line) and a movement can have several receipts.
CREATE TABLE IF NOT EXISTS finance_transaction_attachments (
    transaction_id INT UNSIGNED NOT NULL,
    attachment_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (transaction_id, attachment_id),
    CONSTRAINT fk_fta_transaction FOREIGN KEY (transaction_id) REFERENCES finance_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fta_attachment FOREIGN KEY (attachment_id) REFERENCES finance_attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
