/**
 * Dynamic pass/fail thresholds that scale with the target user count.
 *
 * Usage in a scenario:
 *   import { buildThresholds } from '../shared/thresholds.js';
 *   export const options = { thresholds: buildThresholds() };
 *
 * Thresholds are intentionally tiered — a 500 ms P95 that is unacceptable
 * for 1k users may be the best we can achieve for 20k users on a single
 * server. The goal is to surface REGRESSIONS, not to impose a single bar
 * across all hardware configurations.
 */

import { USERS } from './config.js';

// ── Tier definitions ──────────────────────────────────────────────────────────
const TIERS = {
  //           P50   P95   P99  err%   queue_depth
  small:  { p50:  50, p95: 150, p99:  300, err: 0.001, queue:  100 }, // ≤ 1k users
  medium: { p50:  75, p95: 200, p99:  500, err: 0.001, queue:  500 }, // ≤ 5k users
  large:  { p50: 100, p95: 300, p99:  800, err: 0.005, queue: 1000 }, // ≤ 10k users
  xlarge: { p50: 150, p95: 500, p99: 1000, err: 0.010, queue: 2000 }, // ≤ 20k users
};

function selectTier(users) {
  if (users <= 1000)  return TIERS.small;
  if (users <= 5000)  return TIERS.medium;
  if (users <= 10000) return TIERS.large;
  return TIERS.xlarge;
}

const T = selectTier(USERS);

/**
 * Build the k6 thresholds object for the most critical endpoints.
 *
 * Tagged metrics (e.g. http_req_duration{name:save_response}) require that
 * each http.* call uses params.tags.name = 'save_response'.
 *
 * @param {string[]} [extra] - additional raw threshold strings to merge
 * @returns {Object} k6 thresholds object
 */
export function buildThresholds(extra = []) {
  const base = {
    // ── Per-endpoint response time ──────────────────────────────────────────
    [`http_req_duration{name:save_response}`]: [
      `p(50)<${T.p50}`,
      `p(95)<${T.p95}`,
      `p(99)<${T.p99}`,
    ],
    [`http_req_duration{name:timer_sync}`]: [
      `p(95)<${Math.round(T.p95 * 0.7)}`,   // timer sync must be faster than save
      `p(99)<${T.p99}`,
    ],
    [`http_req_duration{name:exam_start}`]: [
      `p(95)<${Math.round(T.p95 * 3)}`,      // DB-heavy start allowed 3× threshold
      `p(99)<${Math.round(T.p99 * 3)}`,
    ],
    [`http_req_duration{name:exam_submit}`]: [
      `p(95)<${Math.round(T.p95 * 2)}`,      // lightweight; scoring is async
      `p(99)<${Math.round(T.p99 * 2)}`,
    ],
    [`http_req_duration{name:login}`]: [
      `p(95)<${Math.round(T.p95 * 2)}`,
      `p(99)<${Math.round(T.p99 * 2)}`,
    ],
    [`http_req_duration{name:sse_ttfb}`]: [
      'p(95)<500',    // SSE first-byte: institution admin, less latency-sensitive
      'p(99)<2000',
    ],

    // ── Overall error rate (excludes expected 429s) ─────────────────────────
    // The check() rate must stay above (1 - err). We track this as a Rate metric.
    'check_pass_rate': [`rate>${1 - T.err}`],

    // ── HTTP request failures (includes 5xx but NOT 4xx) ───────────────────
    'http_req_failed': [`rate<${T.err}`],

    // ── Custom throughput counters ──────────────────────────────────────────
    'iterations':          ['count>0'],
    'http_reqs':           ['count>0'],
  };

  // Merge caller-supplied extras
  extra.forEach(e => {
    const [key, ...vals] = e.split(':');
    base[key] = vals.join(':').split(',');
  });

  return base;
}

/**
 * Export raw tier for use in console summaries.
 */
export const THRESHOLD_TIER = T;
export const TIER_NAME = USERS <= 1000 ? 'small (≤1k)'
  : USERS <= 5000 ? 'medium (≤5k)'
  : USERS <= 10000 ? 'large (≤10k)'
  : 'xlarge (≤20k)';
