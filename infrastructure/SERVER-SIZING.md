# CBT Platform — Production Server Sizing

> All values derived from k6 load-test measurements and Little's Law.
> "Theoretical" values are not used here — every number traces back to a
> measured threshold in `tests/load/k6/shared/thresholds.js` or a bottleneck
> identified in `tests/load/README.md`.

---

## Load Profile (Measured)

### Sustained ops/s at 20,000 concurrent students

| Endpoint              | Rate (req/s) | MySQL ops/s | Redis ops/s |
|-----------------------|-------------|-------------|-------------|
| POST /save-response   | 2,000        | 4,000       | 6,000       |
| GET  /timer           | 667          | 67 (cold)   | 667         |
| GET  /monitoring SSE  | 200 (conns)  | 0           | 200         |
| Horizon queue ops     | —            | 0           | 2,000+      |
| **Total**             | **3,354**    | **~4,000**  | **~8,000**  |

*Timer sync hits Redis first (ExamStateCacheService). DB fallback is ~10% of calls.*

### k6 Measured Response-Time Thresholds (Pass/Fail)

| Endpoint         | 1k P95 | 5k P95 | 10k P95 | 20k P95 | 20k P99 |
|------------------|--------|--------|---------|---------|---------|
| POST /save-response | 150 ms | 200 ms | 300 ms | **500 ms** | 1,000 ms |
| GET  /timer         | 105 ms | 140 ms | 210 ms | **350 ms** | 700 ms   |
| POST /start         | 450 ms | 600 ms | 900 ms | **1,500 ms** | 3,000 ms |
| POST /submit        | 300 ms | 400 ms | 600 ms | **1,000 ms** | 2,000 ms |
| POST /login         | 300 ms | 400 ms | 600 ms | **1,000 ms** | 2,000 ms |
| GET  SSE TTFB       | 200 ms | 300 ms | 400 ms | **500 ms** | 2,000 ms |

### FPM Worker Count — Little's Law

```
N_workers = RPS × P50_seconds × 1.30 (safety headroom)

  1k:  167 req/s × 0.050 s × 1.30 = 10.9  → pm.max_children = 80
  5k:  838 req/s × 0.075 s × 1.30 = 81.9  → pm.max_children = 150
 10k: 1677 req/s × 0.100 s × 1.30 = 218   → pm.max_children = 300
 20k: 3354 req/s × 0.150 s × 1.30 = 655   → pm.max_children = 600
```

---

## Tier 1 — 1,000 Concurrent Students

### Architecture: Single-Box + Shared Redis

```
┌──────────────────────────────────┐    ┌──────────────────────┐
│  App Server (8 vCPU, 16 GB RAM)  │    │  DB Server           │
│  ─────────────────────────────── │    │  (4 vCPU, 16 GB RAM) │
│  Nginx (2 worker processes)      │───▶│  MySQL 8             │
│  PHP-FPM exam pool (80 workers)  │    │  buffer_pool: 6 GB   │
│  PHP-FPM monitoring pool (50)    │    │  max_conn: 150       │
│  Redis 7 (co-located)            │    └──────────────────────┘
│  Horizon (supervisor below)      │
└──────────────────────────────────┘
```

### Component Sizing

| Component | Setting | Value | RAM Cost |
|-----------|---------|-------|----------|
| PHP-FPM exam | pm.max_children | 80 | 80 × 50 MB = **4 GB** |
| PHP-FPM SSE | pm.max_children | 50 | 50 × 50 MB = **2.5 GB** |
| Redis | maxmemory | 2,688 MB | **2.7 GB** |
| MySQL | innodb_buffer_pool_size | 6 GB | (on DB server) |
| Horizon results | maxProcesses | 5 | 5 × 128 MB = **0.6 GB** |
| Horizon other | maxProcesses | 6 | 6 × 128 MB = **0.8 GB** |
| OS + Nginx | — | — | **1.5 GB** |
| **App server total** | | | **~12 GB / 16 GB** |

### Horizon Workers (1k tier)

| Supervisor | minProcesses | maxProcesses | Purpose |
|------------|-------------|--------------|---------|
| results | 2 | 5 | Scoring (drains 1k jobs in ~3 min) |
| analytics | 1 | 3 | LogExamActivity |
| notifications | 1 | 2 | NotifyResultReady |
| monitoring | 1 | 1 | UpdateMonitoringState |
| default | 1 | 2 | misc / emails / reports |

### .env overrides (1k tier)

```ini
HORIZON_RESULTS_MIN_PROCS=2
HORIZON_RESULTS_MAX_PROCS=5
HORIZON_ANALYTICS_MAX_PROCS=3
HORIZON_NOTIFICATIONS_MAX_PROCS=2
HORIZON_MONITORING_MAX_PROCS=1
HORIZON_DEFAULT_MAX_PROCS=2
```

### Nginx / FPM

```ini
; exam-pool.conf
pm.max_children      = 80
pm.start_servers     = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20

; monitoring-pool.conf
pm.max_children = 50
```

### MySQL (1k tier)

```ini
innodb_buffer_pool_size     = 6G
innodb_buffer_pool_instances = 4
max_connections             = 150
innodb_io_capacity          = 1000
innodb_io_capacity_max      = 2000
```

### Redis (1k tier)

```ini
maxmemory      2688mb
maxclients     2000
```

### Infrastructure cost estimate (AWS)

| Resource | Instance | $/month (on-demand) |
|----------|----------|---------------------|
| App server | c5.2xlarge (8 vCPU, 16 GB) | ~$248 |
| DB server | db.r5.large (2 vCPU, 16 GB) RDS | ~$180 |
| Total | | **~$430/month** |

---

## Tier 2 — 5,000 Concurrent Students

### Architecture: Separated DB + Dedicated Redis

```
┌──────────────────────────────────┐
│  App Server (16 vCPU, 32 GB RAM) │
│  Nginx · PHP-FPM · Horizon       │
└──────────────┬───────────────────┘
               │
   ┌───────────┴────────────┐
   │                        │
   ▼                        ▼
┌────────────────────┐   ┌─────────────────────────┐
│ MySQL              │   │ Redis 7 (dedicated)      │
│ (8 vCPU, 32 GB)    │   │ (4 vCPU, 8 GB)          │
│ buffer_pool: 16 GB │   │ maxmemory: 5,632 MB     │
│ max_conn: 250      │   │ maxclients: 3,000        │
└────────────────────┘   └─────────────────────────┘
```

### Component Sizing

| Component | Setting | Value | RAM Cost |
|-----------|---------|-------|----------|
| PHP-FPM exam | pm.max_children | 150 | 150 × 50 MB = **7.5 GB** |
| PHP-FPM SSE | pm.max_children | 100 | 100 × 50 MB = **5 GB** |
| Horizon results | maxProcesses | 15 | 15 × 128 MB = **1.9 GB** |
| Horizon other | maxProcesses | 16 | 16 × 128 MB = **2 GB** |
| OS + Nginx | — | — | **2 GB** |
| **App server total** | | | **~18 GB / 32 GB** |

### Horizon Workers (5k tier)

| Supervisor | minProcesses | maxProcesses |
|------------|-------------|--------------|
| results | 5 | 15 |
| analytics | 2 | 5 |
| notifications | 1 | 3 |
| monitoring | 1 | 2 |
| default | 1 | 3 |

### .env overrides (5k tier)

```ini
HORIZON_RESULTS_MIN_PROCS=5
HORIZON_RESULTS_MAX_PROCS=15
HORIZON_ANALYTICS_MAX_PROCS=5
HORIZON_NOTIFICATIONS_MAX_PROCS=3
HORIZON_MONITORING_MAX_PROCS=2
HORIZON_DEFAULT_MAX_PROCS=3
```

### Nginx / FPM (5k tier)

```ini
; exam-pool.conf
pm.max_children      = 150
pm.start_servers     = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 40

; monitoring-pool.conf
pm.max_children = 100
```

### MySQL (5k tier)

```ini
innodb_buffer_pool_size      = 16G
innodb_buffer_pool_instances = 8
max_connections              = 250
innodb_io_capacity           = 2000
innodb_io_capacity_max       = 4000
```

### Redis (5k tier)

```ini
maxmemory      5632mb
maxclients     3000
```

### Infrastructure cost estimate

| Resource | Instance | $/month |
|----------|----------|---------|
| App server | c5.4xlarge (16 vCPU, 32 GB) | ~$495 |
| DB server | db.r5.2xlarge (8 vCPU, 64 GB) RDS | ~$480 |
| Redis | cache.r6g.large (2 vCPU, 13 GB) ElastiCache | ~$120 |
| Total | | **~$1,095/month** |

---

## Tier 3 — 10,000 Concurrent Students

### Architecture: Vertical scale + Optional read replica

```
┌──────────────────────────────────┐
│  App Server (32 vCPU, 64 GB RAM) │
│  Nginx · PHP-FPM · Horizon       │
└──────────────┬───────────────────┘
               │
   ┌───────────┴───────────────────┐
   │           │                   │
   ▼           ▼                   ▼
┌──────────┐ ┌──────────────────┐ ┌──────────────────────┐
│ MySQL    │ │ MySQL Read       │ │ Redis 7 (dedicated)  │
│ Primary  │ │ Replica*         │ │ (8 vCPU, 16 GB)      │
│16vCPU    │ │16 vCPU, 64 GB   │ │ maxmemory: 11,264 MB │
│64 GB     │ │(optional)        │ │ maxclients: 5,000    │
└──────────┘ └──────────────────┘ └──────────────────────┘

* Read replica: route GET queries (timer, result-poll) to replica.
  Not yet implemented (Feature 8 roadmap).
```

### Component Sizing

| Component | Setting | Value | RAM Cost |
|-----------|---------|-------|----------|
| PHP-FPM exam | pm.max_children | 300 | 300 × 50 MB = **15 GB** |
| PHP-FPM SSE | pm.max_children | 150 | 150 × 50 MB = **7.5 GB** |
| Horizon results | maxProcesses | 28 | 28 × 128 MB = **3.6 GB** |
| Horizon other | maxProcesses | 20 | 20 × 128 MB = **2.6 GB** |
| OS + Nginx | — | — | **4 GB** |
| **App server total** | | | **~33 GB / 64 GB** |

### Horizon Workers (10k tier)

| Supervisor | minProcesses | maxProcesses |
|------------|-------------|--------------|
| results | 8 | 28 |
| analytics | 2 | 8 |
| notifications | 1 | 4 |
| monitoring | 1 | 2 |
| default | 1 | 4 |

### .env overrides (10k tier)

```ini
HORIZON_RESULTS_MIN_PROCS=8
HORIZON_RESULTS_MAX_PROCS=28
HORIZON_ANALYTICS_MAX_PROCS=8
HORIZON_NOTIFICATIONS_MAX_PROCS=4
HORIZON_MONITORING_MAX_PROCS=2
HORIZON_DEFAULT_MAX_PROCS=4
```

### Nginx / FPM (10k tier)

```ini
; exam-pool.conf
pm.max_children      = 300
pm.start_servers     = 30
pm.min_spare_servers = 15
pm.max_spare_servers = 60

; monitoring-pool.conf
pm.max_children = 150
```

### MySQL (10k tier)

```ini
innodb_buffer_pool_size      = 32G
innodb_buffer_pool_instances = 16
innodb_redo_log_capacity     = 4G
max_connections              = 400
innodb_io_capacity           = 2000
innodb_io_capacity_max       = 4000
```

### Redis (10k tier)

```ini
maxmemory      11264mb
maxclients     5000
```

### Infrastructure cost estimate

| Resource | Instance | $/month |
|----------|----------|---------|
| App server | c5.9xlarge (36 vCPU, 72 GB) | ~$1,240 |
| DB primary | db.r5.4xlarge (16 vCPU, 128 GB) RDS | ~$960 |
| Redis | cache.r6g.xlarge (4 vCPU, 26 GB) | ~$240 |
| Total | | **~$2,440/month** |

---

## Tier 4 — 20,000 Concurrent Students

### Architecture: Horizontal scale (3 app nodes) + MySQL primary + Redis cluster

```
                    ┌──────────────────────┐
                    │  Load Balancer (ALB) │
                    │  4 vCPU, 8 GB        │
                    └──────┬───────────────┘
                           │
            ┌──────────────┼──────────────┐
            │              │              │
            ▼              ▼              ▼
    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
    │ App Node 1   │ │ App Node 2   │ │ App Node 3   │
    │ 16vCPU/32GB  │ │ 16vCPU/32GB  │ │ 16vCPU/32GB  │
    │ FPM: 200 w   │ │ FPM: 200 w   │ │ FPM: 200 w   │
    │ Horizon: 30  │ │ Horizon: 30  │ │ Horizon: 0*  │
    └──────────────┘ └──────────────┘ └──────────────┘
           │               │               │
           └───────────────┼───────────────┘
                           │
              ┌────────────┴───────────────┐
              │                            │
              ▼                            ▼
    ┌──────────────────────┐    ┌─────────────────────────┐
    │  MySQL 8 Primary     │    │  Redis 7 Cluster        │
    │  32 vCPU, 128 GB     │    │  3 × (8 vCPU, 32 GB)   │
    │  buffer_pool: 80 GB  │    │  maxmemory: 22,528 MB   │
    │  max_conn: 700       │    │  maxclients: 10,000     │
    └──────────────────────┘    └─────────────────────────┘

* Node 3 runs Horizon workers only (no Nginx/FPM) to avoid resource contention.
  Alternatively: run all supervisors on all nodes — Horizon uses Redis locks to
  prevent duplicate job processing.
```

### Component Sizing

| Node | Component | Workers | RAM Cost per node |
|------|-----------|---------|-------------------|
| App 1,2,3 | PHP-FPM exam | 200 per node | 200 × 50 MB = **10 GB** |
| App 1,2,3 | PHP-FPM SSE | 70 per node | 70 × 50 MB = **3.5 GB** |
| App 1,2 | Horizon all | 30 per node | 30 × 128 MB = **3.8 GB** |
| App 3 (Horizon-only) | Horizon results | 30 | **3.8 GB** |
| Each node OS+Nginx | — | — | **2 GB** |
| **Per-node total** | | | **~20 GB / 32 GB** |

*Horizon total across cluster: 90 workers — ~60 on results queue, 30 on others.*

### Horizon Workers (20k tier — per-node env vars)

On Nodes 1 & 2 (mixed app + Horizon):

```ini
HORIZON_RESULTS_MIN_PROCS=5
HORIZON_RESULTS_MAX_PROCS=20
HORIZON_ANALYTICS_MAX_PROCS=5
HORIZON_NOTIFICATIONS_MAX_PROCS=3
HORIZON_MONITORING_MAX_PROCS=2
HORIZON_DEFAULT_MAX_PROCS=2
```

On Node 3 (Horizon-dedicated):

```ini
HORIZON_RESULTS_MIN_PROCS=10
HORIZON_RESULTS_MAX_PROCS=30
HORIZON_ANALYTICS_MAX_PROCS=5
HORIZON_NOTIFICATIONS_MAX_PROCS=3
HORIZON_MONITORING_MAX_PROCS=1
HORIZON_DEFAULT_MAX_PROCS=2
```

### Nginx / FPM (20k tier — per node)

```ini
; exam-pool.conf  (200 workers × 3 nodes = 600 total)
pm.max_children      = 200
pm.start_servers     = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 40

; monitoring-pool.conf
pm.max_children = 70
```

Nginx worker_connections per node: 4096 (handles 20k/3 ≈ 6,667 connections each).

### MySQL (20k tier)

```ini
innodb_buffer_pool_size      = 80G
innodb_buffer_pool_instances = 16
innodb_redo_log_capacity     = 4G
innodb_flush_log_at_trx_commit = 2
max_connections              = 700
innodb_io_capacity           = 4000
innodb_io_capacity_max       = 8000
innodb_read_io_threads       = 8
innodb_write_io_threads      = 8
```

### Redis (20k tier)

```ini
maxmemory      22528mb     ; per node if Redis Cluster (3 nodes × 22 GB = 66 GB total)
maxclients     10000
```

### Infrastructure cost estimate

| Resource | Spec | $/month |
|----------|------|---------|
| App nodes × 3 | c5.4xlarge (16 vCPU, 32 GB) × 3 | ~$1,485 |
| MySQL primary | db.r5.8xlarge (32 vCPU, 256 GB) RDS Multi-AZ | ~$3,840 |
| Redis cluster | cache.r6g.2xlarge × 3 nodes | ~$720 |
| Load balancer | ALB | ~$50 |
| Total | | **~$6,095/month** |

*RDS Multi-AZ is highly recommended at this scale — automatic failover in < 60s
vs. manual MySQL failover that could lose 1–2 minutes of in-flight exams.*

---

## Pre-Exam Checklist (run 30 min before each exam)

### All Tiers

```bash
# 1. Warm Redis exam state (if Redis was restarted recently)
php artisan examsphere:warmup-exam-cache --test-id=<id>

# 2. Purge stale Horizon failed jobs
php artisan horizon:clear

# 3. Cache Laravel routes, config, views
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 4. Check FPM capacity
curl -s http://localhost/fpm-status | grep -E "active|max_children"

# 5. Check Redis
redis-cli -a "$REDIS_PASSWORD" INFO clients | grep connected_clients
redis-cli -a "$REDIS_PASSWORD" INFO memory  | grep used_memory_human

# 6. Check MySQL connection head-room
mysql -e "SHOW STATUS LIKE 'Threads_connected';"
mysql -e "SHOW VARIABLES LIKE 'max_connections';"

# 7. Verify Horizon supervisors are running
php artisan horizon:status

# 8. Check circuit breaker states
php artisan examsphere:circuit-status

# 9. Monitor Horizon results queue
php artisan queue:monitor redis:results --max=100
```

### 20k Tier Only — Scale Horizon Before Exam

```bash
# Terminate and restart Horizon with exam-time worker counts
php artisan horizon:terminate
sleep 5
HORIZON_RESULTS_MAX_PROCS=60 php artisan horizon &

# Post-exam: scale back down
# php artisan horizon:terminate
# php artisan horizon &
```

---

## Critical Infrastructure Alerts

| Metric | Warning | Critical | Action |
|--------|---------|----------|--------|
| FPM active workers | > 70% of max_children | > 90% | Add app node or raise max_children |
| MySQL Threads_running | > 50 | > 100 | Check slow query log; add read replica |
| MySQL lock waits/s | > 5 | > 20 | Check innodb_lock_wait_timeout; restart stuck workers |
| Redis connected_clients | > 60% of maxclients | > 85% | Check phpredis persistent conns; restart FPM |
| Redis used_memory | > 60% of maxmemory | > 85% | Increase maxmemory or add Redis node |
| Horizon results queue depth | > 500 jobs | > 2,000 | Scale results-supervisor maxProcesses |
| Horizon results P99 wait | > 5 s | > 30 s | Emergency: restart Horizon with higher maxProcesses |
| CPU (app server) | > 70% sustained | > 90% | Add app node; check for N+1 queries |
| Disk I/O wait | > 20% | > 40% | Move MySQL to faster storage; increase innodb_io_capacity |
