param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9][a-z0-9_-]*$')][string] $Project,
    [string] $Manifest,
    [Parameter(Mandatory)][string] $OutputDir,
    [ValidateRange(1,100)][int] $BatchSize = 5,
    [switch] $DryRun,
    [switch] $VerifyAll,
    [switch] $AuditCatalog,
    [switch] $RetryFailed,
    [switch] $RetryFallback,
    [switch] $DeferVisionFallback,
    [switch] $RetryStale
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$root = Join-Path $repo 'storage/app/ai-stage-05-04'
$output = [IO.Path]::GetFullPath($OutputDir, (Get-Location).Path)
if (!$output.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Output must be inside storage/app/ai-stage-05-04'
}
$ids = @()
$approved = @()
if (!$AuditCatalog) {
    $bytes = [IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $Manifest).Path)
    $hash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
    $plan = [Text.Encoding]::UTF8.GetString($bytes).TrimStart([char]0xFEFF) | ConvertFrom-Json
    $ids = @($plan.artwork_ids)
    $approved = @($plan.approved_demo_ids)
    if ($plan.project -cne $Project -or $plan.collection -cnotmatch '^[a-zA-Z0-9_-]+$' -or !$ids.Count) {
        throw 'Invalid manifest project, collection or empty list'
    }
    foreach ($id in $ids + $approved) {
        if (($id -isnot [int] -and $id -isnot [long]) -or $id -lt 1 -or $id -gt 2147483647) {
            throw 'IDs must be positive integers'
        }
    }
    if ($ids.Count -ne @($ids | Select-Object -Unique).Count -or
        $approved.Count -ne @($approved | Select-Object -Unique).Count -or
        @($approved | Where-Object { $_ -notin $ids }).Count) {
        throw 'Duplicate IDs or approval outside the manifest'
    }
}
New-Item -ItemType Directory -Force -Path $output | Out-Null
$lock = [IO.File]::Open((Join-Path $root "recovery-$Project.lock"), 'OpenOrCreate', 'ReadWrite', 'None')
$journal = $null

function Log([hashtable] $Entry) {
    $Entry.utc = [DateTimeOffset]::UtcNow.ToString('o')
    $data = [Text.Encoding]::UTF8.GetBytes(($Entry | ConvertTo-Json -Depth 45 -Compress) + [char]10)
    $journal.Write($data, 0, $data.Length)
    $journal.Flush($true)
}
function EmptyQueue($Row) {
    ($Row.queue.pending + $Row.queue.reserved + $Row.queue.delayed) -eq 0
}
function Inspect([string] $Action, [long[]] $Id = @(), [bool] $Verify = $false) {
    if ($Action -eq 'inspect') { $inspected.AddRange($Id) }
    $request = @{ ids = @($Id); approved = @($approved); verify = $Verify } | ConvertTo-Json -Compress
    $targetJson = $target | ConvertTo-Json -Compress
    $lines = $inspectCode | docker exec -i -e "RECOVERY_ACTION=$Action" -e "RECOVERY_TARGET=$targetJson" -e "RECOVERY_REQUEST=$request" $containers.app php
    if ($LASTEXITCODE -ne 0) { throw "Laravel inspection failed ($Action, IDs $Id)" }
    $rows = @($lines | ForEach-Object { $_ | ConvertFrom-Json -Depth 45 })
    if ($Action -in @('inspect','enable') -and
        ($rows.Count -ne $Id.Count -or ($rows.id -join ',') -ne ($Id -join ','))) { throw 'Unexpected inspection IDs' }
    if ($Action -eq 'inspect') {
        foreach ($row in $rows) {
            $deferred = $deferrals[[long]$row.id]
            if ($deferred -and $row.classification -eq 'text_fallback' -and
                $row.latest_run.id -eq $deferred.latest_run.id -and $row.content_hash -eq $deferred.content_hash) {
                if ($Verify -and !$row.qdrant_revision_matches) { throw "Deferred point no longer matches for $($row.id)" }
                $row.classification = 'vision_deferred'
                $row.reason = $deferred.reason
            }
        }
    }
    $rows
}
function Health {
    $targetJson = $target | ConvertTo-Json -Compress
    $line = $healthCode | docker exec -i -e "RECOVERY_TARGET=$targetJson" $containers['ai-api'] python -
    $code = $LASTEXITCODE
    $value = $line | ConvertFrom-Json
    if ($code -ne 0 -or !$value) {
        if ($journal) { Log @{ event = 'service_unavailable'; health = $value } }
        throw "FastAPI check failed at $($value.stage) ($($value.error_type))"
    }
    $value
}
function CanProcess([string] $Class) {
    $Class -eq 'needs_index' -or
    ($Class -eq 'failed' -and $RetryFailed) -or
    ($Class -eq 'text_fallback' -and $RetryFallback) -or
    ($Class -eq 'revision_mismatch' -and $RetryStale)
}
function HasNewTerminalRun($Before, $After) {
    (EmptyQueue $After) -and $After.latest_run.id -and $After.latest_run.id -gt [long]$Before.latest_run.id -and
    $Before.content_hash -eq $After.content_hash -and $After.latest_run.content_hash -eq $After.content_hash -and
    $After.latest_run.status -in @('success','failed')
}
function Outcome($Row) {
    if ($Row.classification -eq 'vision_success') { return 'vision_success' }
    if ($Row.latest_run.status -eq 'failed') {
        if ($Row.latest_run.stage -eq 'embedding_and_indexing') { return 'index_error' }
        return 'analysis_error'
    }
    if ($Row.latest_run.analysis_source -eq 'text_fallback') { return 'text_fallback' }
    if ($Row.classification -eq 'revision_mismatch') { return 'revision_mismatch' }
    'incomplete'
}
function CanDefer($Row) {
    if (!$DeferVisionFallback -or $Row.classification -ne 'text_fallback' -or
        $Row.latest_run.status -ne 'success' -or !$Row.qdrant_revision_matches) { return $false }
    $attempts = @($Row.latest_run.vision_attempts)
    $errors = @('generation_limit','invalid_structured_metadata','missing_required_fields','incomplete_response')
    $attempts.Count -eq 2 -and $attempts[0].outcome -in $errors -and
        $attempts[1].retry_reason -eq $attempts[0].outcome -and $attempts[1].outcome -in $errors
}
function MarkDeferred($Row) {
    $Row.classification = 'vision_deferred'
    $Row.reason = $Row.latest_run.vision_attempts[-1].outcome
    $deferrals[[long]$Row.id] = $Row
}
function Report([long[]] $Selected, [string] $Mode) {
    $counts = [ordered]@{ completed = 0; remaining = 0; failed = 0; fallback = 0; deferred = 0; ineligible = 0 }
    foreach ($id in $ids) {
        $bucket = switch ($states[[long]$id]) {
            'vision_success' { 'completed' }
            'failed' { 'failed' }
            'text_fallback' { 'fallback' }
            'ineligible' { 'ineligible' }
                { $_ -in @('approval_required','in_progress','provenance_unknown','revision_mismatch','vision_deferred') } { 'deferred' }
            default { 'remaining' }
        }
        $counts[$bucket]++
    }
    [ordered]@{
        project = $Project; manifest_sha256 = $hash; mode = $Mode; batch_size = $BatchSize
        environment = $environment; health = $health; blockers = $blockers; selected = @($Selected)
        statistics = $counts; manifest_reconciled = $reconciled; reconciled_at = $reconciledAt
        inspected_ids = @($inspected)
        complete = $reconciled -and $counts.completed -eq $ids.Count
    } | ConvertTo-Json -Depth 12
}
function Reconcile {
    $rows = @(
        for ($offset = 0; $offset -lt $ids.Count; $offset += 50) {
            $end = [Math]::Min($offset + 49, $ids.Count - 1)
            Inspect 'inspect' @($ids[$offset..$end]) $true
        }
    )
    if (@($rows | Where-Object { !(EmptyQueue $_) }).Count) { throw 'Queue not empty during final verification' }
    foreach ($row in $rows) { $states[[long]$row.id] = $row.classification }
    if ($journal) {
        $summary = @($rows | Select-Object id, classification, reason, content_hash, media_uuid, qdrant_revision_matches,
            @{ Name = 'mysql_status'; Expression = { $_.metadata.status } },
            @{ Name = 'point_count'; Expression = { @($_.inspection.points | Where-Object { $_ }).Count } },
            @{ Name = 'saved_point'; Expression = { $_.inspection.saved_point } })
        Log @{ event = 'manifest_reconciled'; results = $summary }
    }
}

try {
    $target = @{ collection = $plan.collection }
    $network = $Project + '_default'
    $owner = docker network inspect --format '{{index .Labels "com.docker.compose.project"}}' $network
    if ($LASTEXITCODE -ne 0 -or $owner -cne $Project) { throw 'Unexpected Compose network' }
    $containers = @{}
    $format = '{{.Id}} {{index .Config.Labels "com.docker.compose.project"}} {{index .Config.Labels "com.docker.compose.service"}} {{.State.Running}} {{with index .NetworkSettings.Networks "' + $network + '"}}{{.IPAddress}}{{end}}'
    foreach ($service in @('app','ai-api','mysql','redis','qdrant')) {
        $info = docker inspect --format $format "$Project-$service-1"
        if ($LASTEXITCODE -ne 0) { throw "Cannot inspect $service" }
        $parts = $info -split ' '
        if ($parts.Count -ne 5 -or $parts[1] -cne $Project -or $parts[2] -cne $service -or
            $parts[3] -cne 'true' -or $parts[4] -notmatch '^\d+\.\d+\.\d+\.\d+$') { throw "Unexpected Compose service $service" }
        $containers[$service] = $parts[0]
        $key = if ($service -eq 'ai-api') { 'api' } else { $service }
        $target[$key] = $parts[4]
    }
    $target.database = docker exec $containers.mysql printenv MYSQL_DATABASE
    if ($LASTEXITCODE -ne 0 -or $target.database -cnotmatch '^[a-zA-Z0-9_]+$') { throw 'Cannot determine Compose database' }
    $running = @(docker ps --filter "label=com.docker.compose.project=$Project" --format '{{.Label "com.docker.compose.service"}}')
    if ($LASTEXITCODE -ne 0) { throw 'Cannot list running services' }
    $inspectCode = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'vision-recovery-inspect.php')
    $healthCode = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'vision-recovery-health.py')
    $environment = Inspect 'environment'
    $health = Health
    if ($environment.schema_version -ne $health.schema_version -or $environment.prompt_version -ne $health.prompt_version) {
        throw 'Laravel and FastAPI schema or prompt version differs'
    }
    $target.collection = $health.collection
    $blockers = @()
    if (!$environment.indexing_config_ok) { $blockers += 'laravel_indexing_config' }
    if ($health.status -ne 'ok') { $blockers += 'ollama_config_or_digest' }
    # Analyze makes one request; index is idempotent and may use all configured HTTP attempts.
    $indexBudget = $environment.http_timeout * [Math]::Max(1, $environment.http_retry_times) +
        [Math]::Max(0, $environment.http_retry_times - 1) * $environment.http_retry_sleep_ms / 1000
    if ($health.analysis_timeout + 5 -ge $environment.http_timeout -or
        $environment.http_timeout + $indexBudget + 10 -ge 600 -or $environment.queue_retry_after -le 600) {
        $blockers += 'analysis_http_queue_budget'
    }
    if (!(EmptyQueue $environment)) { $blockers += 'ai_queue_not_empty' }
    if ($running -contains 'worker-ai' -or $running -contains 'scheduler') { $blockers += 'background_ai_worker_or_scheduler' }
    if ($AuditCatalog) {
        @{ project = $Project; health = $health; blockers = $blockers; catalog = (Inspect 'audit') } | ConvertTo-Json -Depth 12
        return
    }
    if (!$DryRun -and !$VerifyAll -and $blockers.Count) { throw "Write run blocked: $($blockers -join ', ')" }

    $file = Join-Path $output 'events-v2.jsonl'
    $binding = @{
        event = 'manifest_bound'; sha256 = $hash; project = $Project; batch_size = $BatchSize
        database_server = $environment.database.server_uuid; database = $target.database
        collection = $target.collection; qdrant = $containers.qdrant; redis = $containers.redis
        redis_database = $environment.redis_database; redis_prefix = $environment.redis_prefix
        schema_version = $environment.schema_version; prompt_version = $environment.prompt_version
    }
    $states = @{}
    $deferrals = @{}
    $inspected = [Collections.Generic.List[long]]::new()
    $pending = @{}
    $unsealed = @{}
    $reconciled = $false
    $reconciledAt = $null
    $length = 0
    $bound = $false
    if (Test-Path -LiteralPath $file) {
        $bytes = [IO.File]::ReadAllBytes($file)
        $length = [Array]::LastIndexOf($bytes, [byte]10) + 1
        foreach ($line in [Text.Encoding]::UTF8.GetString($bytes, 0, $length).Split([char]10, [StringSplitOptions]::RemoveEmptyEntries)) {
            $entry = $line | ConvertFrom-Json -Depth 45
            if (!$bound) {
                foreach ($key in $binding.Keys) {
                    if ($entry.$key -cne $binding[$key]) { throw "Recovery state binding changed ($key). Review the queue before starting a new state." }
                }
                $bound = $true
                continue
            }
            switch ($entry.event) {
                'checkpoint' { $states[[long]$entry.id] = $entry.classification; $reconciled = $false }
                'dispatch_intent' { $pending[[long]$entry.id] = $entry; $reconciled = $false }
                'result' {
                    $id = [long]$entry.id
                    $pending.Remove($id)
                    $states[$id] = $entry.after.classification
                    if ($entry.outcome -eq 'vision_deferred') { $deferrals[$id] = $entry.after }
                    if ($entry.outcome -in @('vision_success','vision_deferred')) { $unsealed[$id] = $true }
                }
                { $_ -in @('batch_verified','batch_checked_with_deferrals','checkpoint_recovered','manifest_reconciled') } {
                    foreach ($row in $entry.results) {
                        $id = [long]$row.id
                        $unsealed.Remove($id)
                        $states[$id] = $row.classification
                    }
                    $reconciled = $entry.event -eq 'manifest_reconciled'
                    if ($reconciled) { $reconciledAt = $entry.utc }
                }
            }
        }
    } elseif (Test-Path -LiteralPath (Join-Path $output 'events.jsonl')) {
        throw 'Review the old 5.4-0C2 journal for unresolved dispatches and use a new output directory'
    }
    if (!$DryRun) {
        $journal = [IO.File]::Open($file, 'OpenOrCreate', 'ReadWrite', 'None')
        # Only LF-terminated entries were fully written before dispatch could happen.
        $journal.SetLength($length)
        $journal.Position = $length
        if (!$bound) { Log $binding }
    }
    foreach ($id in @($pending.Keys)) {
        $intent = $pending[$id]
        $row = Inspect 'inspect' @($id) $true
        $outcome = Outcome $row
        if (!(HasNewTerminalRun $intent.before $row) -or $outcome -eq 'incomplete') {
            if ($journal) { Log @{ event = 'dispatch_unresolved'; batch = $intent.batch; id = $id; current = $row } }
            throw "Uncertain dispatch for $id. Inspect the queue and job history before continuing."
        }
        if ($outcome -eq 'text_fallback' -and (CanDefer $row)) {
            MarkDeferred $row
            $outcome = 'vision_deferred'
        }
        if ($journal) { Log @{ event = 'result'; batch = $intent.batch; id = $id; outcome = $outcome; after = $row; recovered = $true } }
        $states[$id] = $row.classification
        if ($outcome -notin @('vision_success','vision_deferred')) { throw "Recovered terminal result for $id ($outcome). Review before retrying." }
        $unsealed[$id] = $true
    }
    if ($unsealed.Count) {
        $rows = @(Inspect 'inspect' @($unsealed.Keys) $true)
        if (@($rows | Where-Object { !(EmptyQueue $_) }).Count) { throw 'Queue not empty during recovery' }
        if ($journal) { Log @{ event = 'checkpoint_recovered'; results = $rows } }
        foreach ($row in $rows) { $states[[long]$row.id] = $row.classification }
        $reconciled = $false
    }
    if ($VerifyAll) {
        Reconcile
        $reconciled = $true
        $reconciledAt = [DateTimeOffset]::UtcNow.ToString('o')
        Report @($ids | Where-Object { CanProcess $states[[long]$_] } | Select-Object -First $BatchSize) 'verify_all'
        return
    }

    $candidates = @($ids | Where-Object { !$states.ContainsKey([long]$_) -or (CanProcess $states[[long]$_]) })
    $selected = [Collections.Generic.List[long]]::new()
    for ($offset = 0; $offset -lt $candidates.Count -and $selected.Count -lt $BatchSize; $offset += $BatchSize) {
        $end = [Math]::Min($offset + $BatchSize - 1, $candidates.Count - 1)
        foreach ($row in @(Inspect 'inspect' @($candidates[$offset..$end]))) {
            $states[[long]$row.id] = $row.classification
            if ($journal) { Log @{ event = 'checkpoint'; id = $row.id; classification = $row.classification; reason = $row.reason } }
            if ((CanProcess $row.classification) -and $selected.Count -lt $BatchSize) { $selected.Add($row.id) }
        }
        $reconciled = $false
    }
    if ($DryRun) {
        Report $selected 'dry_run'
        return
    }
    if ($selected.Count) {
        $batch = [guid]::NewGuid().ToString()
        Log @{ event = 'batch_started'; batch = $batch; ids = @($selected); models = $health.models }
        foreach ($id in $selected) {
            if ((Health).status -ne 'ok') { throw 'Ollama configuration changed' }
            $before = Inspect 'inspect' @($id)
            if (!(EmptyQueue $before)) { throw "Queue became nonempty before $id" }
            if ($before.classification -eq 'vision_success') { continue }
            if (!(CanProcess $before.classification)) { throw "Eligibility changed for $id" }
            if ($before.needs_enable) {
                $null = Inspect 'enable' @($id)
                $before = Inspect 'inspect' @($id)
                if ($before.classification -eq 'vision_success') { continue }
                if (!(EmptyQueue $before) -or !(CanProcess $before.classification) -or $before.needs_enable) {
                    throw "Eligibility or queue changed after enabling $id"
                }
            }
            Log @{ event = 'dispatch_intent'; batch = $batch; id = $id; before = $before }
            docker exec $containers.app php artisan ai:index-artwork $id --no-ansi | Write-Host
            if ($LASTEXITCODE -ne 0) { throw "Dispatch failed for $id. Inspect the queue before resuming." }
            docker exec $containers.app php artisan queue:work redis --queue=ai --once --timeout=600 --tries=3 --no-interaction --no-ansi | Write-Host
            $workerExit = $LASTEXITCODE
            try { $after = Inspect 'inspect' @($id) $true }
            catch {
                Log @{ event = 'result_unavailable'; batch = $batch; id = $id; worker_exit = $workerExit }
                throw
            }
            $outcome = Outcome $after
            if (!(HasNewTerminalRun $before $after) -or $outcome -eq 'incomplete') {
                Log @{ event = 'dispatch_unresolved'; batch = $batch; id = $id; current = $after; worker_exit = $workerExit }
                throw "Uncertain dispatch result for $id"
            }
            if ($workerExit -eq 0 -and $outcome -eq 'text_fallback' -and (CanDefer $after)) {
                MarkDeferred $after
                $outcome = 'vision_deferred'
            }
            Log @{ event = 'result'; batch = $batch; id = $id; outcome = $outcome; worker_exit = $workerExit; after = $after }
            if ($workerExit -ne 0 -or $outcome -notin @('vision_success','vision_deferred')) {
                $null = Health
                throw "Stopped at $id with $outcome. Review the saved result before resuming."
            }
        }
        $verified = @()
        try {
            $verified = @(Inspect 'inspect' $selected $true)
            if (@($verified | Where-Object { $_.classification -notin @('vision_success','vision_deferred') -or !(EmptyQueue $_) }).Count) {
                throw 'Batch did not pass live MySQL and Qdrant verification'
            }
        } catch {
            Log @{ event = 'batch_verification_failed'; batch = $batch; results = $verified; error = $_.Exception.Message }
            throw
        }
        $event = if ($verified.classification -contains 'vision_deferred') { 'batch_checked_with_deferrals' } else { 'batch_verified' }
        Log @{ event = $event; batch = $batch; results = $verified }
        foreach ($row in $verified) { $states[[long]$row.id] = $row.classification }
    }
    if (!$reconciled -and !@($ids | Where-Object { !$states.ContainsKey([long]$_) -or (CanProcess $states[[long]$_]) }).Count) {
        Reconcile
        $reconciled = $true
        $reconciledAt = [DateTimeOffset]::UtcNow.ToString('o')
    }
    Report $selected 'batch'
} finally {
    if ($journal) { $journal.Dispose() }
    $lock.Dispose()
}
