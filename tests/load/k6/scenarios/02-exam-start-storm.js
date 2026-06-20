/**
 * Scenario 02 — Exam Start Storm
 *
 * Simulates N students identifying themselves and starting the exam
 * within a short burst window (the "exam open" moment — everyone hits
 * Start at once). This is the highest DB-write intensity moment of an exam.
 *
 * Each VU:
 *   1. POST /test/join/{slug}/identify  → gets student_id + sets session cookie
 *   2. POST /test/join/{slug}/start     → creates TestAttempt, warms Redis cache
 *   3. Records attemptUuid for use in later scenarios
 *
 * Expected hot paths:
 *   - TestAttempt INSERT (DB)
 *   - TestTimerState INSERT (DB)
 *   - DistributedLock (Redis) — prevents duplicate starts
 *   - ExamStateCacheService::warmup() (Redis HMSET)
 *   - ExamStarted event → monitoring queue job
 *
 * Run:
 *   k6 run --env USERS=1000 --env TEST_SLUG=load-test-exam \
 *          tests/load/k6/scenarios/02-exam-start-storm.js
 */

import http   from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend }  from 'k6/metrics';
import {
  BASE_URL, USERS, TEST_SLUG,
  JSON_HEADERS, rampStages, studentForVU,
} from '../shared/config.js';
import { buildThresholds } from '../shared/thresholds.js';

const checkPassRate  = new Rate('check_pass_rate');
const identifyTime   = new Trend('identify_time',   true);
const examStartTime  = new Trend('exam_start_time', true);

// Start storm: all students arrive within 3 minutes (tight burst)
export const options = {
  stages: [
    { duration: '3m', target: USERS }, // fast ramp = burst
    { duration: '1m', target: USERS }, // sustain briefly
    { duration: '1m', target: 0 },
  ],
  thresholds: buildThresholds(),
};

export default function () {
  const student = studentForVU(__VU);

  // ── Step 1: Identify ──────────────────────────────────────────────────────
  const identRes = http.post(
    `${BASE_URL}/test/join/${TEST_SLUG}/identify`,
    JSON.stringify({ roll_number: student.roll_number }),
    { headers: JSON_HEADERS, tags: { name: 'identify' } }
  );
  identifyTime.add(identRes.timings.duration);

  const identOk = check(identRes, {
    'identify: status 200':       (r) => r.status === 200,
    'identify: student_id set':   (r) => !!r.json('data.student.id'),
  });
  checkPassRate.add(identOk);

  if (!identOk) {
    sleep(1);
    return;
  }

  const studentId = identRes.json('data.student.id');

  // ── Step 2: Start exam ────────────────────────────────────────────────────
  const startRes = http.post(
    `${BASE_URL}/test/join/${TEST_SLUG}/start`,
    JSON.stringify({ student_id: studentId }),
    { headers: JSON_HEADERS, tags: { name: 'exam_start' } }
  );
  examStartTime.add(startRes.timings.duration);

  const startOk = check(startRes, {
    'start: status 200 or 201':   (r) => [200, 201].includes(r.status),
    'start: attempt_uuid present': (r) => !!r.json('attempt_uuid'),
  });
  checkPassRate.add(startOk);

  // Brief think time after starting (student reads instructions)
  sleep(2);
}
