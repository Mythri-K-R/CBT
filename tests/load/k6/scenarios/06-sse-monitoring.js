/**
 * Scenario 06 — SSE Monitoring Load
 *
 * Simulates institution admins/faculty opening the live monitoring dashboard
 * (GET /api/monitoring/exams/{testId}/stream).
 *
 * What we measure:
 *   - Time-to-First-Byte (TTFB): how quickly the SSE connection opens
 *     and delivers the first exam_update event
 *   - Connection success rate under concurrent load
 *   - FPM worker exhaustion (manifests as 502/504 when workers are full)
 *   - Redis pipeline read latency at scale
 *
 * SSE in k6:
 *   k6 does not natively stream chunked responses, but it CAN measure
 *   TTFB and the first chunk arrival. We use http.get() with a 35-second
 *   timeout (slightly longer than one SSE cycle) and check that at least
 *   one 'data:' line arrives.
 *
 * Expected concurrent watchers in production:
 *   Typically 1–5 admins per institution monitoring their live exam.
 *   At 200 institutions each with 2 admins: 400 concurrent SSE connections.
 *   Each holds one PHP-FPM worker for the duration.
 *
 * CRITICAL: Ensure a dedicated php-fpm pool for /api/monitoring/* before
 *   running this at USERS >= 100 to avoid starving the exam engine workers.
 *
 * Run:
 *   k6 run --env USERS=50 --env BASE_URL=http://localhost:8000/api \
 *          --env ADMIN_EMAIL=admin@loadtest.local \
 *          --env ADMIN_PASSWORD=LoadTest@123 \
 *          --env TEST_ID=1 \
 *          tests/load/k6/scenarios/06-sse-monitoring.js
 */

import http   from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import {
  BASE_URL, USERS, TEST_ID,
  ADMIN_EMAIL, ADMIN_PASS, JSON_HEADERS, authHeaders, rampStages,
} from '../shared/config.js';
import { buildThresholds } from '../shared/thresholds.js';

const checkPassRate   = new Rate('check_pass_rate');
const ttfb            = new Trend('sse_ttfb',            true);
const connectionFails = new Counter('sse_connection_fails');
const dataReceived    = new Counter('sse_data_events_received');

// Tokens are acquired in setup() and shared across all VUs.
// We only need a handful of admin tokens (monitoring roles are institution-scoped).
const TOKEN_COUNT = Math.min(USERS, 10);

export const options = {
  // SSE test: smaller user count (admins), longer connections
  stages: rampStages(Math.min(USERS, 200), 10),
  thresholds: {
    ...buildThresholds(),
    'sse_ttfb': ['p(95)<500', 'p(99)<2000'],
    'sse_connection_fails': ['count<5'],
  },
};

export function setup() {
  const tokens = [];
  for (let i = 0; i < TOKEN_COUNT; i++) {
    const res = http.post(
      `${BASE_URL}/login`,
      JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASS }),
      { headers: JSON_HEADERS }
    );
    if (res.status === 200) {
      tokens.push(res.json('token'));
    }
    sleep(0.2);
  }
  if (tokens.length === 0) {
    throw new Error('setup() could not obtain any auth tokens for SSE monitoring test');
  }
  console.log(`[sse-monitoring] Acquired ${tokens.length} admin token(s)`);
  return { tokens };
}

export default function (data) {
  const token  = data.tokens[__VU % data.tokens.length];
  const testId = TEST_ID;

  // Open the SSE stream. k6 reads up to the timeout and measures TTFB.
  // With timeout=35s: k6 waits for the first data chunk within 35 s.
  const res = http.get(
    `${BASE_URL}/monitoring/exams/${testId}/stream`,
    {
      headers: {
        ...authHeaders(token),
        'Accept': 'text/event-stream',
        'Cache-Control': 'no-cache',
      },
      tags:    { name: 'sse_ttfb' },
      timeout: '35s',
    }
  );

  ttfb.add(res.timings.waiting); // waiting = TTFB in k6

  const body = res.body || '';

  const ok = check(res, {
    'sse: connection established':   (r) => r.status === 200,
    'sse: content-type event-stream':(r) => (r.headers['Content-Type'] || '').includes('text/event-stream'),
    'sse: received data event':      (_) => body.includes('data:'),
    'sse: exam_update event':        (_) => body.includes('event: exam_update'),
  });

  if (!ok) {
    connectionFails.add(1);
  }

  // Count how many data: lines we received (each = one SSE event delivered)
  const dataLines = (body.match(/^data:/gm) || []).length;
  dataReceived.add(dataLines);

  checkPassRate.add(ok);

  // Simulate admin keeping dashboard open for 30 seconds before we re-connect
  sleep(30);
}

export function teardown(data) {
  console.log(`[sse-monitoring] Test complete. Tokens used: ${data.tokens.length}`);
}
