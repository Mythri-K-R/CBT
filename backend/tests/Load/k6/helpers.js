/**
 * helpers.js — shared utilities for ExamSphere k6 load tests
 *
 * Handles the Livewire 3 HTTP protocol:
 *   1. Extract wire:snapshot from initial page HTML
 *   2. POST to /livewire/update with updates + method calls
 *   3. Parse redirect / error from the response
 */

import http from 'k6/http';
import { check } from 'k6';

// ── Config ────────────────────────────────────────────────────────────────────

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// ── HTML extraction helpers ───────────────────────────────────────────────────

/** Pull the CSRF meta tag value from any page HTML. */
export function extractCsrf(html) {
    // Standard Laravel meta tag
    let m = html.match(
        /<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/
    );

    if (m) {
        return m[1];
    }

    // ExamSphere uses data-csrf
    m = html.match(/data-csrf=["']([^"']+)["']/);

    if (m) {
        return m[1];
    }

    return null;
}

/**
 * Extract the raw Livewire snapshot JSON string from a page.
 * Livewire 3 embeds it as: wire:snapshot="{&quot;data&quot;:...}"
 */
export function extractSnapshot(html) {
    // wire:snapshot attribute value is HTML-entity-encoded JSON
    const m = html.match(/wire:snapshot="((?:[^"\\]|\\.)*)"/);
    if (!m) return null;
    return htmlDecode(m[1]);
}

/** Decode HTML entities to raw characters. */
function htmlDecode(str) {
    return str
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}

// ── Livewire HTTP protocol ────────────────────────────────────────────────────

/**
 * POST a Livewire update to /livewire/update.
 *
 * @param {string} csrf        - CSRF token from the page meta tag
 * @param {string} snapshot    - Raw snapshot JSON string from wire:snapshot
 * @param {Object} updates     - Property updates  e.g. { "form.email": "x" }
 * @param {Array}  calls       - Method calls       e.g. [{ path:"", method:"login", params:[] }]
 * @returns {Response}
 */
export function livewireCall(csrf, snapshot, updates, calls) {
    const payload = JSON.stringify({
        components: [{
            snapshot: snapshot,
            updates:  updates || {},
            calls:    calls   || [],
        }],
    });

    return http.post(`${BASE_URL}/livewire/update`, payload, {
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'text/html, application/xhtml+xml',
            'X-Livewire':   'true',
            'X-CSRF-TOKEN': csrf,
            'Referer':      BASE_URL,
        },
    });
}

/**
 * Parse the redirect URL from a Livewire update response.
 * Returns null if no redirect was issued.
 */
export function livewireRedirect(res) {
    try {
        const body = JSON.parse(res.body);
        return body?.components?.[0]?.effects?.redirect ?? null;
    } catch (_) {
        return null;
    }
}

/**
 * Parse validation / component errors from a Livewire update response.
 * Returns array of error strings (empty if none).
 */
export function livewireErrors(res) {
    try {
        const body = JSON.parse(res.body);
        const errors = body?.components?.[0]?.effects?.errors ?? {};
        return Object.values(errors).flat();
    } catch (_) {
        return [];
    }
}

/**
 * Extract the updated snapshot from a Livewire response (for chained calls).
 */
export function livewireNextSnapshot(res) {
    try {
        const body = JSON.parse(res.body);
        return body?.components?.[0]?.snapshot ?? null;
    } catch (_) {
        return null;
    }
}

// ── HTTP helpers ──────────────────────────────────────────────────────────────

/** GET a page and assert HTTP 200. Returns the Response. */
export function getPage(path, tag) {
    const url = path.startsWith('http')
        ? path
        : `${BASE_URL}${path}`;

    const res = http.get(url);

    check(res, {
        [`${tag} status 200`]: r => r.status === 200,
    });

    return res;
}

/**
 * Zero-padded number string.  zeroPad(3, 2) → "03"
 */
export function zeroPad(n, width) {
    return String(n).padStart(width, '0');
}

/**
 * Derive student credentials from the absolute VU index (1-based).
 *   VUs 1–1000    → institution 01, students LT01-0001…LT01-1000
 *   VUs 1001–2000 → institution 02, students LT02-0001…LT02-1000
 *   …
 *   VUs 19001–20000 → institution 20, students LT20-0001…LT20-1000
 */
export function studentForVu(vuIndex) {
    const instIdx    = Math.ceil(vuIndex / 1000);          // 1–20
    const studentIdx = ((vuIndex - 1) % 1000) + 1;        // 1–1000
    return {
        institutionIdx: instIdx,
        rollNumber:     `LT${zeroPad(instIdx, 2)}-${zeroPad(studentIdx, 4)}`,
        dob:            '01012000',   // DDMMYYYY — matches seeder DOB 2000-01-01
        testSlug:       `lt-test-inst${zeroPad(instIdx, 2)}`,
    };
}

/**
 * Derive institution admin credentials from VU index (1-based, max 20).
 */
export function adminForVu(vuIndex) {
    const idx = ((vuIndex - 1) % 20) + 1;
    return {
        username: `ltadmin${zeroPad(idx, 2)}`,
        password: 'LoadTest@123',
    };
}
