# Public RAID/TRADE intelligence mirror for local design/testing only.
# No server credentials, no ESI scans, no production writes, no audit changes.
$ErrorActionPreference = 'Stop'
$root = 'K:\xampp\htdocs\guristas.net'
if (-not (Test-Path -LiteralPath (Join-Path $root 'public\index.php'))) { throw "Missing local Guristas website: $root" }
$destination = Join-Path $root 'storage\cache\economic-intelligence'
[IO.Directory]::CreateDirectory($destination) | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)
$items = @(
    @{ Url='https://guristas.net/api/war/raid-orders.php'; Name='raid'; Kind='raid' },
    @{ Url='https://guristas.net/api/industry/trade-orders.php'; Name='trade-orders'; Kind='trade' }
)
foreach ($item in $items) {
    $response = Invoke-RestMethod -Uri $item.Url -Method Get -TimeoutSec 35 -Headers @{'Accept'='application/json'}
    if (-not $response.ok -or -not $response.data) { throw "Live public intelligence unavailable for $($item.Name). Existing local data preserved." }
    if ($item.Kind -eq 'trade' -and -not $response.data.orders) { throw 'TRADE payload has no recommendations.' }
    $now = [DateTimeOffset]::UtcNow.ToString('o')
    $snapshot = if ($item.Kind -eq 'raid') {
        @{ data=$response.data; meta=$response.meta; sources=$response.sources; collected_at=([string]$response.generated_at); local_preview_mirror=$true; mirrored_at=$now }
    } else {
        @{ data=$response.data; collected_at=$now; local_preview_mirror=$true; mirrored_at=$now; note='Public API contains no publication timestamp. Local collected_at indicates mirror time, not original market collection.' }
    }
    if ($item.Kind -eq 'raid') {
        if (-not $snapshot.collected_at -or $snapshot.collected_at -eq '') { throw 'RAID API did not include original publication time.' }
        $age=([DateTimeOffset]::UtcNow - [DateTimeOffset]::Parse($snapshot.collected_at)).TotalSeconds
        if ($age -lt -30 -or $age -gt 900) { throw 'RAID live source is stale; not mirroring.' }
    }
    $json=ConvertTo-Json -InputObject $snapshot -Depth 100 -Compress
    $path=Join-Path $destination ($item.Name+'.json')
    [IO.File]::WriteAllText($path,$json,$utf8)
    Write-Host "Mirrored $($item.Name) to local cache" -ForegroundColor Green
}
Write-Host 'Refresh localhost:8085. Run this command again when you need newer screenshots.'
