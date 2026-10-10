<?php
declare(strict_types=1);
/** Private, persistent site progress; separate from expiring ESI caches. */
final class GuristasPilotRecordStore
{

    public static function initial(): array { return ['version' => 1, 'romance' => ['enabled' => false, 'chapter' => 0, 'affinity' => 0, 'choices' => []], 'combat' => ['kills' => [], 'checked' => [], 'last_sync' => null]]; }
    public function read(int $id): array { return $this->access($id, null); }
    public function update(int $id, callable $change): array { return $this->access($id, $change); }
    private function access(int $id, ?callable $change): array
    {
        if ($id < 1) throw new InvalidArgumentException('Character required.');
        return $this->accessSql($id, $change);
    }
    /** Stores each pilot document transactionally in MySQL. */
    private function accessSql(int $id, ?callable $change): array
    {
        $db = eve_db();
        $environment = eve_token_table();
        if ($change === null) {
            $q = $db->prepare('SELECT document FROM guristas_pilot_records WHERE environment = ? AND character_id = ?');
            $q->execute([$environment, $id]);
            $raw = $q->fetchColumn();
            return $raw === false ? self::initial() : $this->decodeSqlRecord((string)$raw);
        }
        $db->beginTransaction();
        try {
            // INSERT IGNORE followed by SELECT FOR UPDATE serializes first writers too.
            $initial = json_encode(self::initial(), JSON_THROW_ON_ERROR);
            $q = $db->prepare('INSERT IGNORE INTO guristas_pilot_records (environment, character_id, document) VALUES (?, ?, ?)');
            $q->execute([$environment, $id, $initial]);
            $q = $db->prepare('SELECT document FROM guristas_pilot_records WHERE environment = ? AND character_id = ? FOR UPDATE');
            $q->execute([$environment, $id]);
            $raw = $q->fetchColumn();
            if ($raw === false) throw new RuntimeException('Pilot record not found after SQL lock.');
            $record = $change($this->decodeSqlRecord((string)$raw));
            if (!is_array($record)) throw new RuntimeException('Invalid updated pilot record.');
            $encoded = json_encode($record, JSON_THROW_ON_ERROR);
            $this->decodeSqlRecord($encoded);
            $q = $db->prepare('UPDATE guristas_pilot_records SET document = ? WHERE environment = ? AND character_id = ?');
            $q->execute([$encoded, $environment, $id]);
            $db->commit();
            return $record;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
    private function decodeSqlRecord(string $json): array
    {
        $record = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['version'] ?? null) !== 1 || !isset($record['romance'], $record['combat']) || !is_array($record['romance']) || !is_array($record['combat'])) {
            throw new RuntimeException('Invalid SQL pilot record.');
        }
        return $record;
    }
}
function eve_pilot_record_store(): GuristasPilotRecordStore
{
    // Local and production stories/evidence are isolated just like character grants.
    return new GuristasPilotRecordStore();
}
