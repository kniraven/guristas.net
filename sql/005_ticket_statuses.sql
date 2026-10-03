-- Run once in the Guristas.net EVE account database.
-- Label change preserves original status age and all ticket contents.
UPDATE guristas_tickets
SET status = 'To Do', version = version + 1
WHERE status = 'Ready';
