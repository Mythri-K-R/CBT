/**
 * Scenario 03 — Answer Save Storm
 *
 * The highest-sustained-throughput scenario: simulates N students
 * saving answers at realistic navigation speed (one save every 30–60 s)
 * PLUS the 10-second auto-save (same endpoint).
 *
 * At 20k concurrent students this drives ~2,000 req/s to POST /exam/save-response.
 *
 * P0-2 FIX: Every save-response request now sends X-Exam-Session-Token.
 * The token is obtained by each VU calling identify + start at the beginning
 * of its first iteration. This mirrors real student behaviour and eliminates
 * the 401 responses that occurred after the C-2 session-token enforcement fix.
 *
 * Session lifecycle per VU:
 *   1. First iteration  → identify + start → stores attemptUuid + sessionToken
 *   2. Subsequent saves → sends X-Exam-Session-Token header on every request
 *   3. On HTTP 401      → token expired; re-init on next iteration (no retry
 *                         within the same iteration to avoid stacking requests)
 *
 * Hot paths exercised:
 *   - Rate limiter: throttle:exam-save (120/min per attemptUuid)
 *   - Redis HGETALL + HMSET (ExamStateCacheService)
 *   - MySQL SELECT + UPDATE (test_responses)
 *   - ExamStateCacheService::updateActivity() + adjustAnsweredCount()
 *
 * Run:
 *   k6 run --env USERS=20000 --env BASE_URL=http://localhost:8000/api \
 *          --env TEST_SLUG=load-test-exam \
 *          tests/load/k6/scenarios/03-answer-save-storm.js
 */

import http          from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import {
  BASE_URL, USERS, TEST_SLUG,
  JSON_HEADERS, examHeaders, rampStages,
  QUESTION_IDS, ANSWER_OPTIONS, RESPONSE_STATUSES,
  randomElement, randomInt, studentForVU,
} from '../shared/config.js';
import { buildThresholds } from '../shared/thresholds.js';

const checkPassRate  = new Rate('check_pass_rate');
const saveTime       = new Trend('save_response_time', true);
const initTime       = new Trend('session_init_time',  true);
const saveErrors     = new Counter('save_errors');
const rateLimitHits  = new Counter('rate_limit_hits');
const tokenRefreshes = new Counter('token_refreshes');

export const options = {
  stages:     rampStages(USERS, 5),
  thresholds: buildThresholds(),
};

// ── VU-local state (each VU has its own isolated copy) ────────────────────────
// These variables persist across iterations within a single VU but are never
// shared between VUs.
let sessionToken   = null;
let attemptUuid    = null;
let questionCursor = 0;

/**
 * Obtain a fresh session token for this VU.
 *
 * Flow:
 *   1. Identify student by roll number → get student_id
 *   2. Start/resume exam  → get attemptUuid + sessionToken
 *
 * If the student already has an in-progress attempt (from a previous scenario
 * or an earlier VU run), TestStartService returns the resume path: same UUID,
 * refreshed token. No duplicate attempt is created.
 *
 * Returns true on success, false on any HTTP error.
 */
function initSession() {
  const student = studentForVU(__VU);
  const t0      = Date.now();

  // Step 1 — Identify
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
    console.error(`[VU${__VU}] exam start response missing token or uuid`);
    return false;
  }

  initTime.add(Date.now() - t0);
  return true;
}

export function setup() {
  console.log(`[save-storm] Target: ${USERS} VUs | ~${USERS / 10} saves/s expected`);
  console.log(`[save-storm] Each VU calls identify+start to obtain X-Exam-Session-Token`);
}

export default function () {
  // ── Session initialization (first iteration or after 401) ─────────────────
  if (!sessionToken || !attemptUuid) {
    tokenRefreshes.add(1);
    if (!initSession()) {
      // Identify/start failed — back off and try again next iteration.
      sleep(5);
      return;
    }
  }

  const qid     = QUESTION_IDS[questionCursor % QUESTION_IDS.length];
  const answer  = randomElement(ANSWER_OPTIONS);
  const status  = randomElement(RESPONSE_STATUSES);
  const elapsed = randomInt(10, 120);

  // ── Save response — X-Exam-Session-Token is mandatory since C-2 fix ───────
  const res = http.post(
    `${BASE_URL}/test/exam/save-response`,
    JSON.stringify({
      attempt_uuid:       attemptUuid,
      test_question_id:   qid,
      selected_answer:    status.startsWith('not') ? null : answer,
      status:             status,
      time_spent_seconds: elapsed,
    }),
    { headers: examHeaders(sessionToken), tags: { name: 'save_response' } }
  );

  saveTime.add(res.timings.duration);

  // ── Response handling ─────────────────────────────────────────────────────
  if (res.status === 429) {
    rateLimitHits.add(1);
    checkPassRate.add(true);   // rate limiting is correct behaviour
    sleep(10);
    return;
  }

  if (res.status === 401) {
    // Token expired (Redis TTL elapsed) or session was taken over.
    // Clear state so initSession() runs on the next iteration.
    sessionToken = null;
    attemptUuid  = null;
    sleep(1);
    return;
  }

  if (res.status === 409) {
    // Session conflict: another device/tab claimed this attempt.
    // For load tests, this means a VU collision on the same student — re-init
    // so this VU gets a fresh token on the next iteration.
    sessionToken = null;
    sleep(2);
    return;
  }

  const ok = check(res, {
    'save: status 200':   (r) => r.status === 200,
    'save: saved true':   (r) => r.json('saved') === true,
  });

  if (!ok) saveErrors.add(1);
  checkPassRate.add(ok);

  questionCursor++;

  // Simulate auto-save cadence: 10 seconds between saves.
  sleep(10);
}
