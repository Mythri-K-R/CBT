# ExamSphere — Load-Testing Execution Runbook

**Platform:** Laravel 12 · PHP 8.3 · MySQL 8 · Redis 7 · Horizon  
**Audience:** QA engineer executing the test plan  
**Scope:** Smoke → 100 VUs → 500 VUs → 1,000 VUs  
**Last updated:** 2026-06-19

> Every command, KPI, threshold, and stop condition in this document is derived
> from the actual codebase — AppServiceProvider rate limiters, k6 scenario scripts,
> thresholds.js tier definitions, and infrastructure sizing in SERVER-SIZING.md.
> Nothing here is theoretical.

---

## Table of Contents

1. [Pre-flight: environment setup](#1-pre-flight-environment-setup)
2. [Critical blocker: rate limiter bypass](#2-critical-blocker-rate-limiter-bypass)
3. [Monitoring setup: terminal layout](#3-monitoring-setup-terminal-layout)
4. [Metric collection commands](#4-metric-collection-commands)
5. [Stage 0 — Smoke test (10 VUs)](#5-stage-0--smoke-test-10-vus)
6. [Stage 1 — 100 VUs](#6-stage-1--100-vus)
7. [Stage 2 — 500 VUs](#7-stage-2--500-vus)
8. [Stage 3 — 1,000 VUs](#8-stage-3--1000-vus)
9. [Universal stop conditions](#9-universal-stop-conditions)
10. [Post-test data collection](#10-post-test-data-collection)
11. [Failure triage guide](#11-failure-triage-guide)

---

## 1. Pre-flight: Environment Setup

Complete every item before starting Stage 0. Do not skip.

### 1.1 Software versions

```bash
php --version          # must be 8.3.x
php -m | grep redis    # must print "redis" (phpredis C extension, NOT predis)
redis-cli --version    # must be 7.x
mysql --version        # must be 8.0.x
k6 version             # must be 0.50+ (supports SharedArray, Tags)
```

If `php -m | grep redis` prints nothing, phpredis is not installed. Install it:

```bash
pecl install redis
# Then add extension=redis to /etc/php/8.3/fpm/conf.d/20-redis.ini
# And set REDIS_CLIENT=phpredis in .env
```

### 1.2 Seed load-test data

The k6 scripts use `LD-00001` → `LD-20000` roll numbers and `load-test-exam` slug.
These must exist in the database before any test runs.

```bash
# From d:/CBT/CBT-main/backend
php artisan db:seed --class=LoadTestSeeder

# Verify seeding succeeded:
php artisan tinker --execute="
    echo 'Students: ' . App\Models\Student::where('roll_number', 'like', 'LD-%')->count() . PHP_EOL;
    echo 'Test:     ' . App\Models\Test::where('slug', 'load-test-exam')->count() . PHP_EOL;
    echo 'TestLink: ' . App\Models\TestLink::whereHas('test', fn(\$q) => \$q->where('slug','load-test-exam'))->count() . PHP_EOL;
"
# Expected output:
#   Students: 20000
#   Test:     1
#   TestLink: 1
```

### 1.3 Application cache

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Confirm no boot-time errors (Redis + Queue enforcement in AppServiceProvider):
php artisan about
```

### 1.4 Horizon

```bash
# Start Horizon (leave running in a dedicated terminal throughout all tests)
php artisan horizon

# Verify all supervisors are up:
php artisan horizon:status
# Expected: status=running, all supervisors listed
```

### 1.5 Create results directory

```bash
mkdir -p tests/load/results
```

### 1.6 Set load-test environment variables

Create `tests/load/.env.loadtest` (never commit this file):

```ini
APP_ENV=local
APP_URL=http://localhost:8000
LOAD_TEST_BYPASS_THROTTLE=true
BASE_URL=http://localhost:8000/api
TEST_SLUG=load-test-exam
TEST_ID=1
ADMIN_EMAIL=admin@loadtest.local
ADMIN_PASSWORD=LoadTest@123
```

Export for the shell session running k6:

```bash
export $(grep -v '^#' tests/load/.env.loadtest | xargs)
```

---

## 2. Critical Blocker: Rate Limiter Bypass

**This must be resolved before running any test above 10 VUs.**

### The problem

Two rate limiters in `AppServiceProvider::configureRateLimiters()` will fire against
a single-machine k6 runner because all traffic originates from one IP:

| Limiter | Key | Limit | Fires at |
|---------|-----|-------|----------|
| `throttle:public` | per IP | 60/min | Timer-sync: 100 VUs → 200 calls/min from 1 IP |
| `throttle:login` | IP + username | 5/min | Login: any concurrent test |

The `login` bypass already exists in AppServiceProvider (line 154) but only covers
`throttle:login`. The `throttle:public` limiter (which covers `/timer`, `/identify`,
`/proctor-event`) has no bypass — it will throttle timer-sync calls at 100+ VUs,
driving `check_pass_rate` below the 99.9% threshold and failing every run.

### The fix

Apply this patch to `app/Providers/AppServiceProvider.php` in your **load-test
environment only** (APP_ENV=local AND LOAD_TEST_BYPASS_THROTTLE=true):

```php
private function configureRateLimiters(): void
{
    // ── LOAD-TEST BYPASS (local env + explicit opt-in only) ──────────────
    // Both conditions MUST be true — env var alone cannot activate in production
    // because app()->environment('local') is always false in production.
    if ($this->app->environment('local') && (bool) env('LOAD_TEST_BYPASS_THROTTLE', false)) {
        RateLimiter::for('login',    fn() => Limit::none());
        RateLimiter::for('public',   fn() => Limit::none());
        // exam-save: keep per-attempt_uuid but raise to 600/min (was 120/min)
        // so a fast k6 VU doesn't trigger it during session init bursts
        RateLimiter::for('exam-save', function (Request $request) {
            $key = 'save:' . ($request->input('attempt_uuid', $request->ip()));
            return Limit::perMinute(600)->by($key);
        });
        // exam-submit and exam-sync: unchanged (3/min and 10/min are fine per VU)
        RateLimiter::for('exam-submit', function (Request $request) {
            $key = 'submit:' . ($request->route('attemptUuid') ?? $request->ip());
            return Limit::perMinute(3)->by($key);
        });
        RateLimiter::for('exam-sync', function (Request $request) {
            $key = 'sync:' . ($request->route('attemptUuid') ?? $request->ip());
            return Limit::perMinute(10)->by($key);
        });
        RateLimiter::for('api', fn() => Limit::none());
        return; // skip production limiters below
    }

    // ── PRODUCTION LIMITERS (unchanged) ──────────────────────────────────
    RateLimiter::for('login', function (Request $request) {
        $key = mb_strtolower($request->input('username', '')) . '|' . $request->ip();
        return Limit::perMinute(5)->by($key)->response(function () {
            return response()->json(['message' => 'Too many login attempts.'], 429);
        });
    });
    // ... rest of production limiters unchanged
```

### Verify the bypass is active

```bash
# Restart FPM after the change
php artisan config:clear && php artisan config:cache

# Confirm bypass is active (should return 200, not 429):
for i in $(seq 1 70); do
    curl -s -o /dev/null -w "%{http_code}\n" \
        -X POST http://localhost:8000/api/test/join/load-test-exam/identify \
        -H "Content-Type: application/json" \
        -d '{"roll_number":"LD-00001"}'
done | sort | uniq -c
# Expected: 70 lines of "200", zero lines of "429"
```

---

## 3. Monitoring Setup: Terminal Layout

Open **6 terminal panes** (tmux recommended) before every test stage.
Keep them visible alongside the k6 output throughout each run.

```
┌─────────────────────────────┬─────────────────────────────┐
│  Pane 1: k6 command         │  Pane 2: Redis live stats   │
│  (changes per stage)        │  (stays running)            │
├─────────────────────────────┼─────────────────────────────┤
│  Pane 3: MySQL live stats   │  Pane 4: FPM status         │
│  (stays running)            │  (stays running)            │
├─────────────────────────────┼─────────────────────────────┤
│  Pane 5: Horizon queue      │  Pane 6: Laravel log tail   │
│  (stays running)            │  (stays running)            │
└─────────────────────────────┴─────────────────────────────┘
```

---

## 4. Metric Collection Commands

Copy each command into the corresponding pane and leave running.

### Pane 2 — Redis (live, every 5 seconds)

```bash
watch -n 5 '
echo "=== Redis Clients & Memory ==="
redis-cli -a "$REDIS_PASSWORD" INFO clients | grep -E "connected_clients|blocked_clients"
redis-cli -a "$REDIS_PASSWORD" INFO memory  | grep -E "used_memory_human|used_memory_rss_human|maxmemory_human"
echo ""
echo "=== Ops/s & Keyspace ==="
redis-cli -a "$REDIS_PASSWORD" INFO stats   | grep -E "instantaneous_ops_per_sec|total_connections_received|rejected_connections"
redis-cli -a "$REDIS_PASSWORD" INFO keyspace
echo ""
echo "=== Exam Queue Depths ==="
redis-cli -a "$REDIS_PASSWORD" LLEN "queues:results"
redis-cli -a "$REDIS_PASSWORD" LLEN "queues:analytics"
redis-cli -a "$REDIS_PASSWORD" LLEN "queues:monitoring"
echo ""
echo "=== Slow commands (last 5) ==="
redis-cli -a "$REDIS_PASSWORD" SLOWLOG GET 5
'
```

### Pane 3 — MySQL (live, every 5 seconds)

```bash
watch -n 5 '
echo "=== Connections ==="
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "
    SHOW STATUS WHERE Variable_name IN (
        \"Threads_connected\",\"Threads_running\",\"Threads_cached\",
        \"Max_used_connections\",\"Connection_errors_max_connections\"
    );" 2>/dev/null

echo ""
echo "=== Query throughput ==="
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "
    SHOW STATUS WHERE Variable_name IN (
        \"Questions\",\"Com_select\",\"Com_insert\",\"Com_update\",\"Com_delete\",
        \"Slow_queries\"
    );" 2>/dev/null

echo ""
echo "=== InnoDB locks ==="
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "
    SHOW STATUS WHERE Variable_name LIKE \"Innodb_row_lock%\";" 2>/dev/null

echo ""
echo "=== Active queries ==="
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "
    SELECT id, user, host, db, command, time, state, LEFT(info,80) AS query
    FROM information_schema.processlist
    WHERE command != \"Sleep\" AND time > 0
    ORDER BY time DESC LIMIT 10;" 2>/dev/null
'
```

### Pane 4 — PHP-FPM status (live, every 3 seconds)

```bash
watch -n 3 '
echo "=== Exam Pool ==="
curl -s "http://127.0.0.1/fpm-status?full" 2>/dev/null | \
    grep -E "pool:|process manager:|start time:|accepted conn:|listen queue:|max listen queue:|idle processes:|active processes:|total processes:|max active processes:|max children reached:|slow requests:"

echo ""
echo "=== Monitoring Pool ==="
curl -s "http://127.0.0.1/fpm-monitoring-status?full" 2>/dev/null | \
    grep -E "pool:|active processes:|idle processes:|max children reached:"
'
```

### Pane 5 — Horizon queue depth (live, every 10 seconds)

```bash
watch -n 10 '
echo "=== Horizon Status ==="
php artisan horizon:status 2>/dev/null

echo ""
echo "=== Queue depths (raw Redis) ==="
printf "results:     "; redis-cli -a "$REDIS_PASSWORD" LLEN "queues:results"    2>/dev/null
printf "analytics:   "; redis-cli -a "$REDIS_PASSWORD" LLEN "queues:analytics"  2>/dev/null
printf "notifications:"; redis-cli -a "$REDIS_PASSWORD" LLEN "queues:notifications" 2>/dev/null
printf "monitoring:  "; redis-cli -a "$REDIS_PASSWORD" LLEN "queues:monitoring" 2>/dev/null

echo ""
echo "=== Failed jobs ==="
redis-cli -a "$REDIS_PASSWORD" LLEN "failed_jobs" 2>/dev/null
php artisan horizon:list --status=failed 2>/dev/null | head -5
'
```

### Pane 6 — Laravel log (tail, errors only)

```bash
tail -f storage/logs/laravel.log | grep -E "ERROR|CRITICAL|WARNING|Slow database"
```

### One-shot snapshot command (run before and after each stage)

```bash
snapshot() {
  local LABEL="${1:-snapshot}"
  local TS=$(date +%Y%m%d-%H%M%S)
  local OUT="tests/load/results/infra-${LABEL}-${TS}.txt"

  {
    echo "=== SNAPSHOT: $LABEL @ $(date) ==="
    echo ""
    echo "--- PHP-FPM ---"
    curl -s "http://127.0.0.1/fpm-status?full" 2>/dev/null

    echo ""
    echo "--- Redis INFO ---"
    redis-cli -a "$REDIS_PASSWORD" INFO all 2>/dev/null

    echo ""
    echo "--- MySQL STATUS ---"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" \
        -e "SHOW GLOBAL STATUS;" 2>/dev/null

    echo ""
    echo "--- MySQL INNODB STATUS ---"
    mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" \
        -e "SHOW ENGINE INNODB STATUS\G" 2>/dev/null

    echo ""
    echo "--- Queue depths ---"
    for q in results analytics notifications monitoring default emails reports; do
        printf "%-15s %s\n" "$q:" \
            "$(redis-cli -a "$REDIS_PASSWORD" LLEN "queues:$q" 2>/dev/null)"
    done

    echo ""
    echo "--- Server resources ---"
    free -m
    vmstat 1 3
    df -h /

  } > "$OUT"

  echo "Snapshot saved: $OUT"
}
```

Save this function in your shell session (`source` it from a file, or paste into each terminal).

---

## 5. Stage 0 — Smoke Test (10 VUs)

**Purpose:** Verify the application starts, routes respond, data is seeded, Redis and
MySQL are reachable, and Horizon processes jobs. Not a performance test.

**Duration:** ~8 minutes  
**Scenario:** Full lifecycle (identify → start → answer loop → submit → poll result)

### 5.1 Pre-stage snapshot

```bash
snapshot "pre-smoke"
```

### 5.2 Run

```bash
k6 run \
  --env USERS=10 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --env TEST_ID="${TEST_ID}" \
  --env EXAM_DURATION_SECONDS=300 \
  --out json=tests/load/results/smoke-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/full-exam-lifecycle.js
```

### 5.3 Expected KPIs

| Metric | Expected | Threshold (FAIL if exceeded) |
|--------|----------|------------------------------|
| save-response P50 | 15–30 ms | > 50 ms |
| save-response P95 | 40–80 ms | > 150 ms |
| save-response P99 | 80–150 ms | > 300 ms |
| timer-sync P95 | 20–50 ms | > 105 ms |
| exam-start P95 | 80–200 ms | > 450 ms |
| exam-submit P95 | 50–150 ms | > 300 ms |
| HTTP 5xx error rate | 0% | > 0.1% |
| check_pass_rate | 100% | < 99.9% |

### 5.4 Infrastructure metrics to watch

| Component | Metric | Expected at 10 VUs | Alert if |
|-----------|--------|--------------------|----------|
| FPM | active processes | 2–5 | > 20 |
| MySQL | Threads_running | 1–3 | > 10 |
| Redis | connected_clients | 5–15 | > 100 |
| Redis | instantaneous_ops_per_sec | 20–100 | > 500 |
| Redis | used_memory | < 100 MB | > 500 MB |
| Horizon | results queue depth | 0–10 | > 50 |
| Server | CPU % | < 10% | > 50% |
| Server | RAM free | > 80% | < 50% free |

### 5.5 What to verify manually during this stage

1. In Pane 6 (Laravel log): zero ERROR or CRITICAL lines
2. In Pane 5 (Horizon): results queue drains to 0 within 60 seconds of submit
3. In Pane 3 (MySQL): `Threads_running` never exceeds 5
4. k6 output: all 10 VUs complete the full lifecycle (result_poll shows `processing: false`)

### 5.6 Post-stage snapshot

```bash
snapshot "post-smoke"
# Wait 2 minutes for Horizon to drain, then:
php artisan queue:monitor redis:results --max=5
# Expected: "0 jobs pending on redis:results"
```

### 5.7 Stop conditions (abort immediately if any trigger)

- Any HTTP 500 response (check k6 output: `http_req_failed rate > 0`)
- `max children reached` in FPM log
- Redis `WRONGTYPE` or `OOM` errors in Pane 6
- MySQL `ERROR 1040` (too many connections)
- k6 exits with `FAIL` on any threshold

### 5.8 Pass criteria (required before Stage 1)

- [ ] All k6 thresholds: **GREEN** (no FAIL in k6 output)
- [ ] `check_pass_rate` > 99.9%
- [ ] Zero HTTP 5xx in k6 output (`http_req_failed rate = 0`)
- [ ] Zero ERROR/CRITICAL in `storage/logs/laravel.log`
- [ ] Horizon results queue drained to 0 within 2 minutes of test end
- [ ] FPM `max children reached` counter = 0 in `/fpm-status`
- [ ] All 10 VUs received `processing: false` on result poll

**If any item is unchecked: DO NOT proceed to Stage 1. Triage first (see §11).**

---

## 6. Stage 1 — 100 VUs

**Purpose:** Validate basic concurrency. Confirm rate limiter bypass works, session
tokens are correctly issued and reused, and Horizon drains small scoring queues.

**Duration:** ~20 minutes total across 3 scenarios  
**Scenarios run in order:** 02 (start storm) → 03 (save storm) → 05 (submit storm)

> **Rate limiter note:** The `throttle:public` bypass (§2) MUST be active.
> At 100 VUs from one IP: timer syncs = 100 × 2/min = 200/min per IP.
> Without bypass: 70% of timer checks return 429 → `check_pass_rate` fails.

### 6.1 Pre-stage snapshot

```bash
snapshot "pre-100vu"
# Confirm Redis queue depths are 0 before starting:
redis-cli -a "$REDIS_PASSWORD" LLEN "queues:results"    # must be 0
redis-cli -a "$REDIS_PASSWORD" LLEN "queues:analytics"  # must be 0
```

### 6.2 Run: Scenario 02 — Exam Start Storm (100 VUs)

Tests identify + start under burst. DB-write intensive.

```bash
k6 run \
  --env USERS=100 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --out json=tests/load/results/100vu-start-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/02-exam-start-storm.js
```

**Wait 2 minutes after this completes before running Scenario 03.**
(Lets Horizon process the ExamStarted monitoring jobs from the burst.)

### 6.3 Run: Scenario 03 — Answer Save Storm (100 VUs, 5-minute sustain)

```bash
k6 run \
  --env USERS=100 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --out json=tests/load/results/100vu-save-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/03-answer-save-storm.js
```

### 6.4 Export attempts for Scenario 05

```bash
php artisan tinker --execute="
    \$uuids = App\Models\TestAttempt::where('test_id', 1)
        ->where('status', 'in_progress')
        ->select(['uuid'])
        ->limit(100)
        ->get()
        ->map(fn(\$a) => ['uuid' => \$a->uuid, 'questions' => range(1,180)])
        ->toJson();
    file_put_contents('tests/load/k6/data/attempts.json', \$uuids);
    echo count(json_decode(\$uuids)) . ' attempts exported';
"
```

### 6.5 Run: Scenario 05 — Submit Storm (100 VUs)

```bash
k6 run \
  --env USERS=100 \
  --env BASE_URL="${BASE_URL}" \
  --out json=tests/load/results/100vu-submit-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/05-exam-submit-storm.js
```

### 6.6 Expected KPIs

#### Scenario 02 — Start Storm

| Metric | Expected | k6 FAIL threshold |
|--------|----------|-------------------|
| identify P95 | 40–80 ms | — (not in thresholds.js) |
| exam-start P95 | 150–300 ms | > 450 ms |
| exam-start P99 | 300–500 ms | > 900 ms |
| check_pass_rate | > 99.9% | < 99.9% |

#### Scenario 03 — Save Storm

| Metric | Expected | k6 FAIL threshold |
|--------|----------|-------------------|
| save-response P50 | 20–40 ms | > 50 ms |
| save-response P95 | 60–100 ms | > 150 ms |
| save-response P99 | 100–200 ms | > 300 ms |
| timer-sync P95 | 30–70 ms | > 105 ms |
| 429 rate (save) | < 5% | excluded from error metric |
| HTTP error rate (5xx) | 0% | > 0.1% |
| check_pass_rate | > 99.9% | < 99.9% |

#### Scenario 05 — Submit Storm

| Metric | Expected | k6 FAIL threshold |
|--------|----------|-------------------|
| exam-submit P95 | 80–200 ms | > 300 ms |
| exam-submit P99 | 150–400 ms | > 600 ms |
| result-poll until `processing:false` | < 2 min | — |
| `submit_errors` counter | 0 | > 10 |
| `already_submitted` counter | 0–5 | — |

### 6.7 Infrastructure metrics to watch during Scenario 03 (save storm)

| Component | Metric | Expected at 100 VUs | Alert if |
|-----------|--------|---------------------|----------|
| FPM | active processes | 5–25 | > 60 (75% of default 80 cap) |
| FPM | max children reached | 0 | > 0 |
| MySQL | Threads_running | 3–10 | > 30 |
| MySQL | Innodb_row_lock_waits | < 10/s | > 50/s |
| Redis | connected_clients | 10–50 | > 200 |
| Redis | instantaneous_ops_per_sec | 500–1,000 | > 5,000 |
| Redis | used_memory | < 200 MB | > 1 GB |
| Horizon | results queue depth | 0–20 | > 100 |
| Horizon | results queue wait | < 1 s | > 3 s (alert threshold) |
| Server | CPU % | 10–30% | > 70% sustained |
| Server | RAM free | > 60% | < 25% free |

### 6.8 Infrastructure metrics during Scenario 05 (submit storm)

| Component | Metric | Expected | Alert if |
|-----------|--------|----------|----------|
| Horizon | results queue depth | peaks at 50–100 jobs | > 500 |
| Horizon | results queue drain time | < 3 min after k6 stops | > 10 min |
| MySQL | Threads_running during scoring | 5–20 | > 50 |
| Redis | `exam:submit:*` lock keys | 0–10 at a time | > 50 concurrent |

### 6.9 Post-stage snapshot and drain check

```bash
snapshot "post-100vu"

# Monitor results queue drain (run immediately after submit storm ends):
for i in $(seq 1 12); do
    DEPTH=$(redis-cli -a "$REDIS_PASSWORD" LLEN "queues:results")
    echo "$(date +%H:%M:%S)  results queue: $DEPTH"
    sleep 30
done
# Expected: depth reaches 0 within 6 minutes (12 × 30s)
```

### 6.10 Stop conditions

- HTTP 5xx rate > 1% for any 30-second window
- FPM `max children reached` > 0 (means workers exhausted)
- `Innodb_row_lock_waits` increasing monotonically for > 60 seconds
- Redis `rejected_connections` > 0
- Horizon results queue depth > 500 and not draining
- Any CRITICAL in Laravel log

### 6.11 Pass criteria (required before Stage 2)

- [ ] All k6 thresholds for all 3 scenarios: **GREEN**
- [ ] `check_pass_rate` ≥ 99.9% across all scenarios
- [ ] Zero HTTP 5xx errors across all scenarios
- [ ] FPM `max children reached` = 0 at end of each scenario
- [ ] MySQL `Innodb_row_lock_waits` stable (not climbing) during save storm
- [ ] Horizon results queue fully drained within 5 minutes of submit storm end
- [ ] Zero `submit_errors` in Scenario 05 output
- [ ] Zero ERROR/CRITICAL in Laravel log during entire stage
- [ ] Redis `rejected_connections` = 0 throughout

---

## 7. Stage 2 — 500 VUs

**Purpose:** Validate sustained load over 15 minutes. Identify memory leaks, Redis
connection accumulation, and gradual latency degradation (the "slow boil" problem).
This is the first test that stresses the FPM pool meaningfully.

**Duration:** ~25 minutes (Scenario 04: 3m ramp + 15m sustain + 2m down + 5m post)  
**Scenario:** 04 — Auto-Save + Timer Sync Storm (sustained)

**Required FPM config:** `pm.max_children` ≥ 150  
Verify: `curl -s http://127.0.0.1/fpm-status | grep "max children"`

### 7.1 Pre-stage preparation

```bash
# Clear any leftover attempts from Stage 1 to avoid UUID collisions:
php artisan tinker --execute="
    App\Models\TestAttempt::where('test_id', 1)
        ->where('status', 'in_progress')
        ->update(['status' => 'completed', 'submitted_at' => now()]);
    echo 'Cleared in-progress attempts';
"

# Warm Redis exam state for 500 students:
php artisan examsphere:warmup-exam-cache --test-id=1

snapshot "pre-500vu"
```

### 7.2 Run: Scenario 02 — Start Storm first (500 VUs)

All 500 students must have active attempts before the save storm.

```bash
k6 run \
  --env USERS=500 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --out json=tests/load/results/500vu-start-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/02-exam-start-storm.js

# Wait 60 seconds for Horizon to process start events:
sleep 60
```

### 7.3 Run: Scenario 04 — Auto-Save + Timer Storm (500 VUs, 15-min sustain)

```bash
k6 run \
  --env USERS=500 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --out json=tests/load/results/500vu-autosave-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/04-auto-save-storm.js
```

### 7.4 Expected KPIs — Scenario 04 at 500 VUs

Throughput at 500 VUs: **50 saves/s + 17 timer syncs/s = ~67 req/s sustained**

| Metric | Minutes 0–3 (ramp) | Minutes 3–18 (sustain) | FAIL threshold |
|--------|-------------------|----------------------|----------------|
| save-response P50 | 20–60 ms | **stable** 30–60 ms | > 75 ms |
| save-response P95 | 60–150 ms | **stable** 80–150 ms | > 200 ms |
| save-response P99 | 100–300 ms | **stable** 150–300 ms | > 500 ms |
| timer-sync P95 | 40–100 ms | **stable** 50–100 ms | > 140 ms |
| `save_errors` counter | — | 0 | > 5 |
| `timer_errors` counter | — | 0 | > 5 |
| `token_refreshes` counter | ~500 (init) | 0–10/min | > 50/min sustained |
| HTTP error rate | 0% | 0% | > 0.1% |
| check_pass_rate | > 99.9% | > 99.9% | < 99.9% |

**Key word: "stable".** Latency that starts at 80ms P95 and climbs to 160ms P95
over 15 minutes indicates a memory or connection leak. This is a STOP condition.

### 7.5 Infrastructure metrics — 15-minute sustained view

Record these at **T+0, T+5, T+10, T+15 minutes** into the sustain phase.

| Component | Metric | T+0 | T+5 | T+10 | T+15 | FAIL if |
|-----------|--------|-----|-----|------|------|---------|
| FPM | active processes | — | — | — | — | any reading > 120 (80% of 150) |
| FPM | max children reached | 0 | 0 | 0 | 0 | any > 0 |
| MySQL | Threads_running | — | — | — | — | > 50 |
| MySQL | Slow_queries delta | — | — | — | — | > 10/min |
| Redis | connected_clients | — | — | — | — | > 300 |
| Redis | instantaneous_ops_per_sec | — | — | — | — | > 6,000 |
| Redis | used_memory_rss_human | — | — | — | — | growing > 100MB in 5 min |
| Horizon | results queue depth | — | — | — | — | > 200 |
| Server | CPU % | — | — | — | — | > 60% sustained |
| Server | RAM free (MB) | — | — | — | — | declining > 500MB in 5 min |

**Fill in the blanks during the actual test.** A table where T+0 through T+15
readings are stable (not monotonically increasing) confirms no leak.

### 7.6 Record latency stability (critical at this stage)

Run this in a separate pane during the sustain phase:

```bash
# Sample k6 output every 60 seconds to track P95 drift
# k6 emits to stdout — use its built-in --console-output or check the JSON output after.
# During the test, watch for lines like:
#   http_req_duration............: avg=XXms med=XXms p(90)=XXms p(95)=XXms
# If P95 increases more than 50ms over 10 minutes: STOP CONDITION
```

### 7.7 Export attempts and run Scenario 05 (Submit Storm at 500 VUs)

After Scenario 04 completes:

```bash
php artisan tinker --execute="
    \$uuids = App\Models\TestAttempt::where('test_id', 1)
        ->where('status', 'in_progress')
        ->select(['uuid'])
        ->limit(500)
        ->get()
        ->map(fn(\$a) => ['uuid' => \$a->uuid, 'questions' => range(1,180)])
        ->toJson();
    file_put_contents('tests/load/k6/data/attempts.json', \$uuids);
    echo count(json_decode(\$uuids)) . ' attempts exported';
"

k6 run \
  --env USERS=500 \
  --env BASE_URL="${BASE_URL}" \
  --out json=tests/load/results/500vu-submit-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/05-exam-submit-storm.js
```

**Expected during submit storm (500 VUs):**

| Metric | Expected | FAIL threshold |
|--------|----------|----------------|
| exam-submit P95 | 100–300 ms | > 400 ms |
| results queue peak depth | 100–300 jobs | > 1,000 |
| results queue drain time | < 8 minutes | > 15 minutes |
| MySQL Threads_running (during scoring) | 10–30 | > 60 |

### 7.8 Post-stage snapshot and leak check

```bash
snapshot "post-500vu"

# Check for FPM worker memory growth (should be controlled by pm.max_requests=500):
ps aux | grep php-fpm | awk '{print $6}' | sort -n | tail -5
# Values in KB. Acceptable: 40,000–100,000 KB (40–100 MB per worker)
# FAIL if any worker > 200,000 KB (200 MB) — indicates pm.max_requests not working

# Check Redis memory didn't grow during the test:
redis-cli -a "$REDIS_PASSWORD" INFO memory | grep used_memory_human
# Compare with snapshot taken before Stage 2 started. Delta should be < 500 MB.

# Check for leaked exam state keys:
redis-cli -a "$REDIS_PASSWORD" KEYS "exam:state:*" | wc -l
# Should decrease after test ends (TTLs expiring). If growing: cache invalidation broken.
```

### 7.9 Stop conditions

- P95 save-response **climbs** > 50 ms over any 5-minute window (leak detected)
- FPM `max children reached` > 0
- Redis `used_memory_rss_human` growing > 200 MB over 5 minutes (no plateau)
- MySQL `Innodb_row_lock_current_waits` > 20 sustained for > 60 seconds
- `token_refreshes` counter growing > 50/min in the sustain phase (session expiry cascade)
- Any HTTP 5xx

### 7.10 Pass criteria (required before Stage 3)

- [ ] All k6 thresholds for all 3 scenarios: **GREEN**
- [ ] `check_pass_rate` ≥ 99.9% across all scenarios
- [ ] save-response P95 drift < 50 ms from T+5 to T+15 (stable, not climbing)
- [ ] FPM `max children reached` = 0 throughout
- [ ] FPM worker RSS memory < 150 MB per worker at test end
- [ ] Redis `used_memory` delta across 15-minute sustain < 500 MB
- [ ] Redis `rejected_connections` = 0 throughout
- [ ] `token_refreshes` during sustain phase < 10/min (sessions not expiring unexpectedly)
- [ ] Horizon results queue drained within 10 minutes of submit storm end
- [ ] Zero ERROR/CRITICAL in Laravel log
- [ ] MySQL `Slow_queries` rate < 5/min during save storm

---

## 8. Stage 3 — 1,000 VUs

**Purpose:** Full 1k-tier validation. This is the production readiness gate for
exams up to 1,000 concurrent students. All measurements must meet the exact
thresholds defined in `tests/load/k6/shared/thresholds.js` (small tier: ≤1k users).

**Duration:** ~55 minutes total  
**Scenarios run in order:** 02 → 04 (15min sustain) → 05 → full lifecycle

**Required FPM config:** `pm.max_children` ≥ 80 (1k tier from SERVER-SIZING.md)  
**Required Horizon config:** `HORIZON_RESULTS_MAX_PROCS` ≥ 5

### 8.1 Pre-stage preparation

```bash
# Full reset: clear all in-progress attempts from previous stages
php artisan tinker --execute="
    App\Models\TestAttempt::where('test_id', 1)->delete();
    echo 'All test attempts cleared for clean run';
"

# Flush exam state cache (only DB0 — never flushes production cache)
redis-cli -a "$REDIS_PASSWORD" -n 0 EVAL "
    local keys = redis.call('keys', 'exam:state:*')
    for i=1,#keys do redis.call('del', keys[i]) end
    local mon = redis.call('keys', 'mon:*')
    for i=1,#mon do redis.call('del', mon[i]) end
    return #keys + #mon
" 0

# Purge Horizon failed jobs from previous stages:
php artisan horizon:clear

# Scale Horizon results supervisor for 1k tier:
# Set in .env or environment:
export HORIZON_RESULTS_MIN_PROCS=2
export HORIZON_RESULTS_MAX_PROCS=5
# Then restart Horizon:
php artisan horizon:terminate && sleep 3 && php artisan horizon &

snapshot "pre-1000vu"
```

### 8.2 Run: Scenario 02 — Start Storm (1,000 VUs)

```bash
k6 run \
  --env USERS=1000 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --out json=tests/load/results/1000vu-start-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/02-exam-start-storm.js
```

**Expected during start storm:**

| Metric | Expected | FAIL threshold |
|--------|----------|----------------|
| identify P95 | 50–150 ms | — |
| exam-start P95 | 200–450 ms | > 450 ms |
| exam-start P99 | 400–900 ms | > 900 ms |
| MySQL Threads_running (peak during start) | 20–50 | > 80 |
| Redis HMSET ops/s (during warmup) | 500–2,000 | — |

Wait 90 seconds after completion before the next scenario.

### 8.3 Run: Scenario 04 — Auto-Save + Timer Storm (1,000 VUs, 15-min sustain)

Throughput: **100 saves/s + 33 timer syncs/s = ~133 req/s sustained**

```bash
k6 run \
  --env USERS=1000 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --out json=tests/load/results/1000vu-autosave-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/04-auto-save-storm.js
```

**Expected KPIs — the definitive 1k-tier thresholds (from thresholds.js `small` tier):**

| Metric | Expected | k6 PASS threshold | k6 FAIL if |
|--------|----------|-------------------|------------|
| save-response P50 | 30–50 ms | < 50 ms | ≥ 50 ms |
| save-response P95 | 80–130 ms | < 150 ms | ≥ 150 ms |
| save-response P99 | 130–250 ms | < 300 ms | ≥ 300 ms |
| timer-sync P95 | 50–90 ms | < 105 ms | ≥ 105 ms |
| timer-sync P99 | 80–200 ms | < 300 ms | ≥ 300 ms |
| HTTP error rate (5xx only) | 0% | < 0.1% | ≥ 0.1% |
| check_pass_rate | > 99.9% | > 99.9% | ≤ 99.9% |

### 8.4 Infrastructure targets at 1,000 VUs (15-minute sustain)

These numbers define readiness — they must hold for the ENTIRE 15-minute window, not just a snapshot.

| Component | Metric | Target | STOP if |
|-----------|--------|--------|---------|
| FPM exam pool | active processes | 30–65 | > 72 (90% of 80) |
| FPM exam pool | max children reached | 0 | > 0 |
| FPM exam pool | slow requests | 0 | > 5/min |
| MySQL | Threads_running | 5–20 | > 50 |
| MySQL | Innodb_row_lock_waits | < 5/s | > 30/s |
| MySQL | Slow_queries rate | < 2/min | > 20/min |
| MySQL | Com_update rate | 80–120/s | > 300/s |
| Redis | connected_clients | 50–120 | > 400 |
| Redis | instantaneous_ops_per_sec | 1,500–3,000 | > 8,000 |
| Redis | used_memory | < 500 MB | > 2 GB |
| Redis | rejected_connections | 0 | > 0 |
| Horizon | results queue depth | 0–5 | > 100 |
| Horizon | analytics queue depth | 0–50 | > 500 |
| Server | CPU % (all cores) | 20–50% | > 80% sustained 60s |
| Server | RAM free | > 40% | < 20% free |
| Server | Disk I/O wait | < 5% | > 20% |

### 8.5 Record timing — 5-minute intervals

At each checkpoint during the 15-minute sustain phase, record in your test log:

```
T+5 min:  FPM active=___, MySQL threads=___, Redis ops/s=___, save P95=___ms
T+10 min: FPM active=___, MySQL threads=___, Redis ops/s=___, save P95=___ms
T+15 min: FPM active=___, MySQL threads=___, Redis ops/s=___, save P95=___ms
```

**Rule:** Any metric that increases more than 20% from T+5 to T+15 = leak. STOP and investigate.

### 8.6 Export attempts and run Scenario 05 — Submit Storm (1,000 VUs)

```bash
php artisan tinker --execute="
    \$uuids = App\Models\TestAttempt::where('test_id', 1)
        ->where('status', 'in_progress')
        ->select(['uuid'])
        ->limit(1000)
        ->get()
        ->map(fn(\$a) => ['uuid' => \$a->uuid, 'questions' => range(1,180)])
        ->toJson();
    file_put_contents('tests/load/k6/data/attempts.json', \$uuids);
    echo count(json_decode(\$uuids)) . ' attempts exported';
"

k6 run \
  --env USERS=1000 \
  --env BASE_URL="${BASE_URL}" \
  --out json=tests/load/results/1000vu-submit-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/scenarios/05-exam-submit-storm.js
```

**Submit storm expected:**

| Metric | Expected | FAIL threshold |
|--------|----------|----------------|
| exam-submit P95 | 100–250 ms | > 300 ms |
| exam-submit P99 | 200–500 ms | > 600 ms |
| results queue peak depth | 200–600 jobs | > 2,000 |
| results queue drain time | < 5 min with 5 workers | > 15 min |
| `submit_errors` counter | 0 | > 10 |
| MySQL Threads_running (scoring peak) | 15–40 | > 70 |

**Watch Horizon during submit storm:**

```bash
# In a separate shell — poll every 15 seconds:
for i in $(seq 1 20); do
    DEPTH=$(redis-cli -a "$REDIS_PASSWORD" LLEN "queues:results")
    TS=$(date +%H:%M:%S)
    echo "$TS  results queue depth: $DEPTH"
    sleep 15
done
```

### 8.7 Run: Full Lifecycle Test (1,000 VUs)

This is the final and most realistic test. Each VU runs the complete
identify → start → 180 questions → submit → result-poll journey.
Duration: ~45 minutes (5m ramp + 30m exam + 5m close).

Reset attempts again before running:

```bash
php artisan tinker --execute="
    App\Models\TestAttempt::where('test_id', 1)->delete();
    echo 'Attempts cleared for lifecycle test';
"
redis-cli -a "$REDIS_PASSWORD" -n 0 EVAL "
    local keys = redis.call('keys', 'exam:state:*')
    for i=1,#keys do redis.call('del', keys[i]) end
    return #keys
" 0
```

```bash
k6 run \
  --env USERS=1000 \
  --env BASE_URL="${BASE_URL}" \
  --env TEST_SLUG="${TEST_SLUG}" \
  --env TEST_ID="${TEST_ID}" \
  --env EXAM_DURATION_SECONDS=1800 \
  --out json=tests/load/results/1000vu-lifecycle-$(date +%Y%m%d-%H%M%S).json \
  tests/load/k6/full-exam-lifecycle.js
```

**Full lifecycle expected — 1k tier (thresholds.js `small` tier):**

| Phase | Metric | Expected | FAIL threshold |
|-------|--------|----------|----------------|
| Identify | P95 | 40–100 ms | — |
| Exam start | P95 | 200–450 ms | > 450 ms |
| Save response | P50 | 30–50 ms | > 50 ms |
| Save response | P95 | 80–150 ms | > 150 ms |
| Timer sync | P95 | 50–105 ms | > 105 ms |
| Exam submit | P95 | 100–300 ms | > 300 ms |
| Result poll (`processing:false`) | time | < 2 min | > 5 min |
| Overall error rate | — | 0% | > 0.1% |
| Overall check_pass_rate | — | > 99.9% | < 99.9% |

### 8.8 Post-stage snapshot and validation

```bash
snapshot "post-1000vu"

# 1. Verify all results scored correctly:
php artisan tinker --execute="
    \$total   = App\Models\TestAttempt::where('test_id', 1)->count();
    \$scored  = App\Models\TestAttempt::where('test_id', 1)->where('status','completed')->count();
    \$pending = App\Models\TestAttempt::where('test_id', 1)->where('status','in_progress')->count();
    echo \"Total: \$total | Scored: \$scored | Still pending: \$pending\";
"

# 2. Check for any failed Horizon jobs:
php artisan horizon:list --status=failed | head -20
redis-cli -a "$REDIS_PASSWORD" LLEN "failed_jobs"

# 3. Check for InnoDB lock incidents:
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "
    SELECT Variable_name, Variable_value
    FROM information_schema.GLOBAL_STATUS
    WHERE Variable_name IN (
        'Innodb_row_lock_waits',
        'Innodb_row_lock_time_max',
        'Innodb_row_lock_time_avg'
    );"

# 4. Check slow query count accumulated during tests:
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "
    SHOW GLOBAL STATUS LIKE 'Slow_queries';"

# 5. Verify circuit breakers stayed closed:
php artisan examsphere:circuit-status
# Expected: all circuits = CLOSED
```

### 8.9 Stop conditions

- save-response P95 > 150 ms for any 2-minute consecutive window
- FPM `max children reached` > 0
- MySQL `Innodb_row_lock_time_max` > 10,000 ms (10s lock wait)
- Redis `rejected_connections` > 0
- Horizon results queue depth > 2,000 and not decreasing
- Any circuit breaker trips to OPEN (check `examsphere:circuit-status`)
- `submit_errors` counter > 10 in Scenario 05
- Server CPU > 80% sustained for > 60 seconds
- Server RAM < 20% free (risk of OOM kill)

### 8.10 Pass criteria — 1k-tier GO/NO-GO

**All items must be checked before declaring 1k-tier PASS.**

#### k6 threshold results

- [ ] `http_req_duration{name:save_response}` p(50) < 50 ms — **GREEN**
- [ ] `http_req_duration{name:save_response}` p(95) < 150 ms — **GREEN**
- [ ] `http_req_duration{name:save_response}` p(99) < 300 ms — **GREEN**
- [ ] `http_req_duration{name:timer_sync}` p(95) < 105 ms — **GREEN**
- [ ] `http_req_duration{name:exam_start}` p(95) < 450 ms — **GREEN**
- [ ] `http_req_duration{name:exam_submit}` p(95) < 300 ms — **GREEN**
- [ ] `http_req_failed` rate < 0.001 (0.1%) — **GREEN**
- [ ] `check_pass_rate` > 0.999 (99.9%) — **GREEN**

#### Infrastructure

- [ ] FPM `max children reached` = 0 across all scenarios
- [ ] FPM worker memory: no worker > 150 MB at test end
- [ ] MySQL: zero `Innodb_deadlocks` increment during test
- [ ] MySQL: `Slow_queries` < 100 total across all scenarios
- [ ] Redis: `rejected_connections` = 0 throughout all scenarios
- [ ] Redis: exam state keys (`KEYS exam:state:*`) decreasing post-test (TTLs working)
- [ ] All Horizon circuit breakers: CLOSED
- [ ] Zero failed Horizon jobs
- [ ] All 1,000 attempts scored (`status=completed`)

#### Stability

- [ ] save-response P95 drift < 30 ms between T+5 and T+15 during sustain
- [ ] FPM active processes stable (not climbing) during 15-minute sustain
- [ ] Redis `used_memory` stable (< 100 MB delta over sustain period)
- [ ] Server RAM free: stable (not declining > 500 MB over sustain period)

---

## 9. Universal Stop Conditions

Stop the k6 test **immediately** (Ctrl+C) and do NOT advance to the next stage
if any of the following occur during any stage:

| # | Condition | How to detect | Severity |
|---|-----------|---------------|----------|
| 1 | HTTP 5xx error rate > 5% for any 30-second window | k6 real-time output: `http_req_failed` | CRITICAL |
| 2 | `max children reached` > 0 in FPM status | Pane 4 watch | CRITICAL |
| 3 | OOM killer activates | `dmesg -T | grep oom` | CRITICAL |
| 4 | MySQL `ERROR 1040 Too many connections` | Pane 6 Laravel log | CRITICAL |
| 5 | Redis `rejected_connections` > 0 | Pane 2 Redis watch | CRITICAL |
| 6 | Any circuit breaker trips OPEN | Pane 5 / `circuit-status` | CRITICAL |
| 7 | save-response P95 climbs > 100ms over 5 minutes (sustained increase) | k6 output | HIGH |
| 8 | MySQL `Innodb_row_lock_time_max` > 10,000ms | Pane 3 MySQL watch | HIGH |
| 9 | Horizon results queue depth > 2,000 and not decreasing | Pane 5 | HIGH |
| 10 | Server RAM free < 10% | `free -m` | HIGH |
| 11 | Server CPU > 90% for > 60 seconds | `top` or Pane 4 | HIGH |
| 12 | CRITICAL log entry in Laravel log | Pane 6 | HIGH |

After stopping, run `snapshot "emergency-stop"` to capture the state for triage.

---

## 10. Post-Test Data Collection

Run after every stage completes (pass or fail). This data is the basis for the
GO/NO-GO decision and future comparisons.

### 10.1 Collect all k6 results

```bash
# k6 JSON output is already saved to tests/load/results/ during each run.
# Summarise the key thresholds from each file:

for f in tests/load/results/*.json; do
    echo "=== $f ==="
    # Extract threshold pass/fail summary
    jq '.metrics | to_entries
        | map(select(.key | test("http_req_duration|check_pass_rate|http_req_failed")))
        | map({metric: .key, values: .value.values})' "$f" 2>/dev/null | head -50
    echo ""
done
```

### 10.2 Collect Redis statistics

```bash
# Full Redis INFO snapshot (saved to file):
redis-cli -a "$REDIS_PASSWORD" INFO all > tests/load/results/redis-final-$(date +%Y%m%d-%H%M%S).txt

# Slow log dump:
redis-cli -a "$REDIS_PASSWORD" SLOWLOG GET 50 > tests/load/results/redis-slowlog-$(date +%Y%m%d-%H%M%S).txt

# Key distribution by prefix:
redis-cli -a "$REDIS_PASSWORD" --scan --pattern "exam:state:*" | wc -l
redis-cli -a "$REDIS_PASSWORD" --scan --pattern "mon:*"        | wc -l
redis-cli -a "$REDIS_PASSWORD" --scan --pattern "queues:*"     | wc -l
```

### 10.3 Collect MySQL statistics

```bash
# Global status snapshot:
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" \
    -e "SHOW GLOBAL STATUS;" > tests/load/results/mysql-status-$(date +%Y%m%d-%H%M%S).txt

# InnoDB metrics:
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" \
    -e "SHOW ENGINE INNODB STATUS\G" > tests/load/results/mysql-innodb-$(date +%Y%m%d-%H%M%S).txt

# Slow query count:
mysql -u "$DB_USERNAME" -p"$DB_PASSWORD" \
    -e "SELECT Variable_name, Variable_value
        FROM information_schema.GLOBAL_STATUS
        WHERE Variable_name IN (
            'Slow_queries','Uptime',
            'Innodb_row_lock_waits','Innodb_row_lock_time_avg',
            'Innodb_deadlocks','Max_used_connections',
            'Threads_created','Connection_errors_max_connections'
        );" > tests/load/results/mysql-kpis-$(date +%Y%m%d-%H%M%S).txt
```

### 10.4 Collect Horizon statistics

```bash
# Horizon job metrics via Redis (Horizon stores metrics in Redis):
php artisan horizon:status > tests/load/results/horizon-status-$(date +%Y%m%d-%H%M%S).txt

# Failed jobs:
php artisan horizon:list --status=failed > tests/load/results/horizon-failed-$(date +%Y%m%d-%H%M%S).txt

# Queue depths at collection time:
for q in results analytics notifications monitoring default emails reports; do
    printf "%-15s %s\n" "$q:" "$(redis-cli -a "$REDIS_PASSWORD" LLEN "queues:$q")"
done > tests/load/results/queue-depths-$(date +%Y%m%d-%H%M%S).txt
```

### 10.5 Collect server resource statistics

```bash
# CPU and memory summary:
{
    echo "=== top (1 snapshot) ==="
    top -bn1 | head -20
    echo ""
    echo "=== memory ==="
    free -m
    echo ""
    echo "=== vmstat (5 samples) ==="
    vmstat 2 5
    echo ""
    echo "=== disk I/O ==="
    iostat -x 2 3
    echo ""
    echo "=== FPM status ==="
    curl -s "http://127.0.0.1/fpm-status?full"
} > tests/load/results/server-resources-$(date +%Y%m%d-%H%M%S).txt
```

### 10.6 Collect Laravel application logs

```bash
# Copy the Laravel log (may be large — tail the last 5000 lines):
tail -5000 storage/logs/laravel.log > tests/load/results/laravel-log-$(date +%Y%m%d-%H%M%S).txt

# Count by level:
grep -oE '"level":"[A-Z]+"' storage/logs/laravel.log | sort | uniq -c | sort -rn
```

---

## 11. Failure Triage Guide

### Symptom: save-response P95 > threshold

**Check first:** FPM `max children reached` counter.
- If > 0: workers exhausted. Raise `pm.max_children`. See SERVER-SIZING.md.
- If 0: check MySQL `Threads_running`. If > 30: DB is the bottleneck.
  - Run: `EXPLAIN SELECT * FROM test_responses WHERE attempt_id=? AND test_question_id=?`
  - Verify `idx_attempt_question` index is being used. If not: `php artisan migrate` (performance indexes from Feature 1).
- If MySQL OK: check Redis `instantaneous_ops_per_sec`. If > 10,000: Redis connection pool issue.
  - Verify phpredis (not predis): `php -m | grep redis`

### Symptom: `check_pass_rate` below threshold

**Most common cause at < 500 VUs:** `throttle:public` firing on timer-sync.
- Verify the bypass is active: `grep -r "LOAD_TEST_BYPASS_THROTTLE" app/Providers/AppServiceProvider.php`
- Verify the env var is set: `echo $LOAD_TEST_BYPASS_THROTTLE`
- Count 429s in k6 output: look for lines with `status=429` on timer_sync tagged requests.

**If 429s are on exam-save (not timer):** `attempt_uuid` missing from request body.
The k6 scripts must send `attempt_uuid` in the POST body for per-VU keying.
Check `examHeaders()` usage in the scenario file.

### Symptom: Horizon results queue not draining

1. Check Horizon is running: `php artisan horizon:status`
2. Check `QUEUE_CONNECTION=redis` in `.env`
3. Check `REDIS_CLIENT=phpredis` in `.env`
4. Check for failed jobs: `php artisan horizon:list --status=failed`
5. Check circuit breaker: `php artisan examsphere:circuit-status`
   - If MySQL circuit is OPEN: DB was unreachable during scoring.
   - Reset with `php artisan examsphere:circuit-status --reset=mysql`
6. Check DLQ: `php artisan examsphere:recover-failed-jobs --critical-only --dry-run`

### Symptom: Redis `rejected_connections` > 0

1. Check `maxclients` in redis.conf vs actual connections: `redis-cli INFO clients`
2. Verify phpredis persistent connections: connections should be ≤ `pm.max_children + Horizon workers`
3. If using predis (not phpredis): each request opens a new connection → exhaust at high RPS.
   Fix: `pecl install redis` + `REDIS_CLIENT=phpredis` in `.env`

### Symptom: MySQL `Innodb_row_lock_waits` climbing

1. Check `innodb_lock_wait_timeout`: should be 5 (not default 50).
   `SHOW VARIABLES LIKE 'innodb_lock_wait_timeout';`
2. Check for long-running transactions:
   `SELECT * FROM information_schema.INNODB_TRX WHERE trx_started < NOW() - INTERVAL 5 SECOND;`
3. This is usually caused by Horizon scoring workers (CalculateTestResults) calling
   `lockForUpdate()` on the same TestAttempt row that the exam submit is also locking.
   Ensure `QUEUE_CONNECTION=redis` so jobs dispatch AFTER the submit transaction commits
   (`after_commit: true` in config/queue.php — already set in Feature 1).

### Symptom: FPM `max children reached` > 0

1. Check current `pm.max_children`: `grep max_children /etc/php/8.3/fpm/pool.d/exam-pool.conf`
2. Calculate required: use Little's Law: N = RPS × P50_seconds × 1.30
   At 1k VUs: 133 req/s × 0.050s × 1.30 ≈ 9 → 80 is generous. Something else is holding workers.
3. Check for slow requests: `curl -s "http://localhost/fpm-status?full" | grep "slow requests"`
4. Check `request_slowlog_timeout` in pool config and read the slow log.
5. Check if SSE connections are in the exam pool: verify Nginx routes `/api/monitoring/` to
   the monitoring FPM socket, not the exam socket.

### Symptom: Token refreshes climbing during sustain phase

The k6 scenarios track `token_refreshes` (session re-initializations during the test).
These should only happen at VU startup (once). If climbing during sustain:
1. Check Redis TTL on exam state keys: `TTL "exam:state:{attemptId}"`
   Should be > remaining exam duration + 2h buffer (Feature 2 design).
2. Check `SingleSessionLock` middleware — if a second VU takes the same student slot,
   the first VU gets 409 and re-initializes. Ensure `studentForVU()` maps unique VUs
   to unique students (no overlap in the 20,000-student pool).

---

*End of runbook. Questions or incidents: capture the output of `snapshot "incident"` and attach to the bug report.*
