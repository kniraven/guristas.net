<?php
declare(strict_types=1);
/** Private, persistent site progress; separate from expiring ESI caches. */
final class GuristasPilotRecordStore
{
    private string $directory;
    public function __construct(string $directory) { $this->directory = $directory; }
    public static function initial(): array { return ['version' => 1, 'romance' => ['enabled' => false, 'chapter' => 0, 'affinity' => 0, 'choices' => []], 'combat' => ['kills' => [], 'checked' => [], 'last_sync' => null]]; }
    public function read(int $id): array { return $this->access($id, null); }
    public function update(int $id, callable $change): array { return $this->access($id, $change); }
    private function access(int $id, ?callable $change): array
    {
        if ($id < 1) throw new InvalidArgumentException('Character required.');
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
}
function eve_pilot_record_store(): GuristasPilotRecordStore
{
    // Local and production stories/evidence are isolated just like character grants.
    return new GuristasPilotRecordStore(dirname(__DIR__, 2) . '/storage/pilot-record/' . eve_token_table());
}
