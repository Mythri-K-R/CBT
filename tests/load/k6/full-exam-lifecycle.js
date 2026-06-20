/**
 * Full Exam Lifecycle — composite k6 test
 *
 * The most realistic load scenario: each VU simulates ONE student's
 * complete journey from identification to result retrieval.
 *
 *   [identify] → [start] → [answer loop × N questions] → [submit] → [poll result]
 *
 * With USERS=20000 and a 30-minute sustain, this drives:
 *   - 2,000 save-response req/s  (auto-save at 10 s intervals)
 *   - 667 timer-sync req/s       (30 s intervals)
 *   - ~35 proctor events/s       (tab switches etc., ~1% of students)
 *   - 333 submit req/s over the last 5 minutes (submissions cluster at end)
 *
 * Metrics emitted per phase:
 *   identify_time, exam_start_time, save_response_time, timer_sync_time,
 *   exam_submit_time, result_poll_time — all trackable in Grafana via k6 Cloud
 *   or the built-in k6 output.
 *
 * Run (single server target — adjust BASE_URL for distributed):
 *   k6 run \
 *     --env BASE_URL=http://localhost:8000/api \
 *     --env USERS=1000 \
 *     --env TEST_SLUG=load-test-exam \
 *     --env TEST_ID=1 \
 *     --env EXAM_DURATION_SECONDS=1800 \
 *     --out json=results/lifecycle-$(date +%s).json \
 *     tests/load/k6/full-exam-lifecycle.js
 *
 * Distributed run (k6 Cloud or multiple instances):
 *   k6 cloud --env USERS=20000 tests/load/k6/full-exam-lifecycle.js
 */

import http   from 'k6/http';
import { check, sleep, group, fail } from 'k6';
import { Rate, Trend, Counter }      from 'k6/metrics';
import {
  BASE_URL, USERS, TEST_SLUG, TEST_ID,
  JSON_HEADERS, examHeaders, rampStages, studentForVU,
  QUESTION_IDS, ANSWER_OPTIONS, RESPONSE_STATUSES, randomElement, randomInt,
} from './shared/config.js';
import { buildThresholds, TIER_NAME } from './shared/thresholds.js';

// ── Custom metrics ────────────────────────────────────────────────────────────
const checkPassRate  = new Rate('check_pass_rate');
const identifyTime   = new Trend('identify_time',      true);
const startTime      = new Trend('exam_start_time',    true);
const saveTime       = new Trend('save_response_time', true);
const timerTime      = new Trend('timer_sync_time',    true);
const submitTime     = new Trend('exam_submit_time',   true);
const resultTime     = new Trend('result_poll_time',   true);
const proctorTime    = new Trend('proctor_event_time', true);

const saveErrors     = new Counter('save_errors');
const submitErrors   = new Counter('submit_errors');
const proctorErrors  = new Counter('proctor_errors');

// ── Configuration ─────────────────────────────────────────────────────────────
const EXAM_DURATION_S = parseInt(__ENV.EXAM_DURATION_SECONDS || '1800');  // 30 min default
const QUESTIONS_COUNT = parseInt(__ENV.QUESTIONS_COUNT        || '180');

export const options = {
  scenarios: {
    // Main exam scenario: students ramp in over 5 min, hold for exam duration
    exam_session: {
      executor:   'ramping-vus',
      startVUs:   0,
      stages: [
        { duration: '5m',  target: USERS },               // students stream in
        { duration: `${Math.floor(EXAM_DURATION_S / 60)}m`, target: USERS }, // exam window
        { duration: '5m',  target: 0 },                   // exam closes
      ],
    },
  },
  thresholds: buildThresholds(),
  // Uncomment to export metrics to InfluxDB/Grafana:
  // ext: { loadimpact: { projectID: 123456, name: 'CBT Full Lifecycle' } },
};

export function setup() {
  console.log(`
=============================================================
  CBT Platform — Full Exam Lifecycle Load Test
  Target VUs   : ${USERS}
  Tier          : ${TIER_NAME}
  Exam duration : ${EXAM_DURATION_S}s
  Test slug     : ${TEST_SLUG}
  Base URL      : ${BASE_URL}
=============================================================`);
}

// ── VU-level state (reset on each VU iteration start) ─────────────────────────
export default function () {
  const student      = studentForVU(__VU);
  let   studentId    = null;   // captured from identify response
  let   sessionToken = null;   // captured from start response (C-2 requirement)
  let   attemptUuid  = null;
  let   questionPool = QUESTION_IDS.slice(0, QUESTIONS_COUNT);
  let   syncCount    = 0;

  // ── Phase 1: Identify ──────────────────────────────────────────────────────
  group('phase_1_identify', () => {
    const res = http.post(
      `${BASE_URL}/test/join/${TEST_SLUG}/identify`,
      JSON.stringify({ roll_number: student.roll_number }),
      { headers: JSON_HEADERS, tags: { name: 'identify' } }
    );
    identifyTime.add(res.timings.duration);

    if (!check(res, {
      'identify: 200':          (r) => r.status === 200,
      'identify: student_id':   (r) => !!r.json('data.student.id'),
    })) {
      checkPassRate.add(false);
      fail(`VU ${__VU}: identify failed (status ${res.status})`);
    }
    studentId = res.json('data.student.id');
    checkPassRate.add(true);
  });

  // ── Phase 2: Start exam ────────────────────────────────────────────────────
  // /start requires student_id (integer). Response is flat: { attempt_uuid, session_token, ... }
  group('phase_2_start', () => {
    const res = http.post(
      `${BASE_URL}/test/join/${TEST_SLUG}/start`,
      JSON.stringify({ student_id: studentId }),
      { headers: JSON_HEADERS, tags: { name: 'exam_start' } }
    );
    startTime.add(res.timings.duration);

    if (!check(res, {
      'start: 200 or 201':    (r) => [200, 201].includes(r.status),
      'start: attempt_uuid':  (r) => !!r.json('attempt_uuid'),
      'start: session_token': (r) => !!r.json('session_token'),
    })) {
      checkPassRate.add(false);
      fail(`VU ${__VU}: exam start failed (status ${res.status})`);
    }

    attemptUuid  = res.json('attempt_uuid');
    sessionToken = res.json('session_token');
    checkPassRate.add(true);
  });

  // Brief read-instructions pause
  sleep(randomInt(3, 10));

  // ── Phase 3: Answer loop ────────────────────────────────────────────────────
  // Run for exam duration or until all questions answered, whichever comes first.
  for (let qi = 0; qi < questionPool.length; qi++) {
    if (!attemptUuid) break;

    group('phase_3_answer', () => {
      const qid     = questionPool[qi];
      const status  = randomElement(RESPONSE_STATUSES);
      const answer  = status.startsWith('not') ? null : randomElement(ANSWER_OPTIONS);

      const res = http.post(
        `${BASE_URL}/test/exam/save-response`,
        JSON.stringify({
          attempt_uuid:       attemptUuid,
          test_question_id:   qid,
          selected_answer:    answer,
          status:             status,
          time_spent_seconds: randomInt(10, 90),
        }),
        { headers: examHeaders(sessionToken), tags: { name: 'save_response' } }
      );
      saveTime.add(res.timings.duration);

      const ok = check(res, {
        'save: 200 or 429': (r) => [200, 429].includes(r.status),
      });
      if (!ok) saveErrors.add(1);
      checkPassRate.add(ok);
    });

    // Timer sync every 3rd save (≈ every 30 seconds)
    if (syncCount % 3 === 0) {
      group('phase_3_timer', () => {
        const res = http.get(
          `${BASE_URL}/test/exam/${attemptUuid}/timer`,
          { headers: JSON_HEADERS, tags: { name: 'timer_sync' } }
        );
        timerTime.add(res.timings.duration);

        const ok = check(res, {
          'timer: 200':              (r) => r.status === 200,
          'timer: remaining >= 0':   (r) => (r.json('remaining_seconds') ?? -1) >= 0,
        });
        checkPassRate.add(ok);

        // 1% chance: student triggers a proctor event (tab switch simulation)
        if (Math.random() < 0.01) {
          const pRes = http.post(
            `${BASE_URL}/test/exam/${attemptUuid}/proctor-event`,
            JSON.stringify({ event_type: 'tab_switch', payload: { count: 1 } }),
            { headers: JSON_HEADERS, tags: { name: 'proctor_event' } }
          );
          proctorTime.add(pRes.timings.duration);
          if (!check(pRes, { 'proctor: 200': (r) => r.status === 200 })) {
            proctorErrors.add(1);
          }
        }
      });
    }

    syncCount++;
    sleep(10);  // 10-second between saves (models auto-save cadence)
  }

  // ── Phase 4: Submit ────────────────────────────────────────────────────────
  if (attemptUuid) {
    group('phase_4_submit', () => {
      const res = http.post(
        `${BASE_URL}/test/exam/${attemptUuid}/submit`,
        '{}',
        { headers: JSON_HEADERS, tags: { name: 'exam_submit' } }
      );
      submitTime.add(res.timings.duration);

      const ok = check(res, {
        'submit: 200':          (r) => r.status === 200,
        'submit: processing':   (r) => r.json('processing') !== undefined || r.json('already_submitted'),
      });
      if (!ok) submitErrors.add(1);
      checkPassRate.add(ok);
    });

    // ── Phase 5: Poll result ─────────────────────────────────────────────────
    let resultReady = false;
    let polls       = 0;

    while (!resultReady && polls < 12) {  // max 60 s of polling (12 × 5 s)
      sleep(5);

      group('phase_5_result_poll', () => {
        const res = http.get(
          `${BASE_URL}/test/exam/${attemptUuid}/result`,
          { headers: JSON_HEADERS, tags: { name: 'result_poll' } }
        );
        resultTime.add(res.timings.duration);

        if (res.status === 200 && !res.json('processing')) {
          resultReady = true;
          check(res, {
            'result: total_score set':  (r) => r.json('data.total_score') !== null,
            'result: status completed': (r) => r.json('data.status') === 'completed',
          });
          checkPassRate.add(true);
        }
      });

      polls++;
    }
  }
}

export function teardown() {
  console.log('[lifecycle] All VUs completed. Check k6 output for thresholds.');
}
