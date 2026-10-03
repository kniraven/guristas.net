-- GURI-034: Convert legacy ticket summaries before deploying HTML-only editors.
-- Back up the database first. Run after 006_ticket_editor_attachments.sql.
-- Safe to repeat: summaries already marked html are untouched.
-- Comments and system history are unchanged.

START TRANSACTION;

UPDATE guristas_tickets
SET summary = REPLACE(
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(
                    REPLACE(summary, '&', '&amp;'),
                    '<', '&lt;'
                ),
                '>', '&gt;'
            ),
            CONCAT(CHAR(13), CHAR(10)), CHAR(10)
        ),
        CHAR(13), CHAR(10)
    ),
    CHAR(10), '<br>'
),
summary_format = 'html',
version = version + 1
WHERE summary_format = 'plain';

COMMIT;