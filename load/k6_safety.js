// CloudBSS — production-safety chaos load test (Phase 2A–2E under load).
// Floods DUPLICATE message ids and DOUBLE checkouts at the webhook, then you
// verify correctness with the SQL in PRODUCTION-SAFETY.md (k6 can't read the DB).
//
//   k6 run -e BASE_URL=https://staging -e WEBHOOK_PATH=/api/webhook/whatsapp/evolution \
//          -e INSTANCE=staging-instance load/k6_safety.js
//
// Run against STAGING only.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

const fail = new Rate('failed_requests');

export const options = {
  scenarios: {
    // 1) duplicate flood: the SAME message id delivered many times, concurrently
    dup_flood: {
      executor: 'ramping-vus', exec: 'dupFlood',
      startVUs: 0, stages: [
        { duration: '30s', target: 200 },
        { duration: '1m',  target: 1000 },
        { duration: '30s', target: 0 },
      ],
    },
    // 2) double checkout: two location messages fired ~together per customer
    double_checkout: {
      executor: 'constant-vus', exec: 'doubleCheckout',
      vus: 100, duration: '2m', startTime: '30s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1500'],
    failed_requests: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const WEBHOOK_PATH = __ENV.WEBHOOK_PATH || '/api/webhook/whatsapp/evolution';
const INSTANCE = __ENV.INSTANCE || 'staging-instance';

function payload(from, text, messageId) {
  return JSON.stringify({
    event: 'messages.upsert',
    instance: INSTANCE,
    data: {
      key: { remoteJid: `${from}@s.whatsapp.net`, fromMe: false, id: messageId },
      message: { conversation: text },
      pushName: `Chaos ${from}`,
      messageTimestamp: Math.floor(Date.now() / 1000),
    },
  });
}

function post(from, text, messageId) {
  const res = http.post(`${BASE_URL}${WEBHOOK_PATH}`, payload(from, text, messageId), {
    headers: { 'Content-Type': 'application/json' }, tags: { name: 'wa_webhook' },
  });
  fail.add(!check(res, { 'no server error': (r) => r.status < 500 }));
  return res;
}

// Every VU hammers ONE fixed (from, messageId) -> dedup must keep cart at qty 1.
export function dupFlood() {
  const from = 256700000000 + (__VU % 50);          // 50 distinct customers
  const messageId = `DUP-${from}-FIXED`;            // SAME id forever per customer
  post(from, 'Rice', messageId);
  sleep(0.2);
}

// Two identical location messages (same id) -> exactly one order per checkout.
export function doubleCheckout() {
  const from = 256701000000 + __VU;
  const checkoutId = `CHK-${from}-${__ITER}`;
  // (assumes a cart already exists; in a full test, seed via prior 'Rice' + 'checkout')
  post(from, 'Kisaasi', checkoutId);
  post(from, 'Kisaasi', checkoutId);                // duplicate delivery of the location
  sleep(3);
}
