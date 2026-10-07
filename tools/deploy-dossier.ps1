[CmdletBinding()]
param(
    [string]$RepositoryPath = 'K:\xampp\htdocs\guristas.net',
    [string]$SshKey = "$env:USERPROFILE\.ssh\Kniraven-ec2-key.pem",
    [string]$HostName = 'ec2-user@3.146.177.60'
)
$ErrorActionPreference = 'Stop'
function Git-Read([string[]]$Arguments) {
    $result = @(& git -C $RepositoryPath @Arguments)
    if ($LASTEXITCODE -ne 0) { throw "Git failed: $($Arguments -join ' ')" }
    return $result
}
$temp = $null
try {
    if (-not (Test-Path -LiteralPath $SshKey)) { throw "SSH key not found: $SshKey" }
    if (((Git-Read @('branch','--show-current')) -join '') -ne 'main') { throw 'Deploy requires main.' }
    if (@(Git-Read @('status','--porcelain','--untracked-files=all')).Count -gt 0) { throw 'Commit all changes before deploying.' }
    Git-Read @('fetch','--no-tags','origin','+refs/heads/main:refs/remotes/origin/main') | Out-Null
    $commit = ((Git-Read @('rev-parse','HEAD')) -join '').Trim()
    $remote = ((Git-Read @('rev-parse','origin/main')) -join '').Trim()
    if ($commit -ne $remote) { throw 'Local main must match origin/main.' }
    $files = @(Get-Content -LiteralPath (Join-Path $RepositoryPath 'docs/dossier-package-files.txt') | Where-Object { $_ -ne '' })
    foreach ($file in $files) {
        if ($file -notmatch '^(app|public|tests|docs|tools)/[A-Za-z0-9_./-]+$' -and $file -ne 'config/esi-scopes.php') { throw "Unexpected deploy path: $file" }
        if ($file.Contains('..')) { throw 'Invalid deploy path.' }
    }
    $temp = Join-Path ([IO.Path]::GetTempPath()) ('guristas-dossier-' + [guid]::NewGuid())
    New-Item -ItemType Directory -Path $temp | Out-Null
    $archive = Join-Path $temp 'source.tar'
    & git -C $RepositoryPath archive --format=tar --output=$archive $commit -- @files
    if ($LASTEXITCODE -ne 0) { throw 'Committed source archive failed.' }
    $script = Join-Path $temp 'deploy.sh'
    $text = [IO.File]::ReadAllText((Join-Path $RepositoryPath 'tools/deploy-dossier.sh')).Replace("`r`n", "`n")
    [IO.File]::WriteAllText($script, $text, (New-Object Text.UTF8Encoding($false)))
    $remoteBase = '/tmp/guristas-dossier-' + [guid]::NewGuid().ToString('N')
    $sshArgs = @('-i',$SshKey,'-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=accept-new')
    & scp @sshArgs $archive "${HostName}:${remoteBase}.tar"
    if ($LASTEXITCODE -ne 0) { throw 'Archive upload failed.' }
    & scp @sshArgs $script "${HostName}:${remoteBase}.sh"
    if ($LASTEXITCODE -ne 0) { throw 'Deployment script upload failed.' }
    & ssh @sshArgs $HostName "bash '${remoteBase}.sh' '${remoteBase}.tar' '$commit'"
    if ($LASTEXITCODE -ne 0) { throw 'Production deployment failed. Review the server output.' }
    Write-Host "Deployed committed source $commit. Sign in again and verify the dashboard."
} finally {
    if ($temp -and (Test-Path -LiteralPath $temp)) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
