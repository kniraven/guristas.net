CREATE TABLE IF NOT EXISTS eve_characters (
    character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    character_name VARCHAR(255) NOT NULL,
    corporation_id BIGINT UNSIGNED NULL,
    corporation_name VARCHAR(255) NULL,
    alliance_id BIGINT UNSIGNED NULL,
    alliance_name VARCHAR(255) NULL,
    birthday DATETIME NULL,
    profile_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eve_character_settings (
    character_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    preferred_theme VARCHAR(16) NOT NULL DEFAULT 'cryptic',
    favorite_ship_id BIGINT UNSIGNED NULL,
    ship_layout_json JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_eve_character_settings FOREIGN KEY (character_id)
        REFERENCES eve_characters(character_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
