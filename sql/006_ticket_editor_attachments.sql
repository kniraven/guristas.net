-- Import once into the existing Guristas.net EVE account database.
ALTER TABLE guristas_tickets ADD COLUMN summary_format VARCHAR(8) NOT NULL DEFAULT 'plain';
ALTER TABLE guristas_ticket_activity ADD COLUMN body_format VARCHAR(8) NOT NULL DEFAULT 'plain';
CREATE TABLE IF NOT EXISTS guristas_ticket_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 activity_id BIGINT UNSIGNED NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(240) NOT NULL,
 storage_key CHAR(48) NOT NULL UNIQUE,
 mime_type VARCHAR(150) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ticket_id) REFERENCES guristas_tickets(id),
 FOREIGN KEY (activity_id) REFERENCES guristas_ticket_activity(id),
 INDEX (ticket_id, activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
