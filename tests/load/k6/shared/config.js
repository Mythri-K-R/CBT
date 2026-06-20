/**
 * Shared k6 configuration for CBT platform load tests.
 *
 * All env vars are read once here so every scenario file imports
 * a single source of truth rather than reading __ENV directly.
 *
 * Required env vars (set via --env or .env file):
 *   BASE_URL        e.g. http://api.loadtest.local
 *   USERS           target concurrent virtual users (default: 1000)
 *   TEST_SLUG       URL slug of the pre-seeded load-test exam (default: load-test-exam)
 *   TEST_ID         integer id of the same exam (default: 1)
 *   ADMIN_EMAIL     institution admin account (default: admin@loadtest.local)
 *   ADMIN_PASSWORD  (default: LoadTest@123)
 *
 * IMPORTANT — Rate Limiting:
 *   The app enforces per-IP throttle on /api/login (typically 3-5/min).
 *   Load-test environment MUST either:
 *     (a) set LOAD_TEST_BYPASS_THROTTLE=1 in .env and use the middleware
 *         guard in AppServiceProvider, OR
 *     (b) use a k6 load-balancer with per-VU unique source IPs (k6 Cloud).
 *   The throttle:exam-save limit (120/min per attemptUuid) is BELOW the
 *   auto-save rate at 20k users. Raise it to 600/min for load test envs.
 */

export const BASE_URL    = __ENV.BASE_URL        || 'http://localhost:8000/api';
export const USERS       = parseInt(__ENV.USERS  || '1000');
export const TEST_SLUG   = __ENV.TEST_SLUG        || 'load-test-exam';
export const TEST_ID     = parseInt(__ENV.TEST_ID || '1');
export const ADMIN_EMAIL = __ENV.ADMIN_EMAIL      || 'admin@loadtest.local';
export const ADMIN_PASS  = __ENV.ADMIN_PASSWORD   || 'LoadTest@123';

// ── Ramp shape ────────────────────────────────────────────────────────────────
// Standard ramp: 2 min up → 5 min sustain → 1 min down.
// Full-lifecycle ramp: longer sustain to simulate a real 30-min exam window.
export function rampStages(users, sustainMinutes = 5) {
  return [
    { duration: '2m', target: users },
    { duration: `${sustainMinutes}m`, target: users },
    { duration: '1m', target: 0 },
  ];
}

// ── Common JSON headers ───────────────────────────────────────────────────────
export const JSON_HEADERS = {
  'Content-Type': 'application/json',
  'Accept':       'application/json',
};

export function authHeaders(token) {
  return {
    ...JSON_HEADERS,
    'Authorization': `Bearer ${token}`,
  };
}

/**
 * P0-2: Exam session headers — adds X-Exam-Session-Token required by
 * ExamEngineController::saveResponse() since the C-2 security fix.
 * Without this header every save returns HTTP 401.
 *
 * Usage in save-storm scenarios:
 *   http.post(url, body, { headers: examHeaders(sessionToken) })
 */
export function examHeaders(sessionToken) {
  return {
    ...JSON_HEADERS,
    'X-Exam-Session-Token': sessionToken,
  };
}

// ── Random helpers ────────────────────────────────────────────────────────────
export function randomElement(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

export function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

// Question pool — adjust question IDs to match your seeded exam.
// Assumption: exam has 180 questions with IDs 1-180 (or override via TEST_QUESTION_OFFSET).
const Q_OFFSET = parseInt(__ENV.TEST_QUESTION_OFFSET || '1');
export const QUESTION_IDS = Array.from({ length: 180 }, (_, i) => i + Q_OFFSET);

export const ANSWER_OPTIONS = ['A', 'B', 'C', 'D'];
export const RESPONSE_STATUSES = [
  'answered',
  'not_answered',
  'marked_review',
  'answered_marked_review',
];

// ── Student pool ──────────────────────────────────────────────────────────────
// Pre-seeded students: roll_numbers LD-00001 … LD-20000 (see tests/load/seed/README.md)
export function studentForVU(vuId) {
  const id = ((vuId - 1) % 20000) + 1;
  return {
    roll_number: `LD-${String(id).padStart(5, '0')}`,
    vu_id: id,
  };
}
