param(
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-z0-9][a-z0-9_-]*$')][string] $Project,
    [Parameter(Mandatory = $true)][string] $Manifest,
    [Parameter(Mandatory = $true)][string] $OutputDir,
    [Parameter(Mandatory = $true)][ValidateRange(1, 100)][int] $BatchSize,
    [switch] $DryRun,
    [switch] $RetryFailed,
    [switch] $RetryFallback,
    [switch] $RetryStale
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$manifestPath = (Resolve-Path -LiteralPath $Manifest).Path
$manifestBytes = [IO.File]::ReadAllBytes($manifestPath)
$manifestHash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($manifestBytes)).ToLowerInvariant()
$plan = [Text.Encoding]::UTF8.GetString($manifestBytes).TrimStart([char]0xFEFF) | ConvertFrom-Json
$ids = @($plan.artwork_ids)
$approvedDemo = @($plan.approved_demo_ids)
if ($plan.project -ne $Project -or $ids.Count -eq 0 -or $ids.Count -ne @($ids | Select-Object -Unique).Count) {
    throw 'Manifest project or fixed ID list is invalid'
}
foreach ($id in $ids + $approvedDemo) {
    if (($id -isnot [int] -and $id -isnot [long]) -or $id -lt 1 -or $id -gt 2147483647) {
        throw 'IDs must be positive integers'
    }
}
foreach ($id in $approvedDemo) {
    if ($id -notin $ids) { throw 'Approved demo IDs must be in the fixed list' }
}
if ($approvedDemo.Count -ne @($approvedDemo | Select-Object -Unique).Count) { throw 'Duplicate demo approvals' }

$projectRoot = Join-Path $repo 'storage/app/ai-stage-05-04'
$outputPath = [IO.Path]::GetFullPath((Join-Path (Get-Location).Path $OutputDir))
if (!$outputPath.StartsWith($projectRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Output must be inside storage/app/ai-stage-05-04'
}
New-Item -ItemType Directory -Force -Path $outputPath | Out-Null
$lock = [IO.File]::Open((Join-Path $projectRoot "recovery-$Project.lock"), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
try {
    $identity = Join-Path $outputPath 'manifest-identity.json'
    if (Test-Path -LiteralPath $identity) {
        $existing = Get-Content -Raw -LiteralPath $identity | ConvertFrom-Json
        if ($existing.sha256 -ne $manifestHash -or $existing.project -ne $Project -or $existing.batch_size -ne $BatchSize) {
            throw 'Existing recovery state belongs to another manifest, project or batch size'
        }
    } elseif (!$DryRun) {
        @{ sha256 = $manifestHash; project = $Project; batch_size = $BatchSize } |
            ConvertTo-Json | Set-Content -LiteralPath $identity -Encoding utf8NoBOM
    }
    $app = "$Project-app-1"
    $api = "$Project-ai-api-1"
    foreach ($service in @('app', 'ai-api', 'mysql', 'redis', 'qdrant')) {
        $actual = docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$Project-$service-1"
        if ($LASTEXITCODE -ne 0 -or $actual -ne $Project) { throw "Unexpected project for $service" }
    }
    $backgroundWorkers = @(docker ps --filter "label=com.docker.compose.project=$Project" --format '{{.Names}}' |
        Where-Object { $_ -match '(worker-ai|scheduler)' })
    if ($backgroundWorkers.Count -ne 0) { throw 'Stop project AI workers and scheduler before running this tool' }

    $inspectCode = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'vision-recovery-inspect.php')
    $healthCode = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'vision-recovery-health.py')
    function Health {
        $out = $healthCode | docker exec -i $api python -
        if ($LASTEXITCODE -ne 0) {
            if (!$DryRun) { Log @{ event = 'service_unavailable'; response = "$out"; utc = [DateTimeOffset]::UtcNow.ToString('o') } }
            throw "AI unavailable or model mismatch: $out"
        }
        return ($out | ConvertFrom-Json)
    }
    function Inspect([int] $Id, [string] $Action, [bool] $Verify) {
        $demo = if ($Id -in $approvedDemo) { '1' } else { '0' }
        $verifyValue = if ($Verify) { '1' } else { '0' }
        $out = $inspectCode | docker exec -i -e "RECOVERY_ARTWORK_ID=$Id" -e "RECOVERY_ACTION=$Action" -e "RECOVERY_DEMO_ALLOWED=$demo" -e "RECOVERY_VERIFY_POINT=$verifyValue" $app php
        if ($LASTEXITCODE -ne 0) { throw "Inspection failed for $Id" }
        return ($out | ConvertFrom-Json -Depth 40)
    }
    function Log([object] $Entry) {
        $Entry | ConvertTo-Json -Depth 45 -Compress | Add-Content -LiteralPath (Join-Path $outputPath 'events.jsonl') -Encoding utf8NoBOM
    }
    function EmptyQueue($Row) {
        return ($Row.queue.pending + $Row.queue.reserved + $Row.queue.delayed) -eq 0
    }

    $health = Health
    if ($plan.collection -ne $health.collection) { throw 'Configured collection differs from manifest' }
    $journal = Join-Path $outputPath 'events.jsonl'
    if (Test-Path -LiteralPath $journal) {
        $history = @(Get-Content -LiteralPath $journal | ForEach-Object { $_ | ConvertFrom-Json -Depth 45 })
        foreach ($intent in @($history | Where-Object event -eq 'dispatch_intent')) {
            $finished = @($history | Where-Object { $_.event -eq 'result' -and $_.batch -eq $intent.batch -and $_.id -eq $intent.id })
            if ($finished.Count -eq 0) {
                $current = Inspect $intent.id 'inspect' $true
                if ($current.classification -ne 'vision_success' -or !(EmptyQueue $current)) {
                    throw "Unfinished dispatch for $($intent.id). Inspect the saved intent, job history and queue before resuming."
                }
                if (!$DryRun) { Log @{ event = 'result'; batch = $intent.batch; id = $intent.id; outcome = 'vision_success'; after = $current; recovered = $true; utc = [DateTimeOffset]::UtcNow.ToString('o') } }
            }
        }
    }
    $selected = @()
    foreach ($id in $ids) {
        $state = Inspect $id 'inspect' $false
        if (!(EmptyQueue $state)) { throw 'AI queue is not empty, inspect it before continuing' }
        if ($state.classification -eq 'vision_success') {
            if (!$DryRun) { Log @{ event = 'skip_vision'; id = $id; state = $state; utc = [DateTimeOffset]::UtcNow.ToString('o') } }
            Write-Host "Skip $id, verified Vision and matching point"
            continue
        }
        if ($state.classification -in @('ineligible', 'in_progress', 'provenance_unknown')) {
            if (!$DryRun) { Log @{ event = 'skip'; id = $id; state = $state; utc = [DateTimeOffset]::UtcNow.ToString('o') } }
            Write-Host "Skip $id, $($state.classification)"
            continue
        }
        if (($state.classification -eq 'failed' -and !$RetryFailed) -or
            ($state.classification -eq 'text_fallback' -and !$RetryFallback) -or
            ($state.classification -eq 'revision_mismatch' -and !$RetryStale)) {
            if (!$DryRun) { Log @{ event = 'deferred'; id = $id; state = $state; utc = [DateTimeOffset]::UtcNow.ToString('o') } }
            Write-Host "Deferred $id, $($state.classification)"
            continue
        }
        $selected += $id
        if ($selected.Count -eq $BatchSize) { break }
    }
    if ($DryRun) {
        [pscustomobject]@{ project = $Project; manifest_sha256 = $manifestHash; models = $health.models;
            collection = $health.collection; selected = $selected; batch_size = $BatchSize } | ConvertTo-Json -Depth 8
        return
    }
    $batchId = [guid]::NewGuid().ToString()
    Log @{ event = 'batch_started'; batch = $batchId; ids = $selected; manifest_sha256 = $manifestHash;
        models = $health.models; utc = [DateTimeOffset]::UtcNow.ToString('o') }
    foreach ($id in $selected) {
        $null = Health
        $before = Inspect $id 'inspect' $false
        if (!(EmptyQueue $before)) { throw "Queue became nonempty before $id" }
        if ($before.classification -eq 'vision_success') {
            Log @{ event = 'skip_vision'; batch = $batchId; id = $id; state = $before }
            continue
        }
        if ($before.classification -eq 'enable_demo') {
            $null = Inspect $id 'enable' $false
            $before = Inspect $id 'inspect' $false
        }
        $allowed = @('needs_index')
        if ($RetryFailed) { $allowed += 'failed' }
        if ($RetryFallback) { $allowed += 'text_fallback' }
        if ($RetryStale) { $allowed += 'revision_mismatch' }
        if ($before.classification -notin $allowed) { throw "Eligibility changed for $id" }
        Log @{ event = 'dispatch_intent'; batch = $batchId; id = $id; before = $before; utc = [DateTimeOffset]::UtcNow.ToString('o') }
        docker exec $app php artisan ai:index-artwork $id --no-ansi
        if ($LASTEXITCODE -ne 0) { throw "Dispatch failed for $id, inspect queue before resuming" }
        docker exec $app php artisan queue:work redis --queue=ai --once --timeout=600 --tries=3 --no-interaction --no-ansi
        $workerExit = $LASTEXITCODE
        try {
            $after = Inspect $id 'inspect' $true
        } catch {
            Log @{ event = 'result'; batch = $batchId; id = $id; outcome = 'inspection_unavailable'; worker_exit = $workerExit;
                utc = [DateTimeOffset]::UtcNow.ToString('o') }
            throw "Stopped at $id because result inspection failed. Review the job and queue before resuming."
        }
        $run = $after.latest_run
        $outcome = if ($after.classification -eq 'vision_success') { 'vision_success' }
        elseif ($run.analysis_source -eq 'text_fallback') { 'text_fallback' }
        elseif ($run.reason -eq 'revision_changed' -or $after.classification -eq 'revision_mismatch') { 'revision_mismatch' }
        elseif ($run.status -eq 'failed' -and $run.stage -eq 'embedding_and_indexing') { 'index_error' }
        elseif ($run.status -eq 'failed') { 'analysis_error' }
        else { 'incomplete' }
        Log @{ event = 'result'; batch = $batchId; id = $id; outcome = $outcome; worker_exit = $workerExit;
            after = $after; utc = [DateTimeOffset]::UtcNow.ToString('o') }
        Write-Host "ID $id outcome $outcome"
        if ($workerExit -ne 0 -or !(EmptyQueue $after) -or $outcome -ne 'vision_success') {
            $null = Health
            throw "Stopped at $id with $outcome. Review the saved result and queue before resuming."
        }
    }
    $verified = foreach ($id in $selected) { Inspect $id 'inspect' $true }
    Log @{ event = 'batch_verified'; batch = $batchId; results = @($verified); utc = [DateTimeOffset]::UtcNow.ToString('o') }
    if (@($verified | Where-Object classification -ne 'vision_success').Count -gt 0) {
        throw 'Batch verification failed'
    }
    Write-Host "Batch $batchId verified, $($selected.Count) processed"
} finally {
    $lock.Dispose()
}
