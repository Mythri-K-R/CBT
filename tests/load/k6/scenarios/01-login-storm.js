/**
 * Scenario 01 — Login Storm
 *
 * Tests the authentication endpoint under concurrent load.
 * Measures: response time, token delivery, throttle behaviour.
 *
 * PRE-REQUISITE: throttle:login must be raised to ≥ (USERS × 2) / min
 *   in a load-test-specific .env, or bypass middleware must be enabled
 *   (LOAD_TEST_BYPASS_THROTTLE=1). The default 3/min per-IP will reject
 *   all load-test traffic; this is EXPECTED in a correctly configured
 *   production environment.
 *
 * Run:
 *   k6 run --env USERS=1000 --env BASE_URL=http://localhost:8000/api \
 *          tests/load/k6/scenarios/01-login-storm.js
 */

import http   from 'k6/http';
import { check, sleep } from 'k6';
import { Rate }         from 'k6/metrics';
import { BASE_URL, USERS, JSON_HEADERS, rampStages } from '../shared/config.js';
import { buildThresholds }                            from '../shared/thresholds.js';

const checkPassRate = new Rate('check_pass_rate');

export const options = {
  stages:     rampStages(USERS, 5),
  thresholds: buildThresholds(),
};

// Two credential sets: institution admin + student roll-number logins.
// Expand as needed to avoid per-IP throttle collisions.
const CREDENTIALS = [
  { email: 'admin@loadtest.local',    password: 'LoadTest@123', role: 'admin' },
  { email: 'faculty1@loadtest.local', password: 'LoadTest@123', role: 'faculty' },
  { email: 'faculty2@loadtest.local', password: 'LoadTest@123', role: 'faculty' },
];

export default function () {
  const cred = CREDENTIALS[__VU % CREDENTIALS.length];

  const res = http.post(
    `${BASE_URL}/login`,
    JSON.stringify({ email: cred.email, password: cred.password }),
    {
      headers: JSON_HEADERS,
      tags:    { name: 'login' },
    }
  );

  const ok = check(res, {
    'login: status 200':        (r) => r.status === 200,
    'login: token present':     (r) => !!r.json('token'),
    'login: user.role correct': (r) => r.json('user.role') === cred.role,
  });
  checkPassRate.add(ok);

  // 429 is expected and not a failure — it means rate limiting is working.
  if (res.status === 429) {
    checkPassRate.add(true);
    sleep(60);   // back off a full minute
    return;
  }

  sleep(1);   // 1-second think time between logins
}
