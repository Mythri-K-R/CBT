/**
 * TEST 03 — Simultaneous Submission Burst
 * ========================================
 * This is the hardest test: ALL virtual users reach the submit button at
 * the same second. This directly stresses:
 *
 *   - doSubmitAttempt(): fetches all TestResponses, scores each one, saves
 *     each response, updates TestAttempt — all under max concurrent DB load
 *   - MySQL write contention on test_responses + test_attempts tables
 *   - PHP-FPM worker pool exhaustion
 *   - Laravel session driver under 20,000 concurrent active sessions
 *
 * Strategy:
 *   All VUs race through the full flow (verify → begin → answer 2 Qs →
 *   submit) but are artificially synchronized using a barrier: everyone
 *   waits until all VUs have reached the exam page, then submits together.
 *   Because k6 cannot do a real distributed barrier across VUs, we achieve
 *   this by giving everyone zero think time — the natural scheduling causes
 *   them to pile up at submit simultaneously.
 *
 * Run (smoke — 200 VUs representing 10 students per institution):
 *   k6 run --env PEAK_VUS=200 tests/Load/k6/03_submit_burst.js
 *
 * Run (full 20,000-user burst — needs Nginx + PHP-FPM, NOT php artisan serve):
 *   k6 run tests/Load/k6/03_submit_burst.js
 *
 * What to watch:
 *   - submit_duration_ms   P95 — should stay < 5 s
 *   - submit_success_count — should equal PEAK_VUS
 *   - http_req_failed      — should be 0
 *   - MySQL slow query log during burst window
 */

import { sleep } from 'k6';
import { check } from 'k6';
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
const PEAK_VUS  = parseInt(__ENV.PEAK_VUS || '20000', 10);
const QUESTIONS = 2;  // answer only 2 questions — focus is on the submit, not the exam

// ── Custom metrics ────────────────────────────────────────────────────────────
const submitDuration  = new Trend('submit_duration_ms',  true);
const submitSuccess   = new Counter('submit_success');
const submitFailure   = new Counter('submit_failure');
const flowError       = new Rate('flow_error_rate');

// ── k6 options ────────────────────────────────────────────────────────────────
export const options = {
    scenarios: {
        submit_burst: {
            // All VUs start at once — no ramp, no stages
            executor:   'shared-iterations',
            vus:        PEAK_VUS,
            iterations: PEAK_VUS,   // one exam per student
            maxDuration: '15m',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<5000'],     // 5 s P95 is acceptable for submit burst
        flow_error_rate:   ['rate<0.02'],      // allow 2% error at peak burst
        submit_duration_ms: ['p(95)<5000'],
    },
};

// ── Main VU function ──────────────────────────────────────────────────────────
export default function () {
    const student = studentForVu(__VU);
    const testUrl = `/t/${student.testSlug}`;

    // ── STEP 1: Landing page ──────────────────────────────────────────────────
    const landingRes = getPage(testUrl, 'burst-landing');
    if (landingRes.status !== 200) { flowError.add(1); return; }

    const csrf     = extractCsrf(landingRes.body);
    const snapshot = extractSnapshot(landingRes.body);
    if (!csrf || !snapshot) { flowError.add(1); return; }

    // ── STEP 2: Verify ────────────────────────────────────────────────────────
    const verifyRes = livewireCall(csrf, snapshot, {
        rollNumber: student.rollNumber,
        dob:        student.dob,
    }, [{ path: '', method: 'verify', params: [] }]);

    if (verifyRes.status !== 200 || livewireErrors(verifyRes).length > 0) {
        flowError.add(1);
        return;
    }

    const instrPath = livewireRedirect(verifyRes);
    if (!instrPath) { flowError.add(1); return; }

    // ── STEP 3: Instructions page ─────────────────────────────────────────────
    const instrRes  = getPage(instrPath, 'burst-instr');
    const instrSnap = extractSnapshot(instrRes.body);
    const instrCsrf = extractCsrf(instrRes.body);
    if (!instrSnap || !instrCsrf) { flowError.add(1); return; }

    // ── STEP 4: Begin test (creates TestAttempt) ──────────────────────────────
    const beginRes = livewireCall(instrCsrf, instrSnap, {},
        [{ path: '', method: 'beginTest', params: [] }]);

    if (beginRes.status !== 200 || livewireErrors(beginRes).length > 0) {
        flowError.add(1);
        return;
    }

    const examPath = livewireRedirect(beginRes);
    if (!examPath) { flowError.add(1); return; }

    // ── STEP 5: Exam page ─────────────────────────────────────────────────────
    const examRes  = getPage(examPath, 'burst-exam');
    if (examRes.status !== 200) { flowError.add(1); return; }

    let examSnap  = extractSnapshot(examRes.body);
    const examCsrf = extractCsrf(examRes.body);
    if (!examSnap || !examCsrf) { flowError.add(1); return; }

    // ── STEP 6: Answer a couple of questions ──────────────────────────────────
    for (let q = 0; q < QUESTIONS; q++) {
        const selRes = livewireCall(examCsrf, examSnap, {},
            [{ path: '', method: 'selectOption', params: ['A'] }]);
        examSnap = livewireNextSnapshot(selRes) || examSnap;

        const saveRes = livewireCall(examCsrf, examSnap, {},
            [{ path: '', method: 'saveAndNext', params: [] }]);
        examSnap = livewireNextSnapshot(saveRes) || examSnap;
    }

    // ── STEP 7: SUBMIT — this is the burst point ──────────────────────────────
    // No sleep — all VUs converge here simultaneously
    const t0        = Date.now();
    const submitRes = livewireCall(examCsrf, examSnap, {},
        [{ path: '', method: 'submitTest', params: [] }]);
    submitDuration.add(Date.now() - t0);

    const ok = check(submitRes, {
        'burst submit: status 200':  r => r.status === 200,
        'burst submit: no errors':   r => livewireErrors(r).length === 0,
        'burst submit: redirects':   r => livewireRedirect(r) !== null,
    });

    if (ok) {
        submitSuccess.add(1);
    } else {
        submitFailure.add(1);
        flowError.add(1);
    }

    // ── STEP 8: Result page ───────────────────────────────────────────────────
    const resultPath = livewireRedirect(submitRes);
    if (resultPath) {
        const resultRes = getPage(resultPath, 'burst-result');
        check(resultRes, {
            'result loaded':        r => r.status === 200,
            'result shows score':   r => r.body.includes('score') || r.body.includes('Score'),
        });
    }
}

// ── Post-test summary ─────────────────────────────────────────────────────────
export function handleSummary(data) {
    const total   = PEAK_VUS;
    const success = data.metrics['submit_success']?.values?.count ?? 0;
    const failed  = data.metrics['submit_failure']?.values?.count ?? 0;
    const p95     = data.metrics['submit_duration_ms']?.values?.['p(95)'] ?? 0;

    console.log('\n══════════════════════════════════════');
    console.log('  SUBMIT BURST SUMMARY');
    console.log('══════════════════════════════════════');
    console.log(`  Total students     : ${total}`);
    console.log(`  Submitted OK       : ${success}`);
    console.log(`  Failed             : ${failed}`);
    console.log(`  Submit P95 latency : ${Math.round(p95)} ms`);
    console.log('══════════════════════════════════════\n');

    return {};   // let k6 also write its default summary
}
