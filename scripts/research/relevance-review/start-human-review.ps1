[CmdletBinding()]
param([switch]$Test)

$ErrorActionPreference = 'Stop'
$reviewRoot = Join-Path $PSScriptRoot '../../../storage/app/ai-stage-05-04/5.4-1d-3a'
if (!(Test-Path -LiteralPath (Join-Path $reviewRoot 'unified-manifest.json'))) {
    & node (Join-Path $PSScriptRoot 'prepare-unified.mjs')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
$reviewArgs = @('--unified')
if ($Test) { $reviewArgs += '--test' }
& node (Join-Path $PSScriptRoot 'server.mjs') @reviewArgs
exit $LASTEXITCODE
