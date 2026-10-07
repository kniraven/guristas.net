param([string]$RepositoryPath = 'K:\xampp\htdocs\guristas.net')
$ErrorActionPreference = 'Stop'
$tracked = @(& git -C $RepositoryPath ls-files -- storage/community)
if ($LASTEXITCODE -ne 0) { throw 'Community record check failed.' }
if ($tracked.Count -gt 0) { throw 'Community records are already tracked. Stop and review before proceeding.' }
$exclude = @(& git -C $RepositoryPath rev-parse --git-path info/exclude)
if ($LASTEXITCODE -ne 0 -or $exclude.Count -ne 1) { throw 'Local Git exclude path unavailable.' }
$excludePath = [string]$exclude[0]
if (-not [IO.Path]::IsPathRooted($excludePath)) { $excludePath = Join-Path $RepositoryPath $excludePath }
if ((Test-Path -LiteralPath $excludePath) -and ((Get-Item -LiteralPath $excludePath).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Linked Git exclude file needs review.' }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $excludePath) | Out-Null
$lines = if (Test-Path -LiteralPath $excludePath) { @(Get-Content -LiteralPath $excludePath) } else { @() }
if ($lines -notcontains '/storage/community/') {
    [IO.File]::AppendAllText($excludePath, "`n/storage/community/`n", (New-Object Text.UTF8Encoding($false)))
}
