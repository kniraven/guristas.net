-- Guristas.net optional persistent-store migration, MySQL 8.0+
-- NON-DESTRUCTIVE: no DROP, TRUNCATE, ALTER existing tables or token values.
-- Execute once in phpMyAdmin, selecting the guristas_net database first.
CREATE TABLE IF NOT EXISTS guristas_pilot_records (
    environment VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    character_id BIGINT UNSIGNED NOT NULL,
    document JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (environment, character_id),
    KEY idx_pilot_character (character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guristas_stream_documents (
    filename VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Informational: see README for migrator, activation, and rollback steps.
