-- Run once in guristas_net on local and production databases.
CREATE TABLE IF NOT EXISTS eve_character_tokens (
    character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    access_token_sealed TEXT NOT NULL,
    refresh_token_sealed TEXT NOT NULL,
    scopes_json JSON NOT NULL,
    expires_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_eve_character_tokens FOREIGN KEY (character_id)
        REFERENCES eve_characters(character_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
