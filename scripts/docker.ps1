<#
.SYNOPSIS
Isolated Docker environment for LibraryStroll. No host PHP/Python required.
.EXAMPLE
.\scripts\docker.ps1 up
.EXAMPLE
.\scripts\docker.ps1 up -Ollama gpu
.EXAMPLE
.\scripts\docker.ps1 artisan ai:status
#>
[CmdletBinding()]
param(
    [Parameter(Position=0)]
    [ValidateSet('init','up','down','status','logs','check','restart','artisan','test','smoke','build')]
    [string]$Action = 'up',
    [ValidateSet('host','docker','gpu')]
    [string]$Ollama,
    [Parameter(Position=1, ValueFromRemainingArguments=$true)]
    [string[]]$Arguments
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$dockerEnvPath = Join-Path $projectRoot 'docker/.env'
$examplePath = Join-Path $projectRoot 'docker/.env.example'

function New-Secret([int]$Size = 32) {
    $bytes = New-Object byte[] $Size
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

function Initialize-DockerEnv {
    if (Test-Path -LiteralPath $dockerEnvPath) {
        $content = [IO.File]::ReadAllText($dockerEnvPath)
    } else {
        $content = [IO.File]::ReadAllText($examplePath)
    }
    $required = @{
        APP_KEY = ('base64:' + (New-Secret))
        DB_PASSWORD = (New-Secret)
        MYSQL_ROOT_PASSWORD = (New-Secret)
        REVERB_APP_SECRET = (New-Secret)
        AI_SERVICE_API_KEY = (New-Secret)
    }
    foreach ($name in $required.Keys) {
        $pattern = '(?m)^' + [regex]::Escape($name) + '=([^\r\n]*)'
        $match = [regex]::Match($content, $pattern)
        if (!$match.Success) {
            $content += "`n$name=$($required[$name])`n"
        } elseif ([string]::IsNullOrWhiteSpace($match.Groups[1].Value)) {
            $content = [regex]::Replace($content, $pattern, "$name=$($required[$name])")
        }
    }
    if ($Ollama) {
        $pattern = '(?m)^DOCKER_OLLAMA_MODE=[^\r\n]*'
        if ([regex]::IsMatch($content, $pattern)) {
            $content = [regex]::Replace($content, $pattern, "DOCKER_OLLAMA_MODE=$Ollama")
        } else { $content += "`nDOCKER_OLLAMA_MODE=$Ollama`n" }
    }
    [IO.File]::WriteAllText($dockerEnvPath, $content.Replace("`r`n", "`n"), (New-Object Text.UTF8Encoding $false))
    Write-Host 'Docker environment ready. Existing secrets and OpenServer .env were preserved.'
}

function Invoke-Compose([string[]]$ComposeArguments) {
    & docker @script:composeFlags @ComposeArguments
    if ($LASTEXITCODE -ne 0) { throw "Docker Compose failed (exit $LASTEXITCODE)." }
}

Push-Location $projectRoot
try {
    if ($Action -in @('init','up')) { Initialize-DockerEnv }
    if (!(Test-Path -LiteralPath $dockerEnvPath)) { throw 'Run .\scripts\docker.ps1 init first.' }
    if (!$Ollama) {
        $saved = [regex]::Match([IO.File]::ReadAllText($dockerEnvPath), '(?m)^DOCKER_OLLAMA_MODE=([^\r\n]+)')
        $Ollama = if ($saved.Success) { $saved.Groups[1].Value.Trim() } else { 'host' }
    }
    $script:composeFlags = @('compose','--env-file','docker/.env','-f','compose.yaml')
    if ($Ollama -in @('docker','gpu')) { $script:composeFlags += @('-f','compose.ollama.yaml') }
    if ($Ollama -eq 'gpu') { $script:composeFlags += @('-f','compose.ollama-gpu.yaml') }

    switch ($Action) {
        'init' { Write-Host 'Next: .\scripts\docker.ps1 up'; break }
        'up' {
            Invoke-Compose (@('up','-d','--build','--wait','--wait-timeout','600') + $Arguments)
            Invoke-Compose @('ps')
            Write-Host 'Ready. Default URL: http://localhost:8088. See docker/.env for configured ports.'
            break
        }
        'build' { Invoke-Compose (@('build') + $Arguments); break }
        'down' {
            if ($Arguments) { throw 'down takes no arguments and never deletes volumes.' }
            Invoke-Compose @('down'); break
        }
        'status' { Invoke-Compose @('ps','-a'); break }
        'logs' { Invoke-Compose (@('logs','--follow','--tail','100') + $Arguments); break }
        'restart' { Invoke-Compose (@('restart') + $Arguments); break }
        'artisan' {
            if (!$Arguments) { throw 'Specify an artisan command, for example: artisan ai:status' }
            Invoke-Compose (@('exec','-T','app','php','artisan') + $Arguments); break
        }
        'check' {
            Invoke-Compose @('ps','-a')
            Invoke-Compose @('exec','-T','app','php','artisan','redis:check','--queue')
            Invoke-Compose @('exec','-T','app','php','artisan','ai:health')
            Invoke-Compose @('exec','-T','app','php','artisan','ai:status')
            break
        }
        'test' {
            Invoke-Compose @('exec','-T','-e','DB_CONNECTION=sqlite','-e','DB_DATABASE=:memory:','-e','DB_URL=',
                '-e','APP_ENV=testing','-e','CACHE_STORE=array','-e','SESSION_DRIVER=array','-e','QUEUE_CONNECTION=sync',
                '-e','AI_SERVICE_ENABLED=false','-e','LOG_CHANNEL=null',
                '-e','RUN_REDIS_SMOKE=1','app','php','vendor/bin/phpunit','--do-not-cache-result')
            Invoke-Compose @('exec','-T','ai-api','python','-m','pytest','-q','-p','no:cacheprovider')
            break
        }
        'smoke' {
            Invoke-Compose @('exec','-T','app','php','artisan','redis:check','--queue')
            Invoke-Compose @('exec','-T','-e','RUN_DOCKER_AI_SMOKE=1','ai-api','python','-m','pytest',
                'tests/test_docker_pipeline.py','-q','-p','no:cacheprovider')
            break
        }
    }
} finally { Pop-Location }
