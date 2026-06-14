// CloudBSS — end-to-end WhatsApp webhook load test (Category 26, the REAL one).
//
// The in-sandbox perf_suite.php measures the BRAIN only. This script measures the
// full path: nginx -> Laravel webhook -> queue -> BotBrain -> DB -> reply. Run it
// against STAGING (never production with real customer numbers).
//
//   k6 run -e BASE_URL=https://staging.cloudbss... -e WEBHOOK_PATH=/api/wa/webhook load/k6_webhook.js
//
// Ramps 100 -> 500 -> 1000 virtual users. Adjust stages/thresholds to taste.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const failRate = new Rate('failed_requests');
const replyTime = new Trend('reply_time_ms', true);

export const options = {
  scenarios: {
    ramp: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 100 },   // 100 concurrent
        { duration: '2m', target: 100 },
        { duration: '1m', target: 500 },    // 500 concurrent
        { duration: '2m', target: 500 },
        { duration: '1m', target: 1000 },   // 1000 concurrent
        { duration: '2m', target: 1000 },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1500'],   // 95% of webhook ACKs under 1.5s
    failed_requests: ['rate<0.01'],       // <1% failures
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost';
const WEBHOOK_PATH = __ENV.WEBHOOK_PATH || '/api/wa/webhook';

// realistic customer message mix
const MESSAGES = [
  'Rice', '2kg sugar and bread', 'Do you have rice and sugar?', '20 vimal 10 coke 5 rice',
  'sakar 2kg', 'show me oils', '2 sugar 3 milk 1 bread', 'give me 2 oils and 3 milk',
  'hello', 'checkout', 'tel and sakar', 'I want sugar and bread',
];

function payload(from, text) {
  // Evolution-API style inbound shape; adapt keys to your actual webhook contract.
  return JSON.stringify({
    event: 'messages.upsert',
    instance: 'staging',
    data: {
      key: { remoteJid: `${from}@s.whatsapp.net`, fromMe: false, id: `LOAD-${from}-${Date.now()}` },
      message: { conversation: text },
      pushName: `LoadTest ${from}`,
      messageTimestamp: Math.floor(Date.now() / 1000),
    },
  });
}

export default function () {
  // each VU is a distinct "customer" number
  const from = 256700000000 + __VU;
  const text = MESSAGES[Math.floor(Math.random() * MESSAGES.length)];

  const t0 = Date.now();
  const res = http.post(`${BASE_URL}${WEBHOOK_PATH}`, payload(from, text), {
    headers: { 'Content-Type': 'application/json' },
    tags: { name: 'wa_webhook' },
  });
  replyTime.add(Date.now() - t0);

  const ok = check(res, {
    'status 200/2xx': (r) => r.status >= 200 && r.status < 300,
    'no server error': (r) => r.status < 500,
  });
  failRate.add(!ok);

  // customers don't fire back-to-back; pace ~1 msg / 3-8s
  sleep(3 + Math.random() * 5);
}

// NOTE: webhooks usually ACK fast and process on a queue, so http_req_duration measures
// ACK latency, not reply latency. To measure true reply latency, assert on the outbound
// message (e.g. poll a test sink / mock WhatsApp endpoint) or read queue drain time from
// Horizon/worker metrics during the run.
