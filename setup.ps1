# BOARDRIDERS first-time Windows setup.
# Run from PowerShell: powershell -ExecutionPolicy Bypass -File .\setup.ps1
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Require-Command([string] $name) {
    if (-not (Get-Command $name -ErrorAction SilentlyContinue)) {
        throw "Required command '$name' not found. See README.md for prerequisites."
    }
}
function Invoke-Checked([string] $command, [string[]] $commandArgs) {
    & $command @commandArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed (exit $LASTEXITCODE): $command $($commandArgs -join ' ')"
    }
}
function Write-Utf8NoBom([string] $file, [string] $value) {
    [System.IO.File]::WriteAllText($file, $value, (New-Object System.Text.UTF8Encoding($false)))
}
function Set-EnvValue([string] $file, [string] $key, [string] $value) {
    $current = [System.IO.File]::ReadAllText($file)
    $pattern = '(?m)^[ \t]*#?[ \t]*' + [regex]::Escape($key) + '=[^\r\n]*'
    if ([regex]::IsMatch($current, $pattern)) {
        $current = [regex]::Replace($current, $pattern, "$key=$value")
    } else {
        $current = $current.TrimEnd("`r", "`n") + "`r`n$key=$value`r`n"
    }
    Write-Utf8NoBom $file $current
}

Require-Command 'node'
Require-Command 'npm'
Require-Command 'npx.cmd'
Require-Command 'php'
Require-Command 'composer'
Require-Command 'docker'

$phpVersion = (& php -r 'echo PHP_VERSION_ID;') -as [int]
if ($phpVersion -lt 80300) { throw 'Laravel 13 requires PHP 8.3 or newer.' }
$phpModules = & php -m
if (-not ($phpModules | Where-Object { $_ -ieq 'pdo_pgsql' })) {
    throw 'PHP extension pdo_pgsql is missing. Enable pdo_pgsql in php.ini before continuing.'
}

Write-Host '==> Starting PostgreSQL and Redis...' -ForegroundColor Cyan
Invoke-Checked 'docker' @('compose', 'up', '-d')

$createdFrontend = $false
if (-not (Test-Path '.\frontend\package.json')) {
    if (Test-Path '.\frontend') { throw 'frontend folder exists but package.json is missing. Please check it manually.' }
    Write-Host '==> Creating Next.js frontend...' -ForegroundColor Cyan
    Invoke-Checked 'npx.cmd' @('create-next-app@latest', 'frontend', '--yes', '--use-npm', '--ts', '--eslint', '--tailwind', '--app', '--src-dir', '--import-alias', '@/*')
    $createdFrontend = $true
}

$createdBackend = $false
if (-not (Test-Path '.\backend\artisan')) {
    if (Test-Path '.\backend') { throw 'backend folder exists but artisan is missing. Please check it manually.' }
    Write-Host '==> Creating Laravel 13 backend...' -ForegroundColor Cyan
    Invoke-Checked 'composer' @('create-project', 'laravel/laravel', 'backend', '^13.0')
    $createdBackend = $true
}

$backendEnv = Join-Path $PSScriptRoot 'backend\.env'
if (-not (Test-Path $backendEnv)) {
    Copy-Item '.\backend\.env.example' $backendEnv
}
(@{
    'APP_NAME' = 'BOARDRIDERS'
    'APP_URL' = 'http://127.0.0.1:8000'
    'DB_CONNECTION' = 'pgsql'
    'DB_HOST' = '127.0.0.1'
    'DB_PORT' = '55432'
    'DB_DATABASE' = 'boardriders'
    'DB_USERNAME' = 'boardriders'
    'DB_PASSWORD' = 'boardriders_dev_only'
    'REDIS_HOST' = '127.0.0.1'
    'REDIS_PORT' = '56379'
    'QUEUE_CONNECTION' = 'database'
    'CACHE_STORE' = 'database'
}).GetEnumerator() | ForEach-Object {
    Set-EnvValue $backendEnv $_.Key $_.Value
}

Push-Location '.\backend'
try {
    if (-not (Test-Path '.\routes\api.php')) {
        Write-Host '==> Enabling Laravel API routes (Sanctum)...' -ForegroundColor Cyan
        Invoke-Checked 'php' @('artisan', 'install:api', '--no-interaction')
    }
    $apiFile = Join-Path (Get-Location) 'routes\api.php'
    if (-not (Test-Path $apiFile)) {
        throw 'Laravel did not create routes/api.php. Check install:api output.'
    }
    $apiContent = [System.IO.File]::ReadAllText($apiFile)
    if (-not $apiContent.Contains("Route::get('/health'")) {
        $health = [System.IO.File]::ReadAllText((Join-Path $PSScriptRoot 'templates\api-health.php.txt'))
        Write-Utf8NoBom $apiFile ($apiContent.TrimEnd() + "`r`n" + $health)
    }
    Write-Host '==> Running PostgreSQL migrations...' -ForegroundColor Cyan
    Invoke-Checked 'php' @('artisan', 'config:clear')
    Invoke-Checked 'php' @('artisan', 'migrate', '--no-interaction')
} finally {
    Pop-Location
}

if ($createdFrontend) {
    Copy-Item '.\templates\page.tsx' '.\frontend\src\app\page.tsx' -Force
    if (Test-Path '.\frontend\.git') {
        Remove-Item '.\frontend\.git' -Recurse -Force
    }
}
if (-not (Test-Path '.\frontend\.env.local')) {
    Write-Utf8NoBom (Join-Path $PSScriptRoot 'frontend\.env.local') "API_INTERNAL_URL=http://127.0.0.1:8000`n"
}

Write-Host ''
Write-Host 'BOARDRIDERS base setup complete.' -ForegroundColor Green
Write-Host 'Terminal 1: cd backend; php artisan serve --host=127.0.0.1 --port=8000'
Write-Host 'Terminal 2: cd frontend; npm run dev'
Write-Host 'API health: http://127.0.0.1:8000/api/health'
Write-Host 'Frontend:   http://localhost:3000'
