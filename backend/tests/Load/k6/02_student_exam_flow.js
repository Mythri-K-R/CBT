/**
 * TEST 02 — Full Student Exam Flow Under Load
 * ===========================================
 * Simulates the complete student journey end-to-end:
 *   Landing → verify credentials → Instructions → beginTest → Exam
 *   → answer all questions → submit
 *
 * Scales up to 20,000 virtual users (= 20 institutions × 1000 students each).
 * Requires the LoadTestSeeder to have been run first.
 *
 * Run (small smoke test — 100 users):
 *   k6 run --env PEAK_VUS=100 tests/Load/k6/02_student_exam_flow.js
 *
 * Run (full 20,000-user load test — needs powerful server + PHP-FPM):
 *   k6 run tests/Load/k6/02_student_exam_flow.js
 *
 * Env vars:
 *   BASE_URL   — target server (default: http://localhost:8000)
 *   PEAK_VUS   — max concurrent VUs (default: 20000)
 *
 * What is tested:
 *   - Concurrent student verification (roll number + DOB)
 *   - Simultaneous TestAttempt creation (unique per student per test)
 *   - Livewire exam page load under heavy concurrency
 *   - TestResponse saves (selectOption + saveAndNext × N questions)
 *   - Test submission (doSubmitAttempt scoring logic under DB write storm)
 *
 * Thresholds:
 *   - 95 % of requests < 3 s
 *   - Submit success rate > 99 %
 */

import { sleep } from 'k6';
import { check } from 'k6';
import exec from 'k6/execution';
import { Trend, Rate, Counter } from 'k6/metrics';
import {
    BASE_URL,
    getPage,
    livewireCall,
    livewireRedirect,
    livewireNextSnapshot,
    livewireErrors,
    extractCsrf,
    extractSnapshot,
    studentForVu,
} from './helpers.js';

// ── Config ────────────────────────────────────────────────────────────────────
const PEAK_VUS      = parseInt(__ENV.PEAK_VUS  || '20000', 10);
const QUESTIONS     = 10;   // matches LoadTestSeeder QUESTIONS_PER_TEST

// ── Custom metrics ────────────────────────────────────────────────────────────
const verifyDuration  = new Trend('verify_duration_ms',  true);
const beginDuration   = new Trend('begin_test_ms',       true);
const submitDuration  = new Trend('submit_duration_ms',  true);
const submitSuccess   = new Counter('submit_success');
const submitFailures  = new Counter('submit_failure');
const flowErrors      = new Rate('flow_error_rate');

// ── k6 scenarios ──────────────────────────────────────────────────────────────
export const options = {
    scenarios: {
        student_exam_flow: {
            executor:   'ramping-vus',
            startVUs:   0,
            stages: [
                { duration: '2m',  target: Math.floor(PEAK_VUS * 0.25) },  // warm-up
                { duration: '3m',  target: PEAK_VUS },                      // ramp to peak
                { duration: '5m',  target: PEAK_VUS },                      // sustain peak
                { duration: '1m',  target: 0 },                             // ramp down
            ],
            gracefulRampDown: '30s',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<3000'],
        flow_error_rate:   ['rate<0.01'],
    },
};

// ── Main VU function ──────────────────────────────────────────────────────────
export default function () {
    const student = studentForVu(exec.scenario.iterationInTest + 1);
    const testUrl = `/t/${student.testSlug}`;

    // ── STEP 1: Load test landing page ────────────────────────────────────────
    const landingRes = getPage(testUrl, 'landing');
    if (landingRes.status !== 200) { flowErrors.add(1); return; }

    const csrf     = extractCsrf(landingRes.body);
    const snapshot = extractSnapshot(landingRes.body);
    console.log('csrf=', csrf);
    console.log('snapshot=', snapshot ? 'YES' : 'NO');
    console.log(landingRes.body.substring(0, 500));
    if (!csrf || !snapshot) {
        flowErrors.add(1);
        return;
    }

    // ── STEP 2: Verify student credentials ────────────────────────────────────
    const t0        = Date.now();
    const verifyRes = livewireCall(csrf, snapshot, {
        rollNumber: student.rollNumber,
        dob:        student.dob,
    }, [{
        path:   '',
        method: 'verify',
        params: [],
    }]);
    verifyDuration.add(Date.now() - t0);

    check(verifyRes, { 'verify: status 200': r => r.status === 200 });
    const verifyErrors = livewireErrors(verifyRes);
    if (verifyErrors.length > 0) {
        flowErrors.add(1);
        return;
    }

    const instructionsPath = livewireRedirect(verifyRes);
    console.log("verifyErrors =", JSON.stringify(verifyErrors));
    console.log("instructionsPath =", instructionsPath);
    console.log("verifyRes.body =", verifyRes.body.substring(0,500));
    if (!instructionsPath) {
        flowErrors.add(1);
        return;
    }

    // ── STEP 3: Load instructions page ───────────────────────────────────────
    const instrRes   = getPage(instructionsPath, 'instructions');
    const instrSnap  = extractSnapshot(instrRes.body);
    const instrCsrf  = extractCsrf(instrRes.body);
    console.log("instrCsrf =", instrCsrf);
    console.log("instrSnap =", instrSnap ? "YES" : "NO");
    if (!instrSnap || !instrCsrf) {
        flowErrors.add(1);
        return;
    }

    // Small think time — student reads instructions (realistic)
    sleep(2);

    // ── STEP 4: Begin test (creates TestAttempt + TestResponses) ─────────────
    const t1       = Date.now();
    const beginRes = livewireCall(instrCsrf, instrSnap, {}, [{
        path:   '',
        method: 'beginTest',
        params: [],
    }]);
    beginDuration.add(Date.now() - t1);

    check(beginRes, { 'beginTest: status 200': r => r.status === 200 });
    const beginErrors = livewireErrors(beginRes);
    if (beginErrors.length > 0) {
        flowErrors.add(1);
        return;
    }

    const examPath = livewireRedirect(beginRes);
    if (!examPath) {
        flowErrors.add(1);
        return;
    }

    // ── STEP 5: Load exam page ────────────────────────────────────────────────
    const examRes  = getPage(examPath, 'exam');
    if (examRes.status !== 200) { flowErrors.add(1); return; }

    let examSnap = extractSnapshot(examRes.body);
    const examCsrf = extractCsrf(examRes.body);
    if (!examSnap || !examCsrf) {
        flowErrors.add(1);
        return;
    }

    check(examRes, { 'exam page has question palette': r => r.body.includes('wire:snapshot') });

    // ── STEP 6: Answer all questions (selectOption + saveAndNext) ─────────────
    const answers = ['A', 'B', 'C', 'D'];
    for (let q = 0; q < QUESTIONS; q++) {
        // Select an option
        const selRes = livewireCall(examCsrf, examSnap, {}, [{
            path:   '',
            method: 'selectOption',
            params: [answers[q % 4]],
        }]);
        examSnap = livewireNextSnapshot(selRes) || examSnap;

        // Save and go to next question
        const saveRes = livewireCall(examCsrf, examSnap, {}, [{
            path:   '',
            method: 'saveAndNext',
            params: [],
        }]);
        examSnap = livewireNextSnapshot(saveRes) || examSnap;

        // Small pause between questions (realistic exam pace)
        sleep(0.5);
    }

    // ── STEP 7: Submit the test ───────────────────────────────────────────────
    const t2       = Date.now();
    const submitRes = livewireCall(examCsrf, examSnap, {}, [{
        path:   '',
        method: 'submitTest',
        params: [],
    }]);
    submitDuration.add(Date.now() - t2);

    const submitOk = check(submitRes, {
        'submit: status 200':      r => r.status === 200,
        'submit: no errors':       r => livewireErrors(r).length === 0,
        'submit: redirects':       r => livewireRedirect(r) !== null,
    });

    if (submitOk) {
        submitSuccess.add(1);
    } else {
        submitFailures.add(1);
        flowErrors.add(1);
    }

    // ── STEP 8: Load result page ──────────────────────────────────────────────
    const resultPath = livewireRedirect(submitRes);
    if (resultPath) {
        const resultRes = getPage(resultPath, 'result');
        check(resultRes, {
            'result page loaded':  r => r.status === 200,
            'result page shows submission success': r => r.body.includes('Test Submitted Successfully'),
        });
    }
}
