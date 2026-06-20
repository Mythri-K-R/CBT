'use strict';
/**
 * Artillery processor functions for CBT load tests.
 *
 * Referenced in YAML scenarios via:
 *   config:
 *     processor: "./functions.js"
 *   scenarios:
 *     - flow:
 *         - function: "setStudentData"
 */

const ANSWERS   = ['A', 'B', 'C', 'D'];
const STATUSES  = ['answered', 'not_answered', 'marked_review', 'answered_marked_review'];

// ── Student identity ──────────────────────────────────────────────────────────

/**
 * Assign a unique roll_number to this virtual user based on Artillery's
 * $loopCount or $uuid context variables.
 */
function setStudentData(context, events, done) {
  // $loopCount is per-VU iteration count — all VUs start at 0, so it cannot
  // be used as a unique VU identity. Use $uuid (Artillery's per-VU unique
  // identifier) to derive a stable numeric ID for this virtual user.
  // The last 5 hex digits of the UUID give 0–1,048,575 unique values;
  // modulo 20,000 maps into the seeded student pool LD-00001..LD-20000.
  const uid = context.vars.$uuid || '00000';
  const id  = (parseInt(uid.replace(/-/g, '').slice(-5), 16) % 20000) + 1;
  context.vars.roll_number  = `LD-${String(id).padStart(5, '0')}`;
  context.vars.question_id  = pickQuestion(context);
  context.vars.answer       = ANSWERS[Math.floor(Math.random() * ANSWERS.length)];
  context.vars.resp_status  = STATUSES[Math.floor(Math.random() * STATUSES.length)];
  context.vars.time_spent   = Math.floor(Math.random() * 90) + 10;
  return done();
}

/**
 * Cycle through question IDs based on iteration count.
 * Assumes 180 questions with IDs starting at TEST_QUESTION_OFFSET (default: 1).
 */
function pickQuestion(context) {
  const offset = parseInt(process.env.TEST_QUESTION_OFFSET || '1');
  const idx    = (context.vars.$loopCount || 0) % 180;
  return idx + offset;
}

/**
 * Advance to the next question after a save-response call.
 */
function nextQuestion(context, events, done) {
  const offset   = parseInt(process.env.TEST_QUESTION_OFFSET || '1');
  const current  = context.vars.question_id || offset;
  context.vars.question_id  = (current - offset + 1) % 180 + offset;
  context.vars.answer       = ANSWERS[Math.floor(Math.random() * ANSWERS.length)];
  context.vars.resp_status  = STATUSES[Math.floor(Math.random() * STATUSES.length)];
  context.vars.time_spent   = Math.floor(Math.random() * 90) + 10;
  return done();
}

/**
 * Extract token from login response and store in context.
 */
function saveToken(context, events, done) {
  if (context.vars.token) {
    context.vars.auth_header = `Bearer ${context.vars.token}`;
  }
  return done();
}

/**
 * Extract attempt UUID from the exam start response.
 * Supports both { data: { attempt_uuid } } and { attempt_uuid } response shapes.
 */
function saveAttemptUuid(context, events, done) {
  const uuid = context.vars.attempt_uuid_data
    || context.vars.attempt_uuid;
  context.vars.attempt_uuid = uuid;
  return done();
}

/**
 * Decide whether to inject a proctor event (1% probability).
 */
function maybeSendProctorEvent(context, events, done) {
  context.vars.send_proctor = Math.random() < 0.01;
  return done();
}

/**
 * Check if exam result is ready (not still processing).
 */
function checkResultReady(context, events, done) {
  context.vars.result_ready = !context.vars.processing;
  return done();
}

module.exports = {
  setStudentData,
  nextQuestion,
  saveToken,
  saveAttemptUuid,
  maybeSendProctorEvent,
  checkResultReady,
};
