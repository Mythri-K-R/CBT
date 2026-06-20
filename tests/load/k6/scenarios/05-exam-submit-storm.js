/**
 * Scenario 05 — Exam Submit Storm
 *
 * Models all N students submitting within a short window (exam time expires).
 * This is the highest queue-depth stress event: every submit dispatches a
 * CalculateTestResults job to the `results` queue.
 *
 * Two sub-phases:
 *   Phase A — submit burst: N students submit within 5 minutes
 *   Phase B — result polling: students poll GET /exam/{uuid}/result until
 *             processing: false (scoring complete)
 *
 * Expected bottlenecks:
 *   - results queue depth spike (N jobs in 5 min = USERS jobs)
 *   - MySQL lock contention on TestAttempt row (lockForUpdate)
 *   - Redis lock contention on exam:submit:{id} key
 *   - Horizon workers at max_processes ceiling
 *
 * Run:
 *   k6 run --env USERS=1000 --env BASE_URL=http://localhost:8000/api \
 *          tests/load/k6/scenarios/05-exam-submit-storm.js
 */

import http   from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { SharedArray } from 'k6/data';
import { BASE_URL, USERS, JSON_HEADERS } from '../shared/config.js';
import { buildThresholds } from '../shared/thresholds.js';

const checkPassRate = new Rate('check_pass_rate');
const submitTime    = new Trend('exam_submit_time', true);
const resultTime    = new Trend('result_poll_time', true);
const submitErrors  = new Counter('submit_errors');
const alreadyDone   = new Counter('already_submitted');
const scoringWaits  = new Counter('scoring_wait_polls');

let attempts;
try {
  attempts = new SharedArray('attempts', () => JSON.parse(open('../data/attempts.json')));
} catch {
  attempts = null;
}

export const options = {
  // All students submit within 5 minutes, then poll for results
  stages: [
    { duration: '5m', target: USERS },   // submit burst
    { duration: '5m', target: USERS },   // result polling
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    ...buildThresholds(),
    // Submit endpoint must stay lightweight (<1s P99) even under queue stress
    [`http_req_duration{name:exam_submit}`]: ['p(95)<1000', 'p(99)<2000'],
    // Result polling may return processing:true; don't count as errors
    'submit_errors':  ['count<10'],
  },
};

let attemptUuid   = null;
let submitted     = false;

export default function () {
  if (!attemptUuid) {
    if (attempts && attempts.length > 0) {
      attemptUuid = attempts[(__VU - 1) % attempts.length].uuid;
    } else {
      attemptUuid = `00000000-0000-0000-0000-${String(__VU).padStart(12, '0')}`;
    }
  }

  // ── Phase A: Submit ────────────────────────────────────────────────────────
  if (!submitted) {
    group('exam_submit', () => {
      const res = http.post(
        `${BASE_URL}/test/exam/${attemptUuid}/submit`,
        '{}',
        { headers: JSON_HEADERS, tags: { name: 'exam_submit' } }
      );
      submitTime.add(res.timings.duration);

      const json = res.json();

      if (res.status === 200 && json.already_submitted) {
        alreadyDone.add(1);
        submitted = true;
        return;
      }

      const ok = check(res, {
        'submit: status 200':      (r) => r.status === 200,
        'submit: has processing':  (r) => r.json('processing') !== undefined,
      });

      if (!ok) {
        submitErrors.add(1);
      } else {
        submitted = true;
      }
      checkPassRate.add(ok);
    });

    sleep(1);
    return;
  }

  // ── Phase B: Poll result ───────────────────────────────────────────────────
  group('result_poll', () => {
    const res = http.get(
      `${BASE_URL}/test/exam/${attemptUuid}/result`,
      { headers: JSON_HEADERS, tags: { name: 'result_poll' } }
    );
    resultTime.add(res.timings.duration);

    const json = res.json();

    if (json && json.processing) {
      scoringWaits.add(1);
      sleep(5);   // scoring job hasn't finished yet — wait 5 s
      return;
    }

    const ok = check(res, {
      'result: status 200':       (r) => r.status === 200,
      'result: total_score set':  (r) => r.json('data.total_score') !== null,
    });
    checkPassRate.add(ok);
  });

  sleep(5);
}
