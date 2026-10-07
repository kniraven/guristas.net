<?php
declare(strict_types=1);
$root = dirname(__DIR__);
function check(bool $result, string $message): void { if (!$result) throw new RuntimeException($message); }
function page(string $path, array $query = []): string {
    $root = dirname(__DIR__);
    $script = '$_GET = ' . var_export($query, true) . '; require ' . var_export($root . '/public/' . $path, true) . ';';
    $command = escapeshellarg(PHP_BINARY) . ' -n -d session.save_path=' . escapeshellarg(sys_get_temp_dir()) . ' -r ' . escapeshellarg($script);
    $output = shell_exec($command);
    check(is_string($output) && !str_contains($output, 'Fatal error') && !str_contains($output, 'Warning:'), 'Public page failed: ' . $path);
    return $output;
}
$missions = page('missions/index.php');
check(str_contains($missions, '70 matching contacts'), 'Expected full agent directory without authentication.');
$filtered = page('missions/index.php', ['q'=>'Aakie']);
check(str_contains($filtered, 'Aakie Sekichi') && str_contains($filtered, '1 matching contacts'), 'Agent search failed.');
$empty = page('missions/index.php', ['q'=>'zz_no_agent_match']);
check(str_contains($empty, 'No contacts match'), 'Missing empty state.');
$escaped = page('missions/index.php', ['q'=>'"><script>alert(1)</script>']);
check(!str_contains($escaped, '<script>alert(1)</script>'), 'Unescaped query.');
foreach (['war/guristas/index.php','join/index.php','signals/index.php','industry/index.php','operations/index.php','community/index.php'] as $path) {
    $html = page($path);
    check(str_contains($html,'OPEN ACCESS') && str_contains($html,'Skip to field tool'), 'Missing public shell.');
}
check(!str_contains(page('signals/index.php'),'autoplay'), 'Audio must not autoplay.');
echo "Public pages, agent search, empty state and escaping passed.\n";
