<?php
declare(strict_types=1);
/** Private, persistent site progress; separate from expiring ESI caches. */
final class GuristasPilotRecordStore
{
    private string $directory;
    private bool $useSql = false;
    public function __construct(string $directory) {
        $this->directory = $directory;
        $this->useSql = guristas_storage_backend('pilot') === 'sql';
    }
    public static function initial(): array { return ['version' => 1, 'romance' => ['enabled' => false, 'chapter' => 0, 'affinity' => 0, 'choices' => []], 'combat' => ['kills' => [], 'checked' => [], 'last_sync' => null]]; }
    public function read(int $id): array { return $this->access($id, null); }
    public function update(int $id, callable $change): array { return $this->access($id, $change); }
    private function access(int $id, ?callable $change): array
    {
        if ($id < 1) throw new InvalidArgumentException('Character required.');
        if ($this->useSql) return $this->accessSql($id, $change);
        $path = $this->directory . '/' . $id . '.json';
        if ($change === null && !is_file($path)) return self::initial();
        if (!is_dir($this->directory) && !mkdir($this->directory, 02750, true) && !is_dir($this->directory)) throw new RuntimeException('Cannot create pilot record directory.');
        // A stable lock file keeps readers and writers synchronized across atomic renames.
        $file = fopen($path . '.lock', 'c+b');
        if (!$file) throw new RuntimeException('Cannot open pilot record lock.');
        chmod($path . '.lock', 0640);
        try {
            if (!flock($file, $change === null ? LOCK_SH : LOCK_EX)) throw new RuntimeException('Cannot lock pilot record.');
            $raw = is_file($path) ? file_get_contents($path) : '';
            if ($raw === false) throw new RuntimeException('Cannot read pilot record.');
            $record = $raw === '' ? self::initial() : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['version'] ?? null) !== 1 || !isset($record['romance'], $record['combat'])) throw new RuntimeException('Invalid pilot record.');
            if ($change !== null) {
                $record = $change($record);
                $json = json_encode($record, JSON_THROW_ON_ERROR);
                $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
                try {
                    if (file_put_contents($temp, $json) !== strlen($json) || !chmod($temp, 0640) || !rename($temp, $path)) throw new RuntimeException('Cannot save pilot record.');
                } finally { if (is_file($temp)) unlink($temp); }
            }
            return $record;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
    /** SQL mode keeps exactly the original record document shape. */
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
    return new GuristasPilotRecordStore(dirname(__DIR__, 2) . '/storage/pilot-record/' . eve_token_table());
}

/** File remains private, outside public/, and is never committed. */
if (!function_exists('guristas_storage_backend')) {
    function guristas_storage_backend(string $feature): string
    {
        static $settings = null;
        if ($settings === null) {
            $file = dirname(__DIR__, 2) . '/config/storage-backends.local.php';
            $settings = is_file($file) ? require $file : [];
            if (!is_array($settings)) throw new RuntimeException('Invalid storage backend configuration.');
        }
        $backend = $settings[$feature] ?? 'json';
        if (!in_array($backend, ['json', 'sql'], true)) throw new RuntimeException('Unsupported storage backend.');
        return $backend;
    }
}
