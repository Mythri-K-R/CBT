/**
 * Scenario 04 — Auto Save + Timer Sync Storm
 *
 * Models the background heartbeat traffic that runs continuously during an exam:
 *   - Answer auto-save (POST /exam/save-response) every 10 seconds per student
 *   - Timer sync      (GET  /exam/{uuid}/timer)   every 30 seconds per student
 *
 * Unlike Scenario 03 (burst test), this scenario measures SUSTAINED throughput
 * over a longer window to find memory leaks, Redis connection leaks, and
 * gradual degradation under steady-state load.
 *
 * At 20k students:
 *   Auto-saves:  20,000 / 10 = 2,000 req/s
 *   Timer syncs: 20,000 / 30 =   667 req/s
 *   Total:                    ≈ 2,667 req/s sustained for the test duration
 *
 * P0-2 FIX: Every save-response request now sends X-Exam-Session-Token.
 * Each VU initialises its session by calling identify + start before the
 * first save. This eliminates the 401 responses caused by the C-2 fix.
 *
 * Session lifecycle per VU:
 *   1. First iteration  → identify + start → stores attemptUuid + sessionToken
 *   2. Every auto-save  → sends X-Exam-Session-Token header
 *   3. Every timer sync → uses attemptUuid in the URL (no token needed on GET)
 *   4. On HTTP 401      → clears token; re-init on next iteration
 *
 * Run (sustained 15-minute window at 20k):
 *   k6 run --env USERS=20000 --env BASE_URL=http://localhost:8000/api \
 *          --env TEST_SLUG=load-test-exam \
 *          tests/load/k6/scenarios/04-auto-save-storm.js
 */

import http          from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import {
  BASE_URL, USERS, TEST_SLUG,
  JSON_HEADERS, examHeaders,
  QUESTION_IDS, ANSWER_OPTIONS, RESPONSE_STATUSES,
  randomElement, randomInt, studentForVU,
} from '../shared/config.js';
import { buildThresholds } from '../shared/thresholds.js';

const checkPassRate  = new Rate('check_pass_rate');
const timerTime      = new Trend('timer_sync_time',    true);
const saveTime       = new Trend('save_response_time', true);
const initTime       = new Trend('session_init_time',  true);
const saveErrors     = new Counter('save_errors');
const timerErrors    = new Counter('timer_errors');
const tokenRefreshes = new Counter('token_refreshes');

export const options = {
  // Longer sustain window for steady-state measurement
  stages: [
    { duration: '3m',  target: USERS },
    { duration: '15m', target: USERS },  // 15 minutes sustained
    { duration: '2m',  target: 0 },
  ],
  thresholds: buildThresholds(),
};

// ── VU-local state (isolated per VU, persists across iterations) ──────────────
let sessionToken = null;
let attemptUuid  = null;
let syncCounter  = 0;

/**
 * Obtain a fresh session token for this VU.
 *
 * Calls identify → start on the load-test student mapped to this VU.
 * If an attempt already exists (e.g., from scenario 02 or 03), TestStartService
 * returns the RESUME path: same UUID, refreshed token. No duplicate attempt.
 *
 * Returns true on success, false on any HTTP failure.
 */
function initSession() {
  const student = studentForVU(__VU);
  const t0      = Date.now();

  // Step 1 — Identify student
  const identRes = http.post(
    `${BASE_URL}/test/join/${TEST_SLUG}/identify`,
    JSON.stringify({ roll_number: student.roll_number }),
    { headers: JSON_HEADERS, tags: { name: 'identify_init' } }
  );

  if (identRes.status !== 200) {
    console.error(`[VU${__VU}] identify failed: HTTP ${identRes.status}`);
    return false;
  }

  const studentId = identRes.json('data.student.id');
  if (!studentId) {
    console.error(`[VU${__VU}] identify returned no student_id`);
    return false;
  }

  // Step 2 — Start or resume exam
  const startRes = http.post(
    `${BASE_URL}/test/join/${TEST_SLUG}/start`,
    JSON.stringify({ student_id: studentId }),
    { headers: JSON_HEADERS, tags: { name: 'exam_start_init' } }
  );

  if (![200, 201].includes(startRes.status)) {
    console.error(`[VU${__VU}] exam start failed: HTTP ${startRes.status}`);
    return false;
  }

  sessionToken = startRes.json('session_token');
  attemptUuid  = startRes.json('attempt_uuid');

  if (!sessionToken || !attemptUuid) {
    console.error(`[VU${__VU}] exam start missing token or uuid`);
    return false;
  }

  initTime.add(Date.now() - t0);
  return true;
}

export function setup() {
  console.log(`[auto-save-storm] Target: ${USERS} VUs`);
  console.log(`[auto-save-storm] Expected: ~${Math.round(USERS / 10)} saves/s + ~${Math.round(USERS / 30)} timer syncs/s`);
  console.log(`[auto-save-storm] Each VU self-initialises via identify+start for X-Exam-Session-Token`);
}

export default function () {
  // ── Session initialization (first iteration or after token expiry) ─────────
  if (!sessionToken || !attemptUuid) {
    tokenRefreshes.add(1);
    if (!initSession()) {
      sleep(5);
      return;
    }
  }

  // ── Auto-save (every 10 s — every iteration) ──────────────────────────────
  group('auto_save', () => {
    const qid = QUESTION_IDS[syncCounter % QUESTION_IDS.length];

    const res = http.post(
      `${BASE_URL}/test/exam/save-response`,
      JSON.stringify({
        attempt_uuid:       attemptUuid,
        test_question_id:   qid,
        selected_answer:    randomElement(ANSWER_OPTIONS),
        status:             randomElement(RESPONSE_STATUSES),
        time_spent_seconds: randomInt(5, 30),
      }),
      // P0-2: X-Exam-Session-Token is mandatory since the C-2 fix.
      { headers: examHeaders(sessionToken), tags: { name: 'save_response' } }
    );

    saveTime.add(res.timings.duration);

    if (res.status === 401) {
      // Token expired — clear state; initSession() runs on next iteration.
      sessionToken = null;
      attemptUuid  = null;
      return;
    }

    if (res.status === 409) {
      // Session conflict (another VU took the same student slot).
      sessionToken = null;
      return;
    }

    const ok = check(res, {
      'auto-save: 200 or 429': (r) => r.status === 200 || r.status === 429,
      'auto-save: saved or rate-limited': (r) => r.status === 429 || r.json('saved') === true,
    });
    if (!ok) saveErrors.add(1);
    checkPassRate.add(ok);
  });

  // ── Timer sync (every 3rd iteration = every 30 s) ─────────────────────────
  // Timer sync uses attemptUuid in the URL and does NOT require a session token
  // — it goes through SingleSessionLock which handles token enforcement
  // separately from the save-response path.
  if (syncCounter % 3 === 0) {
    group('timer_sync', () => {
      const res = http.get(
        `${BASE_URL}/test/exam/${attemptUuid}/timer`,
        {
          headers: examHeaders(sessionToken),
          tags:    { name: 'timer_sync' },
        }
      );
      timerTime.add(res.timings.duration);

      const ok = check(res, {
        'timer: status 200':        (r) => r.status === 200,
        'timer: remaining_seconds': (r) => r.json('remaining_seconds') >= 0,
      });
      if (!ok) timerErrors.add(1);
      checkPassRate.add(ok);
    });
  }

  syncCounter++;
  sleep(10);   // 10-second auto-save cadence
}
