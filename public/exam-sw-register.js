/**
 * ExamSphere Service Worker Registration — Feature 3: Offline Answer Recovery
 *
 * FRONTEND INTEGRATION:
 *   Add to the exam page ONLY (not all pages). In Vue/React load it conditionally:
 *
 *     import '/exam-sw-register.js'   // or load via <script src="/exam-sw-register.js">
 *
 *   Or directly in the exam page template:
 *     <script src="/exam-sw-register.js" defer></script>
 *
 * REQUIREMENTS:
 *   - Must be loaded from a page served over HTTPS (or localhost for development)
 *   - The Laravel app must serve /exam-sw.js at the root path (already in public/)
 *
 * SCOPE NOTE:
 *   The SW is registered with scope '/' which makes it control the entire origin.
 *   The fetch interceptor only fires on POST /exam/save-response so admin/institution
 *   pages are unaffected by interception. However, clients.claim() will take over all
 *   open tabs on SW activation. If the exam frontend runs under a dedicated sub-path
 *   (e.g. '/exam/'), change { scope: '/' } below to { scope: '/exam/' } to limit SW
 *   control to exam pages only.
 *
 * EVENTS EMITTED (window-level CustomEvents for the exam UI to listen to):
 *   examsw:offline       — student has gone offline
 *   examsw:online        — student has come back online
 *   examsw:synced        — offline answers were synced successfully
 *   examsw:sync-failed   — sync attempted but failed (will retry)
 *   examsw:pending       — { detail: { count: N } } — N answers are queued
 */

(function () {
  'use strict';

  if (!('serviceWorker' in navigator)) {
    console.warn('[ExamSW] Service Workers not supported in this browser. Offline recovery unavailable.');
    return;
  }

  let swRegistration = null;

  // ── Registration ─────────────────────────────────────────────────────────────

  navigator.serviceWorker.register('/exam-sw.js', { scope: '/' })
    .then((registration) => {
      swRegistration = registration;
      console.info('[ExamSW] Service Worker registered, scope:', registration.scope);

      // Trigger a sync on registration in case answers were queued from a previous page load
      triggerSync();
    })
    .catch((err) => {
      console.error('[ExamSW] Service Worker registration failed:', err);
    });

  // ── Connectivity events ───────────────────────────────────────────────────────

  window.addEventListener('online', () => {
    window.dispatchEvent(new CustomEvent('examsw:online'));
    // Jitter: spread reconnect syncs over 0–30 s to prevent a storm when a
    // classroom or ISP outage resolves and thousands of students reconnect at once.
    triggerSyncWithJitter(30000);
  });

  window.addEventListener('offline', () => {
    window.dispatchEvent(new CustomEvent('examsw:offline'));
  });

  // ── Visibility change (sleep/wake recovery) ───────────────────────────────────
  // When a laptop wakes from sleep, the page becomes visible again. If the device
  // is back online, trigger a sync to drain any queued answers.

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && navigator.onLine) {
      triggerSyncWithJitter(15000);
    }
  });

  // ── Messages from Service Worker ──────────────────────────────────────────────

  navigator.serviceWorker.addEventListener('message', (event) => {
    if (!event.data) return;

    if (event.data.type === 'SYNC_DONE') {
      if (event.data.success) {
        window.dispatchEvent(new CustomEvent('examsw:synced'));
      } else {
        window.dispatchEvent(new CustomEvent('examsw:sync-failed'));
      }
    }

    if (event.data.type === 'PENDING_COUNT') {
      window.dispatchEvent(new CustomEvent('examsw:pending', {
        detail: { count: event.data.count },
      }));
    }
  });

  // ── Helpers ───────────────────────────────────────────────────────────────────

  function triggerSync() {
    if (!navigator.serviceWorker.controller) return;
    navigator.serviceWorker.controller.postMessage({ type: 'SYNC_NOW' });
  }

  function triggerSyncWithJitter(maxDelayMs) {
    const delay = Math.floor(Math.random() * maxDelayMs);
    setTimeout(triggerSync, delay);
  }

  /**
   * Query how many answers are currently queued for offline sync.
   * The result arrives as a 'examsw:pending' CustomEvent.
   *
   * Usage in Vue/React:
   *   window.examSW.getPendingCount();
   *   window.addEventListener('examsw:pending', (e) => console.log(e.detail.count));
   */
  function getPendingCount() {
    if (!navigator.serviceWorker.controller) return;
    navigator.serviceWorker.controller.postMessage({ type: 'PENDING_COUNT' });
  }

  // Expose minimal API on window for the exam page to call manually if needed
  window.examSW = { triggerSync, getPendingCount };
})();
