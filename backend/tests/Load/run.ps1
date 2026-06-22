# ============================================================
# ExamSphere Load Test Runner
# ============================================================
# Usage:
#   .\tests\Load\run.ps1                 # full 20,000-user run
#   .\tests\Load\run.ps1 -Smoke          # quick 100-user smoke test
#   .\tests\Load\run.ps1 -Test login     # run only Test 01
#   .\tests\Load\run.ps1 -Test exam      # run only Test 02
#   .\tests\Load\run.ps1 -Test burst     # run only Test 03
#   .\tests\Load\run.ps1 -Cleanup        # remove all load test data
#   .\tests\Load\run.ps1 -SkipSeed       # skip DB seeding
#
# Requirements:
#   - k6 installed (https://grafana.com/docs/k6/latest/set-up/install-k6/)
#       choco install k6       OR
#       winget install k6
#   - php artisan accessible (run from backend/ or set -PhpPath)
#   - A real web server (Nginx + PHP-FPM) for 20 k+ tests.
#     php artisan serve is single-threaded and will queue all requests.
# ============================================================

param (
    [switch] $Smoke,          # limit to 100 VUs for a quick sanity check
    [string] $Test    = 'all', # login | exam | burst | all
    [switch] $SkipSeed,
    [switch] $Cleanup,
    [string] $BaseUrl = 'http://localhost:8000',
    [string] $PhpPath = 'php'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir = Split-Path -Parent (Split-Path -Parent $ScriptDir)
$K6Dir      = Join-Path $ScriptDir 'k6'

# ── Helpers ───────────────────────────────────────────────────────────────────

function Write-Header([string] $msg) {
    $line = '═' * 60
    Write-Host "`n$line" -ForegroundColor Cyan
    Write-Host "  $msg" -ForegroundColor Cyan
    Write-Host "$line`n" -ForegroundColor Cyan
}

function Write-Step([string] $msg) {
    Write-Host "▶  $msg" -ForegroundColor Yellow
}

function Assert-k6 {
    try { $null = & k6 version 2>&1; Write-Host "  k6 found." -ForegroundColor Green }
    catch {
        Write-Host "ERROR: k6 not found." -ForegroundColor Red
        Write-Host "Install with:  choco install k6   OR   winget install k6" -ForegroundColor Red
        exit 1
    }
}

function Assert-Server {
    Write-Step "Checking server at $BaseUrl ..."
    try {
        $res = Invoke-WebRequest -Uri $BaseUrl -TimeoutSec 5 -UseBasicParsing -EA Stop
        Write-Host "  Server responding (HTTP $($res.StatusCode))." -ForegroundColor Green
    } catch {
        Write-Host "WARNING: Server at $BaseUrl not responding." -ForegroundColor Yellow
        Write-Host "  Make sure php artisan serve (or Nginx) is running." -ForegroundColor Yellow
        $continue = Read-Host "Continue anyway? (y/n)"
        if ($continue -ne 'y') { exit 1 }
    }
}

function Invoke-Artisan([string] $cmd) {
    Write-Step "php artisan $cmd"
    Push-Location $BackendDir
    & $PhpPath artisan $cmd
    Pop-Location
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: artisan command failed." -ForegroundColor Red
        exit 1
    }
}

function Run-K6([string] $script, [hashtable] $envVars = @{}) {
    $scriptPath = Join-Path $K6Dir $script
    $args = @('run', $scriptPath, "--env", "BASE_URL=$BaseUrl")

    foreach ($kv in $envVars.GetEnumerator()) {
        $args += '--env'
        $args += "$($kv.Key)=$($kv.Value)"
    }

    Write-Step "k6 $($args -join ' ')"
    & k6 @args
    if ($LASTEXITCODE -ne 0) {
        Write-Host "k6 test FAILED (exit $LASTEXITCODE)." -ForegroundColor Red
    } else {
        Write-Host "k6 test PASSED." -ForegroundColor Green
    }
    return $LASTEXITCODE
}

# ── Session driver check ──────────────────────────────────────────────────────

function Check-SessionDriver {
    $envFile = Join-Path $BackendDir '.env'
    if (Test-Path $envFile) {
        $content = Get-Content $envFile -Raw
        if ($content -match 'SESSION_DRIVER\s*=\s*file') {
            Write-Host ""
            Write-Host "⚠  WARNING: SESSION_DRIVER=file detected in .env" -ForegroundColor Yellow
            Write-Host "   File-based sessions will not scale to 20,000 concurrent users." -ForegroundColor Yellow
            Write-Host "   Switch to database or redis sessions before running large tests:" -ForegroundColor Yellow
            Write-Host "     1. Set SESSION_DRIVER=database in .env" -ForegroundColor Yellow
            Write-Host "     2. php artisan migrate  (sessions table already exists)" -ForegroundColor Yellow
            Write-Host ""
        }
        if ($content -notmatch 'SESSION_DRIVER') {
            Write-Host "⚠  SESSION_DRIVER not set in .env — defaults to file." -ForegroundColor Yellow
            Write-Host "   Recommended: SESSION_DRIVER=database" -ForegroundColor Yellow
            Write-Host ""
        }
    }
}

# ── Main ──────────────────────────────────────────────────────────────────────

Write-Header "ExamSphere Load Test Suite"
Write-Host "  Base URL : $BaseUrl"
Write-Host "  Mode     : $(if ($Smoke) {'SMOKE (100 VUs)'} else {'FULL (20,000 VUs)'})"
Write-Host "  Test     : $Test"
Write-Host ""

Assert-k6

# ── Cleanup mode ──────────────────────────────────────────────────────────────
if ($Cleanup) {
    Write-Header "Cleanup — removing all LT* load test data"
    Invoke-Artisan "db:seed --class=LoadTestSeeder --action=cleanup"
    Write-Host "Done." -ForegroundColor Green
    exit 0
}

# ── Server check ──────────────────────────────────────────────────────────────
Assert-Server
Check-SessionDriver

# ── Seed ──────────────────────────────────────────────────────────────────────
if (-not $SkipSeed) {
    Write-Header "Seeding load test data (20 institutions × 1,000 students)"
    Write-Host "  This creates ~20,000 students + 20 tests. Takes ~30 seconds." -ForegroundColor Gray
    Invoke-Artisan "db:seed --class=LoadTestSeeder"
} else {
    Write-Host "Skipping seed (--SkipSeed)." -ForegroundColor Gray
}

# ── Determine VU count ────────────────────────────────────────────────────────
$peakVus = if ($Smoke) { '100' } else { '20000' }

# ── Run tests ─────────────────────────────────────────────────────────────────
$results = @{}

if ($Test -eq 'all' -or $Test -eq 'login') {
    Write-Header "Test 01 — Institution Admin Concurrent Login (20 VUs)"
    $results['login'] = Run-K6 '01_institution_login.js'
}

if ($Test -eq 'all' -or $Test -eq 'exam') {
    Write-Header "Test 02 — Full Student Exam Flow ($peakVus VUs)"
    $results['exam'] = Run-K6 '02_student_exam_flow.js' @{ PEAK_VUS = $peakVus }
}

if ($Test -eq 'all' -or $Test -eq 'burst') {
    Write-Header "Test 03 — Simultaneous Submit Burst ($peakVus VUs)"
    Write-Host "⚠  The burst test re-runs the full flow. If students already" -ForegroundColor Yellow
    Write-Host "   submitted in Test 02, re-seed first: .\run.ps1 -SkipSeed:`$false -Test burst" -ForegroundColor Yellow
    Write-Host "   Or run the burst test standalone (without Test 02 first)." -ForegroundColor Yellow
    $results['burst'] = Run-K6 '03_submit_burst.js' @{ PEAK_VUS = $peakVus }
}

# ── Final report ──────────────────────────────────────────────────────────────
Write-Header "Load Test Complete"

foreach ($kv in $results.GetEnumerator()) {
    $status = if ($kv.Value -eq 0) { '✓ PASS' } else { '✗ FAIL' }
    $color  = if ($kv.Value -eq 0) { 'Green'  } else { 'Red'   }
    Write-Host "  $status  Test: $($kv.Key)" -ForegroundColor $color
}

Write-Host ""
Write-Host "Data is still in the DB. To clean up run:" -ForegroundColor Gray
Write-Host "  .\tests\Load\run.ps1 -Cleanup" -ForegroundColor Gray
Write-Host ""

# Return non-zero if any test failed
if ($results.Values -contains 1) { exit 1 } else { exit 0 }
