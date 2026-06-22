/**
 * TEST 01 — Institution Admin Concurrent Login
 * ============================================
 * Simulates 20 institution admins all logging in simultaneously, then
 * browsing the dashboard and question list for 30 seconds.
 *
 * Run:
 *   k6 run tests/Load/k6/01_institution_login.js
 *   k6 run --env BASE_URL=http://your-server tests/Load/k6/01_institution_login.js
 *
 * What is tested:
 *   - Laravel session creation under simultaneous logins
 *   - Livewire login component (LoginForm::authenticate)
 *   - Rate-limiter behaviour (5 attempts per IP)
 *   - Dashboard Livewire component render under concurrent load
 *
 * Pass/fail thresholds:
 *   - 95 % of requests complete in < 2 s
 *   - Error rate < 1 %
 */

import { sleep } from 'k6';
import { check } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import {
    BASE_URL, getPage, livewireCall, extractCsrf, extractSnapshot,
    livewireRedirect, adminForVu,
} from './helpers.js';

// ── Custom metrics ────────────────────────────────────────────────────────────
const loginDuration  = new Trend('login_duration_ms',   true);
const loginErrors    = new Rate('login_error_rate');
const loginSuccess   = new Counter('login_success_count');

// ── k6 options ────────────────────────────────────────────────────────────────
export const options = {
    scenarios: {
        concurrent_logins: {
            executor:   'ramping-vus',
            startVUs:   0,
            stages: [
                { duration: '5s',  target: 20 },   // ramp to 20 VUs in 5 s
                { duration: '60s', target: 20 },   // hold 20 VUs for 60 s (each does login→browse)
                { duration: '5s',  target: 0  },   // ramp down
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        http_req_duration:  ['p(95)<2000'],   // 95th percentile under 2 s
        login_error_rate:   ['rate<0.01'],    // less than 1 % errors
    },
};

// ── Main VU function ──────────────────────────────────────────────────────────
export default function () {
    const admin = adminForVu(__VU);

    // ── Step 1: Load login page ───────────────────────────────────────────────
    const loginPageRes = getPage('/login', 'login-page');
    const csrf         = extractCsrf(loginPageRes.body);
    const snapshot     = extractSnapshot(loginPageRes.body);

    check(csrf,     { 'got csrf token': v => v !== null });
    check(snapshot, { 'got livewire snapshot': v => v !== null });

    if (!csrf || !snapshot) {
        loginErrors.add(1);
        return;
    }

    // ── Step 2: Livewire login call ───────────────────────────────────────────
    const start    = Date.now();
    const loginRes = livewireCall(csrf, snapshot, {
        'form.email':    admin.username,
        'form.password': admin.password,
    }, [{
        path:   '',
        method: 'login',
        params: [],
    }]);
    loginDuration.add(Date.now() - start);

    const ok = check(loginRes, {
        'login response 200': r => r.status === 200,
        'no login errors':    r => {
            try {
                const body = JSON.parse(r.body);
                const errs = body?.components?.[0]?.effects?.errors ?? {};
                return Object.keys(errs).length === 0;
            } catch (_) { return false; }
        },
    });

    if (!ok) {
        loginErrors.add(1);
        return;
    }

    // Livewire returns a redirect to the dashboard on success
    const redirectUrl = livewireRedirect(loginRes);
    check(redirectUrl, { 'login redirects to dashboard': v => v !== null });

    if (!redirectUrl) {
        loginErrors.add(1);
        return;
    }

    loginSuccess.add(1);

    // ── Step 3: Follow redirect → dashboard ──────────────────────────────────
    // k6 cookie jar already holds the session cookie after login
    const path = redirectUrl.startsWith('http') ? new URL(redirectUrl).pathname : redirectUrl;
    const dashRes = getPage(path, 'dashboard');
    check(dashRes, {
        'dashboard loaded':          r => r.status === 200,
        'dashboard has institution': r => r.body.includes('dashboard') || r.body.includes('Dashboard'),
    });

    sleep(2);

    // ── Step 4: Visit questions list (heavier DB query) ───────────────────────
    const questRes = getPage('/institution/questions', 'questions-list');
    check(questRes, {
        'questions page loaded': r => r.status === 200,
        'questions page has content': r => r.body.length > 1000,
    });

    sleep(3);

    // ── Step 5: Visit results page ────────────────────────────────────────────
    const resultsRes = getPage('/institution/results', 'results-page');
    check(resultsRes, { 'results page loaded': r => r.status === 200 });

    sleep(5);
}
