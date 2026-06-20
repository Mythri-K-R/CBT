# CBT Platform — Load Test Suite

**Target:** 20,000 concurrent students  
**Stack:** Laravel 10 · MySQL 8 · Redis 7 · Horizon · PHP-FPM · Nginx

---

## Quick Start

```bash
# Install k6 (primary tool)
# Linux:  sudo snap install k6
# Mac:    brew install k6
# Win:    winget install k6

# Install Artillery (secondary)
npm install -g artillery@2

# 1. Seed test data (MUST run before any load test)
php artisan db:seed --class=LoadTestSeeder

# 2. Run individual scenario at 1k users
k6 run --env USERS=1000 --env BASE_URL=http://localhost:8000/api \
       --env TEST_SLUG=load-test-exam --env TEST_ID=1 \
       tests/load/k6/scenarios/03-answer-save-storm.js

# 3. Run full lifecycle at 5k users
k6 run --env USERS=5000 --env BASE_URL=http://localhost:8000/api \
       tests/load/k6/full-exam-lifecycle.js

# 4. Run Artillery exam flow
artillery run tests/load/artillery/exam-flow.yml

# 5. Run Artillery answer-save storm (acquire tokens dynamically — no CSV needed)
artillery run tests/load/artillery/answer-save-storm.yml
```

---

## Pre-Requisites

### 1. Data Seeding

The load test seeder must create:

| Entity | Count | Convention |
|--------|-------|------------|
| Institution | 1 | `loadtest.local` |
| Admin user | 1 | `admin@loadtest.local` / `LoadTest@123` |
| Faculty users | 3 | `faculty{1,2,3}@loadtest.local` |
| Students | 20,000 | roll_numbers `LD-00001` → `LD-20000` |
| Test | 1 | `load-test-exam` slug, 180 questions, 3h duration |
| TestLink | 1 | active, unlimited seats |
| Test questions | 180 | IDs contiguous from `TEST_QUESTION_OFFSET` |

```bash
# Create the seeder:
php artisan make:seeder LoadTestSeeder
# Implement as described above, then:
php artisan db:seed --class=LoadTestSeeder
```

### 2. Rate Limiter Overrides (load-test .env)

Add to `.env.load-test` and use with `APP_ENV=load-test`:

```ini
LOAD_TEST_BYPASS_THROTTLE=1  # disables throttle:login for load-test env
```

Add to `AppServiceProvider.php::configureRateLimiting()`:
```php
if (config('app.env') === 'load-test') {
    RateLimiter::for('login',    fn() => Limit::none());
    RateLimiter::for('exam-save',fn() => Limit::perMinute(600)->by(request()->input('attempt_uuid')));
}
```

### 3. Generating `attempts.json`

After running Scenario 02 (or the seeder), export attempts for use by Scenario 05
(submit storm). A 10-entry placeholder file already exists at
`tests/load/k6/data/attempts.json` — replace it with real UUIDs before running:

```bash
# PowerShell (Windows)
php artisan tinker --execute="echo App\Models\TestAttempt::where('test_id', 1)->select(['uuid'])->get()->map(fn(\$a) => ['uuid' => \$a->uuid, 'questions' => range(1, 180)])->toJson();" | Out-File -Encoding utf8 tests/load/k6/data/attempts.json

# Bash / Git Bash
php artisan tinker --execute="
    \App\Models\TestAttempt::where('test_id', 1)
        ->select(['uuid'])
        ->get()
        ->map(fn(\$a) => ['uuid' => \$a->uuid, 'questions' => range(1, 180)])
        ->toJson()
" > tests/load/k6/data/attempts.json
```

The file format is a JSON array: `[{"uuid":"...","questions":[1..180]}, ...]`

---

## Scenarios Overview

| # | Script | Simulates | Peak target |
|---|--------|-----------|-------------|
| 1 | `k6/scenarios/01-login-storm.js` | Admin/faculty morning login rush | 500 auth/s |
| 2 | `k6/scenarios/02-exam-start-storm.js` | All students starting within 5 min | 20k starts |
| 3 | `k6/scenarios/03-answer-save-storm.js` | Sustained auto-save at 10s cadence | 2,000 req/s |
| 4 | `k6/scenarios/04-auto-save-storm.js` | Save + timer sync sustained 15 min | 2,667 req/s |
| 5 | `k6/scenarios/05-exam-submit-storm.js` | Exam ends — everyone submits at once | queue spike |
| 6 | `k6/scenarios/06-sse-monitoring.js` | Admin dashboards watching live exam | 200 connections |
| — | `k6/full-exam-lifecycle.js` | Full end-to-end student journey | 20k VUs |

---

## Pass/Fail Thresholds

Thresholds are **tiered by user count**. Tests FAIL in CI if any threshold is breached.

### Response Time Thresholds (milliseconds)

| Endpoint | 1k P95 | 5k P95 | 10k P95 | 20k P95 | 20k P99 |
|----------|--------|--------|---------|---------|---------|
| POST /save-response | **150** | **200** | **300** | **500** | 1000 |
| GET /timer | **105** | **140** | **210** | **350** | 700 |
| POST /start | **450** | **600** | **900** | **1500** | 3000 |
| POST /submit | **300** | **400** | **600** | **1000** | 2000 |
| POST /login | **300** | **400** | **600** | **1000** | 2000 |
| GET /monitoring/snapshot | **200** | **300** | **500** | **800** | 1600 |
| SSE TTFB | **200** | **300** | **400** | **500** | 2000 |

### Error Rate Thresholds (HTTP 5xx only — 429s excluded)

| Load | Max Error Rate |
|------|---------------|
| ≤ 1k users | **0.1%** |
| ≤ 5k users | **0.1%** |
| ≤ 10k users | **0.5%** |
| ≤ 20k users | **1.0%** |

### Infrastructure Thresholds

| Resource | Warning | Critical |
|----------|---------|----------|
| MySQL connections | > 80% of `max_connections` | > 95% |
| Redis memory | > 60% of `maxmemory` | > 85% |
| FPM worker utilization | > 70% of `pm.max_children` | > 90% |
| Horizon results queue depth | > 500 jobs | > 2,000 jobs |
| Horizon results P99 wait | > 5 s | > 30 s |
| CPU (PHP-FPM node) | > 70% | > 90% |
| RAM (PHP-FPM node) | > 75% | > 90% |

---

## Expected Throughput Profile

At 20,000 concurrent students with 180 questions and 10s auto-save:

```
save-response:    2,000 req/s   (20k ÷ 10s)
timer-sync:         667 req/s   (20k ÷ 30s)
heartbeat:          667 req/s   (via sync listener — no queue)
proctor events:      20 req/s   (1% of students every 30s)
                 ──────────────
Total exam ops:   3,354 req/s sustained

Redis writes:     ≈ 8,000 ops/s (4 per save + 1 per timer + 1 per heartbeat)
MySQL writes:     ≈ 4,000 ops/s (2 per save: SELECT + UPDATE)
Queue jobs:       ≈ 2,020 jobs/s analytics + monitoring queue
```

---

## Identified Bottlenecks

Listed in order of severity at 20k concurrent students.

### 1. PHP-FPM Worker Exhaustion (CRITICAL at > 5k users)

**What:** PHP-FPM spawns a fixed pool of workers. At 3,354 req/s with P95 response
time of 150ms, steady-state concurrent workers = 3354 × 0.15 = **503 workers**.

**Default `pm.max_children = 50` will fail at > 300 req/s.**

**Symptom:** HTTP 502/504 from Nginx; FPM error log shows "max_children reached".

**Fix:**
```ini
; /etc/php/8.2/fpm/pool.d/www.conf
pm = dynamic
pm.max_children     = 600
pm.start_servers    = 50
pm.min_spare_servers = 20
pm.max_spare_servers = 80
pm.max_requests      = 500   ; recycle workers to prevent memory creep
```

### 2. MySQL Write Saturation (HIGH at > 10k users)

**What:** `save-response` executes 2 MySQL ops/call: a SELECT with `lockForUpdate`
and an UPDATE. At 2,000 saves/s = 4,000 MySQL ops/s. A single MySQL server
on typical hardware handles 5,000–15,000 simple ops/s depending on row size,
index coverage, and I/O latency. We approach saturation at 20k users.

**Symptom:** MySQL `Threads_running` spikes; InnoDB lock wait timeouts; P99 save
response time climbs above 1 s.

**Fix (immediate):**
```ini
# my.cnf
innodb_buffer_pool_size    = 8G     # cache hot rows in memory
innodb_log_file_size       = 1G     # larger redo log = fewer flushes
innodb_flush_log_at_trx_commit = 2  # relax durability: flush every second, not per commit
max_connections            = 500
wait_timeout               = 60
interactive_timeout        = 60
thread_pool_size           = 16     # MariaDB / Percona: OS-thread pool
```

**Fix (architectural):** Migrate the `test_responses` write to a write-buffer
approach. Batch 10 saves per student into a single `INSERT ... ON DUPLICATE KEY UPDATE`
every 10 seconds instead of individual UPDATEs per save. Reduces MySQL ops by 10×.

**Fix (long-term):** Add a read replica (Feature 8). All SELECT queries move
to replica; writes to primary. Doubles MySQL capacity instantly.

### 3. Redis Connection Exhaustion with Predis (HIGH at > 5k users)

**What:** `predis/predis` creates a **new TCP connection per request**. At 3,354
req/s, Redis receives 3,354 new connections/s. Redis `max_clients` defaults to 10,000
but connection setup overhead compounds under high concurrency.

**Symptom:** Redis `connected_clients` spikes; PHP errors: "Connection refused" or
"EAGAIN"; slight but consistent P99 tail latency on Redis calls.

**Fix (high impact, low effort):**
```bash
# Replace predis with phpredis C extension — persistent connections
composer remove predis/predis
pecl install redis

# php.ini:
extension=redis.so

# config/database.php — switch client:
'client' => env('REDIS_CLIENT', 'phpredis'),

# With phpredis, configure persistent connections:
'options' => [
    'persistent' => 1,
    'prefix'     => env('REDIS_PREFIX', Str::slug(env('APP_NAME'), '_').'_database_'),
]
```

**Expected improvement:** 30–50% reduction in Redis call latency; eliminates
connection-setup overhead entirely (connections are reused per FPM worker).

### 4. Horizon Queue Worker Starvation During Submit Storm (MEDIUM)

**What:** When 20k students submit within a 5-minute window = 4,000 submissions/min.
Each dispatches a `CalculateTestResults` job. Horizon's `results-supervisor`
maxes at 8 workers × ~5 s/job = **240 jobs/min throughput**.

At 4,000 submissions/min, queue depth reaches **~16,000 jobs in backlog**.
Students wait for their results.

**Symptom:** Horizon dashboard shows results queue depth > 1,000; students see
`processing: true` for > 5 minutes; P99 result-ready time > 300 s.

**Fix (Horizon config):**
```php
// config/horizon.php
'results-supervisor' => [
    'maxProcesses' => 20,   // was 8 — increases throughput to 600 jobs/min
    'timeout'      => 120,  // keep generous for large exams
    'nice'         => -10,  // max OS priority
],
```

**Fix (architectural):** Pre-scale Horizon before the exam start time (cron).
Schedule `artisan horizon:terminate && artisan horizon` with a higher
`maxProcesses` 10 minutes before the exam, then scale back after submissions close.

### 5. SSE Connections Starving Exam Engine Workers (MEDIUM)

**What:** Each SSE connection (`GET /monitoring/stream`) holds one FPM worker
for the connection lifetime (3 s sleep loop). 200 admin dashboards = 200 workers
permanently occupied. At 600 total FPM workers, 33% are consumed by dashboards
instead of serving 2,000 save-response calls/s.

**Symptom:** FPM utilization stays high even when exam traffic is moderate.

**Fix:** Dedicated FPM pool for monitoring endpoints.

```nginx
# /etc/nginx/sites-enabled/cbt.conf
location /api/monitoring/ {
    fastcgi_pass unix:/run/php/php8.2-fpm-monitoring.sock;
    # ... other fastcgi params
}

location /api/ {
    fastcgi_pass unix:/run/php/php8.2-fpm-exam.sock;
}
```

```ini
; /etc/php/8.2/fpm/pool.d/monitoring.conf
[monitoring]
listen = /run/php/php8.2-fpm-monitoring.sock
pm = static
pm.max_children = 100   ; max concurrent SSE connections
```

### 6. InnoDB Lock Contention on `test_attempts` (LOW–MEDIUM)

**What:** `TestSubmitService` uses `lockForUpdate()` on `TestAttempt`. At 4,000
submissions/min, many rows are locked briefly. If Horizon workers also query
`TestAttempt` during scoring, LOCK WAIT TIMEOUT errors can occur.

**Symptom:** MySQL `SHOW ENGINE INNODB STATUS` shows row lock waits > 5 s.

**Fix:** The existing distributed lock design (Redis-first, DB second) already
minimises DB lock contention. Ensure `innodb_lock_wait_timeout = 5` (not the
default 50) so failures fast-fail rather than pile up.

### 7. Memory Growth Under Sustained Load (LOW)

**What:** PHP FPM workers grow from ~20MB baseline to ~80MB after 500 requests
due to Laravel's runtime class loading. With `pm.max_requests = 500`, workers
recycle before memory reaches problematic levels. Without recycling, workers
can grow to 256MB+ over hours.

**Symptom:** RSS memory per FPM worker grows monotonically; total server RAM
exhausted after 4–8 hours of exam traffic.

**Fix:** `pm.max_requests = 500` (already recommended in Fix #1). Also:
```php
// config/app.php — disable Telescope in production (it logs every request to SQLite)
'telescope' => env('TELESCOPE_ENABLED', false),
```

---

## Optimization Checklist (Pre-Production)

Run through this list before any exam with > 5,000 students.

### PHP / FPM
- [ ] `pm.max_children ≥ 600` configured
- [ ] `pm.max_requests = 500` (prevents memory leak)
- [ ] `opcache.validate_timestamps = 0` (eliminates file-stat check per request)
- [ ] `opcache.memory_consumption = 256` (enough for all classes)
- [ ] Telescope **disabled** in production (`TELESCOPE_ENABLED=false`)
- [ ] `phpredis` C extension installed (not `predis`) — verify: `php -m | grep redis`

### Redis
- [ ] `maxmemory` set to 70% of available RAM
- [ ] `maxmemory-policy = allkeys-lru` (graceful eviction under pressure)
- [ ] `tcp-keepalive = 60`
- [ ] `save ""` (disable RDB persistence for pure cache workload)
- [ ] Verify connection count: `redis-cli info clients | grep connected_clients`

### MySQL
- [ ] `innodb_buffer_pool_size = 70% of RAM`
- [ ] `max_connections = 500`
- [ ] `innodb_flush_log_at_trx_commit = 2` (OK for replicated setup)
- [ ] All 14 performance indexes from Feature 1 migration applied: `php artisan migrate`
- [ ] Slow query log enabled: `slow_query_log = 1; long_query_time = 0.1`
- [ ] Verify `EXPLAIN` on `save-response` query uses `idx_attempt_question`

### Nginx
- [ ] `worker_processes = auto` (= CPU count)
- [ ] `worker_connections = 4096`
- [ ] `keepalive_timeout = 65`
- [ ] `proxy_buffering off` for SSE endpoints
- [ ] Gzip enabled for JSON responses (reduces payload 60–70%)

### Horizon
- [ ] `results-supervisor.maxProcesses = 20` before exam
- [ ] Monitor: `php artisan horizon:status`
- [ ] Verify queue drain: `php artisan queue:monitor redis:results --max=100`
- [ ] Purge failed jobs before exam: `php artisan horizon:clear`

### Application
- [ ] Config cache: `php artisan config:cache`
- [ ] Route cache: `php artisan route:cache`
- [ ] View cache: `php artisan view:cache`
- [ ] Event discovery off: `shouldDiscoverEvents() = false` (already done in EventServiceProvider)
- [ ] `APP_DEBUG=false`
- [ ] `LOG_LEVEL=warning` (avoid disk I/O from info logs at 3,000 req/s)

---

## Metrics to Collect During a Load Run

Use PerfMon (JMeter) or `node_exporter` + Grafana for server-side metrics.

| Metric | Tool | Alert If |
|--------|------|----------|
| PHP-FPM active processes | `pm.status` page | > 80% of `max_children` |
| MySQL Threads_running | `SHOW STATUS` | > 50 for > 10 s |
| MySQL lock waits | INNODB STATUS | > 0 waits/s |
| Redis `connected_clients` | `INFO clients` | > 5,000 |
| Redis `instantaneous_ops_per_sec` | `INFO stats` | < 8,000 (should be > this) |
| Redis `used_memory_rss_human` | `INFO memory` | > 80% of server RAM |
| Horizon queue depth: results | Horizon API | > 500 jobs |
| Horizon queue depth: analytics | Horizon API | > 2,000 jobs |
| P95 `save_response` | k6/Artillery | > tier threshold |
| PHP worker memory | `ps aux` grep php-fpm | > 128MB per worker |
| CPU% (all cores) | `top` / `vmstat` | > 80% sustained |
| Disk IO wait | `iostat` | > 20% |

---

## Run Order for a Full Benchmark

```bash
# 1. Reset test data
php artisan db:seed --class=LoadTestSeeder --fresh

# 2. Warm up (verify everything works before load)
k6 run --env USERS=10 tests/load/k6/full-exam-lifecycle.js

# 3. Tier 1 — 1k users (baseline)
k6 run --env USERS=1000 --out json=results/1k-lifecycle.json \
       tests/load/k6/full-exam-lifecycle.js

# 4. Tier 2 — 5k users
k6 run --env USERS=5000 --out json=results/5k-lifecycle.json \
       tests/load/k6/full-exam-lifecycle.js

# 5. Tier 3 — 10k users
k6 run --env USERS=10000 --out json=results/10k-lifecycle.json \
       tests/load/k6/full-exam-lifecycle.js

# 6. Tier 4 — 20k users (requires optimizations from checklist above)
k6 run --env USERS=20000 --out json=results/20k-lifecycle.json \
       tests/load/k6/full-exam-lifecycle.js

# 7. Submit storm in isolation (highest queue stress)
k6 run --env USERS=20000 tests/load/k6/scenarios/05-exam-submit-storm.js

# 8. SSE monitoring under concurrent exam load
k6 run --env USERS=20000 tests/load/k6/scenarios/04-auto-save-storm.js &
k6 run --env USERS=100  tests/load/k6/scenarios/06-sse-monitoring.js

# 9. Soak test (4h at 5k — run overnight)
k6 run --env USERS=5000 --env EXAM_DURATION_SECONDS=14400 \
       tests/load/k6/full-exam-lifecycle.js
```

---

## File Map

```
tests/load/
├── k6/
│   ├── shared/
│   │   ├── config.js          ← base URL, env vars, student pool helpers
│   │   └── thresholds.js      ← dynamic pass/fail thresholds per user tier
│   ├── scenarios/
│   │   ├── 01-login-storm.js
│   │   ├── 02-exam-start-storm.js
│   │   ├── 03-answer-save-storm.js
│   │   ├── 04-auto-save-storm.js
│   │   ├── 05-exam-submit-storm.js
│   │   └── 06-sse-monitoring.js
│   ├── full-exam-lifecycle.js  ← composite: identify→start→answer→submit→result
│   └── data/
│       └── attempts.json       ← replace placeholders with real UUIDs (see §3 above)
│
├── artillery/
│   ├── login-storm.yml
│   ├── exam-flow.yml           ← full lifecycle with Artillery think times
│   ├── answer-save-storm.yml   ← sustained save throughput (tokens acquired dynamically)
│   ├── functions.js            ← processor: student data, token, question rotation
│   └── data/
│       └── attempts.csv        ← reference format: uuid,q_offset (not loaded at runtime)
│
├── jmeter/
│   └── STRATEGY.md             ← test plan structure, distributed setup, CI/CD
│
└── README.md                   ← this file
```
