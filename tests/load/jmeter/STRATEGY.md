# JMeter Load Test Strategy — CBT Platform

JMeter is the recommended tool for load tests that require:
- Corporation/enterprise CI integration (JMeter Maven Plugin)
- Detailed per-sampler HTML dashboards (`jmeter -g report`)  
- Protocol-level debugging (TCP/HTTP wire log via Proxy Recorder)
- JDBC samplers to monitor MySQL queue depth in real time

For raw scripting velocity, use the k6 scripts. Use JMeter for sign-off
testing, long-running soak tests (4–8 h), and stakeholder reports.

---

## Test Plan Structure

```
CBT Load Test Plan
├── User Defined Variables
│   ├── BASE_URL          = http://localhost:8000
│   ├── TEST_SLUG         = load-test-exam
│   ├── TEST_ID           = 1
│   ├── ADMIN_EMAIL       = admin@loadtest.local
│   └── ADMIN_PASSWORD    = LoadTest@123
│
├── HTTP Request Defaults (apply to all samplers)
│   ├── Server: ${BASE_URL}
│   ├── Content encoding: UTF-8
│   └── Connect timeout: 5000 / Response timeout: 30000
│
├── HTTP Header Manager (global)
│   ├── Content-Type: application/json
│   └── Accept: application/json
│
├── CSV Data Set Config — students.csv
│   ├── Filename: data/students.csv
│   ├── Variable Names: ROLL_NUMBER,STUDENT_ID
│   ├── Delimiter: ,
│   ├── Recycle on EOF: true
│   └── Sharing mode: All threads
│
├── Thread Group 1 — Login Storm (Scenario 1)
│   ├── Threads (users): 1000
│   ├── Ramp-up period: 120 s
│   ├── Loop count: Forever (duration-based)
│   ├── Duration: 300 s
│   ├── Startup delay: 0
│   │
│   ├── HTTP Sampler: POST /api/login
│   │   ├── Body: {"email":"${ADMIN_EMAIL}","password":"${ADMIN_PASSWORD}"}
│   │   ├── Name: login
│   │   └── Response Assertion: $.token exists
│   │
│   ├── JSON Extractor: token
│   │   ├── Names of created variables: AUTH_TOKEN
│   │   ├── JSON Path: $.token
│   │   └── Match No.: 1
│   │
│   ├── HTTP Sampler: GET /api/dashboard
│   │   ├── Name: dashboard_after_login
│   │   └── HTTP Header Manager: Authorization: Bearer ${AUTH_TOKEN}
│   │
│   ├── Constant Timer: 2000 ms
│   │
│   └── HTTP Sampler: POST /api/logout
│       └── Name: logout
│
├── Thread Group 2 — Exam Start Storm (Scenario 2)
│   ├── Threads: 1000 → 20000 (use Stepping Thread Group plugin)
│   │   └── Plugin: bzm - Stepping Thread Group
│   │       ├── This group will start: 0 threads
│   │       ├── First, wait for: 0 s
│   │       ├── Then start N threads every M s
│   │       │   N = 100, M = 6 (=100/s ramp to 20k in 200s)
│   │       └── Stop threads after: 600 s
│   │
│   ├── HTTP Sampler: POST /api/test/join/${TEST_SLUG}/identify
│   │   ├── Name: identify
│   │   └── Body: {"roll_number":"${ROLL_NUMBER}"}
│   │
│   ├── JSON Extractor: student_id
│   │   ├── Variable: STUDENT_ID
│   │   └── JSON Path: $.data.student.id
│   │
│   ├── Constant Timer: 3000 ms (read instructions pause)
│   │
│   ├── HTTP Sampler: POST /api/test/join/${TEST_SLUG}/start
│   │   ├── Name: exam_start
│   │   └── Body: {"student_id":"${STUDENT_ID}"}
│   │
│   └── JSON Extractor: attempt_uuid
│       ├── Variable: ATTEMPT_UUID
│       └── JSON Path: $.data.attempt_uuid
│
├── Thread Group 3 — Answer Save Storm (Scenario 3)
│   ├── Threads: configured per target (see per-tier table below)
│   ├── Duration: 600 s
│   │
│   ├── [setUp] CSV Data Set: attempts.csv (ATTEMPT_UUID per row)
│   │
│   ├── Counter Config
│   │   ├── Starting value: 1
│   │   ├── Increment: 1
│   │   ├── Maximum: 180
│   │   └── Variable name: Q_IDX
│   │
│   ├── HTTP Sampler: POST /api/test/exam/save-response
│   │   ├── Name: save_response
│   │   └── Body: {
│   │           "attempt_uuid":"${ATTEMPT_UUID}",
│   │           "test_question_id":${Q_IDX},
│   │           "selected_answer":"A",
│   │           "status":"answered",
│   │           "time_spent_seconds":30
│   │         }
│   │
│   ├── Response Assertion (save_response)
│   │   ├── Apply to: Main sample
│   │   └── HTTP Response Code = 200 OR 429
│   │
│   └── Constant Timer: 10000 ms (auto-save cadence)
│
├── Thread Group 4 — Exam Submit Storm (Scenario 5)
│   ├── Threads: per-tier
│   ├── Duration: 600 s (submit phase + result poll phase)
│   │
│   ├── HTTP Sampler: POST /api/test/exam/${ATTEMPT_UUID}/submit
│   │   └── Name: exam_submit
│   │
│   ├── JSON Extractor: processing flag
│   │   ├── Variable: IS_PROCESSING
│   │   └── JSON Path: $.processing
│   │
│   ├── While Controller: IS_PROCESSING = true
│   │   ├── Constant Timer: 5000 ms
│   │   └── HTTP Sampler: GET /api/test/exam/${ATTEMPT_UUID}/result
│   │       └── Name: result_poll
│   │
│   └── Response Assertion: $.data.total_score NOT null
│
├── Thread Group 5 — SSE Monitoring (Scenario 6)
│   ├── Threads: 50–200 (admin watchers only)
│   ├── Duration: 600 s
│   │
│   ├── HTTP Sampler: POST /api/login (admin credentials)
│   │   └── Store token in AUTH_TOKEN
│   │
│   └── HTTP Sampler: GET /api/monitoring/exams/${TEST_ID}/snapshot
│       ├── Name: monitoring_snapshot
│       ├── HTTP Header: Authorization: Bearer ${AUTH_TOKEN}
│       ├── Constant Timer: 3000 ms (3s poll matches SSE cadence)
│       └── Response Assertion: statusCode = 200
│
├── Listeners (always-on)
│   ├── Backend Listener → InfluxDB
│   │   ├── Implementation: influxdb2BackendListenerClient
│   │   ├── influxdbUrl: http://influx:8086
│   │   └── measurement: jmeter
│   │
│   ├── Summary Report → results/summary.jtl
│   ├── View Results Tree (disabled in load run — CPU-heavy)
│   └── HTML Dashboard Generator
│       └── Output folder: results/html-report/
│
└── JDBC Sampler Group — MySQL Throughput Monitor
    ├── JDBC Connection Config
    │   ├── Variable name: mysql_conn
    │   └── Database URL: jdbc:mysql://127.0.0.1:3306/cbt_db
    │
    ├── Thread Group: 1 thread, loop every 10 s
    │
    └── JDBC Request: MySQL real-time metrics
        ├── Name: mysql_throughput
        └── SQL Query: |
              SELECT
                VARIABLE_NAME,
                VARIABLE_VALUE
              FROM performance_schema.global_status
              WHERE VARIABLE_NAME IN (
                'Com_select', 'Com_update', 'Com_insert',
                'Threads_connected', 'Innodb_rows_read',
                'Innodb_rows_inserted', 'Innodb_rows_updated'
              );
```

---

## Thread Count per Target Load Tier

| Scenario               | 1k users | 5k users | 10k users | 20k users |
|------------------------|----------|----------|-----------|-----------|
| Login Storm            | 100      | 500      | 1000      | 2000      |
| Exam Start Storm       | 1000     | 5000     | 10000     | 20000     |
| Answer Save Storm      | 1000     | 5000     | 10000     | 20000     |
| Auto-Save + Timer Sync | 1000     | 5000     | 10000     | 20000     |
| Exam Submit Storm      | 1000     | 5000     | 10000     | 20000     |
| SSE Monitoring         | 20       | 50       | 100       | 200       |

> For > 5k threads in a single JMeter instance, increase heap:
> `HEAP="-Xms4g -Xmx8g" jmeter -n -t test-plan.jmx`
> For 20k threads, run JMeter in distributed mode (1 controller + 4 injectors).

---

## Distributed Mode Setup (for 20k users)

```bash
# On each injector node:
jmeter-server -Djava.rmi.server.hostname=INJECTOR_IP

# On controller (replace IPs with injector IPs):
jmeter -n -t test-plan.jmx \
  -R 192.168.1.10,192.168.1.11,192.168.1.12,192.168.1.13 \
  -l results/run-$(date +%Y%m%d%H%M).jtl \
  -e -o results/html/
```

Each injector handles 5k threads. The controller aggregates results.

---

## Required JMeter Plugins

Install via JMeter Plugin Manager:
- **Custom Thread Groups**: `bzm - Stepping Thread Group`
- **3 Basic Graphs**: throughput, response time, error rate per endpoint
- **PerfMon Server Agent**: install on the target server to monitor CPU/RAM/IO
- **InfluxDB Backend Listener**: for Grafana real-time dashboard

---

## Soak Test Configuration (4-hour run)

For detecting memory leaks and gradual degradation:

```
Phase 1 — Ramp:   0 → 5000 users over 10 minutes
Phase 2 — Soak:   5000 users for 3 hours 40 minutes
Phase 3 — Drain:  5000 → 0 over 10 minutes
```

Watch for:
- PHP-FPM worker memory growing over time (leak indicator)
- Redis `used_memory` growth rate (should plateau)
- MySQL `Threads_connected` creeping toward `max_connections`
- P99 response time drifting upward mid-test (queue backup)

---

## Assertions and Thresholds

Configure via JMeter's **SLA Report** or the `jmeter-maven-plugin`:

```xml
<!-- pom.xml snippet for CI gate -->
<configuration>
  <errorRateThresholdInPercent>1.0</errorRateThresholdInPercent>
  <durationThreshold>
    <percentile99>1000</percentile99>   <!-- ms -->
    <percentile95>500</percentile95>
    <percentile50>150</percentile50>
  </durationThreshold>
</configuration>
```

---

## JMeter CI/CD Integration

```yaml
# .github/workflows/load-test.yml  (run on schedule, not on every PR)
- name: Run JMeter soak test
  run: |
    jmeter -n \
      -t tests/load/jmeter/cbt-load-test.jmx \
      -Jbase_url=${{ secrets.LOAD_TEST_URL }} \
      -Jusers=5000 \
      -l results/results-${{ github.run_id }}.jtl \
      -e -o results/html/
    # Fail the build if error rate > 1%
    python tests/load/jmeter/check-thresholds.py results/results-*.jtl
```
