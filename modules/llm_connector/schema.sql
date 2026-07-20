-- llm_connector module
--
-- llm_providers: configured LLM API providers (one active at a time).
-- api_key is encrypted at rest via EncryptionService (BLOB column).
CREATE TABLE IF NOT EXISTS llm_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    driver VARCHAR(50) NOT NULL,
    api_endpoint VARCHAR(255) NOT NULL,
    api_key BLOB NOT NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- llm_provider_models: models discovered from a provider's API.
-- is_tier_cheap / is_tier_capable / is_tier_ocr: exactly one model per tier per provider.
CREATE TABLE IF NOT EXISTS llm_provider_models (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    model_id VARCHAR(100) NOT NULL,
    display_name VARCHAR(200) NOT NULL,
    is_tier_cheap TINYINT NOT NULL DEFAULT 0,
    is_tier_capable TINYINT NOT NULL DEFAULT 0,
    is_tier_ocr TINYINT NOT NULL DEFAULT 0,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_provider_model (provider_id, model_id),
    CONSTRAINT fk_model_provider FOREIGN KEY (provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
