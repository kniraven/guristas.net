# One-time removal of the four backup files recorded in the supplied Guristas.net directory listing.
# Preview by omitting -Delete. This script intentionally has no wildcard or recursive deletion.
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$SiteRoot,
    [switch]$Delete
)
$ErrorActionPreference = 'Stop'
$rootItem = Get-Item -LiteralPath $SiteRoot -ErrorAction Stop
if (-not $rootItem.PSIsContainer -or $rootItem.Name -ine 'guristas.net') {
    throw 'SiteRoot must point to a directory named guristas.net.'
}
if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
    throw 'SiteRoot must be a real directory, not a junction or symbolic link.'
}
$root = $rootItem.FullName
$relativeFiles = @(
    'public\index.php.backup-20260724-043154',
    'public\assets\css\themes.css.backup-20260724-032758',
    'public\assets\css\themes.css.backup-20260724-033734',
    'public\assets\css\themes.css.backup-20260724-043154'
)
$candidates = @()
foreach ($relative in $relativeFiles) {
    $path = Join-Path $root $relative
    $parent = Split-Path -Parent $path
    # Refuse a redirected parent directory so no path can escape the site tree.
    $cursor = $parent
    while ($cursor.Length -gt $root.Length) {
        $dir = Get-Item -LiteralPath $cursor -ErrorAction Stop
        if (($dir.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Refusing redirected directory: $cursor"
        }
        $cursor = Split-Path -Parent $cursor
    }
    $exists = Test-Path -LiteralPath $path -PathType Leaf
    if ($exists) {
        $file = Get-Item -LiteralPath $path
        if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw "Refusing linked file: $path"
        }
    }
    $candidates += [pscustomobject]@{ Relative = $relative; Path = $path; Exists = $exists }
}
foreach ($candidate in $candidates) {
    if (-not $candidate.Exists) {
        Write-Host "Absent: $($candidate.Relative)"
        continue
    }
    if ($Delete) {
        Remove-Item -LiteralPath $candidate.Path -Force -ErrorAction Stop
        Write-Host "Deleted: $($candidate.Relative)"
    } else {
        Write-Host "Would delete: $($candidate.Relative)"
    }
}
if (-not $Delete) { Write-Host 'Preview only. Run again with -Delete to remove these exact files.' }
