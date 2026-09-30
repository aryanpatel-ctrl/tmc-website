// Capacity test (tender §4.7 R-4.7-9 peak load, §4.14 R-4.14-4) — run by scripts/perf/capacity-test.sh
// with k6 in a container on the stack's own network. Two open-model scenarios run together:
//   pages   anonymous visitors at a fixed arrival rate over every site's home page (English and
//           Hindi) and the main listings — the traffic the page cache is built for;
//   search  uncached, fully dynamic requests (site search) at a lower rate — PHP + database capacity.
// An arrival-rate executor keeps sending at the target rate however slow the server gets, so a
// saturated server shows up as latency, errors and dropped iterations instead of hiding.

import http from 'k6/http';
import { check } from 'k6';
import { Rate } from 'k6/metrics';

const env = (name, fallback) => (__ENV[name] !== undefined && __ENV[name] !== '' ? __ENV[name] : fallback);
const DOMAIN = env('BASE_DOMAIN', 'tmc.localhost');
const TARGET_IP = env('TARGET_IP', '');
const RATE = parseInt(env('RATE', '100'), 10);
const SEARCH_RATE = parseInt(env('SEARCH_RATE', '5'), 10);
const DURATION = env('DURATION', '2m');
const P95_PAGES = parseInt(env('P95_PAGES_MS', '800'), 10);
const P95_SEARCH = parseInt(env('P95_SEARCH_MS', '3000'), 10);
const MIN_HIT = parseFloat(env('MIN_CACHE_HIT', '0.9'));
const PREFIXES = ['', 'tmh.', 'hbchrcv.', 'mpmmcc.', 'hbchrcmzp.', 'hbchpunjab.'];

function seconds(text) {
  const m = /^(\d+)(s|m|h)$/.exec(text);
  if (!m) throw new Error(`DURATION must look like 90s, 2m or 1h (got ${text})`);
  return parseInt(m[1], 10) * { s: 1, m: 60, h: 3600 }[m[2]];
}
const DURATION_S = seconds(DURATION);

const PAGES = [];
for (const prefix of PREFIXES) {
  PAGES.push(`http://${prefix}${DOMAIN}/`, `http://${prefix}${DOMAIN}/hi/`);
}
for (const path of ['/tenders/', '/careers/', '/events/', '/departments/', '/sitemap/']) {
  PAGES.push(`http://${DOMAIN}${path}`);
}
const SEARCH_TERMS = ['cancer', 'oncology', 'appointment', 'tender', 'radiation', 'screening', 'research', 'nursing'];

const hosts = {};
if (TARGET_IP) {
  for (const prefix of PREFIXES) hosts[`${prefix}${DOMAIN}`] = TARGET_IP;
}

const cacheHit = new Rate('page_cache_hit');

export const options = {
  hosts,
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
  scenarios: {
    pages: {
      executor: 'constant-arrival-rate',
      exec: 'pages',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: Math.max(20, Math.ceil(RATE / 2)),
      maxVUs: Math.max(100, RATE * 4),
    },
    search: {
      executor: 'constant-arrival-rate',
      exec: 'search',
      rate: SEARCH_RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: Math.max(5, SEARCH_RATE * 2),
      maxVUs: Math.max(20, SEARCH_RATE * 20),
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{scenario:pages}': [`p(95)<${P95_PAGES}`],
    'http_req_duration{scenario:search}': [`p(95)<${P95_SEARCH}`],
    'http_reqs{scenario:pages}': ['count>0'],
    'http_reqs{scenario:search}': ['count>0'],
    page_cache_hit: [`rate>${MIN_HIT}`],
    // Iterations the arrival rate asked for but no VU was free to start: the server could not keep up.
    dropped_iterations: [`count<${Math.max(1, Math.floor((RATE + SEARCH_RATE) * DURATION_S * 0.01))}`],
  },
};

// Warm the page cache once, as normal traffic would, so the test measures the steady state.
export function setup() {
  for (const url of PAGES) {
    http.get(url);
    http.get(url);
  }
}

export function pages() {
  const url = PAGES[Math.floor(Math.random() * PAGES.length)];
  const res = http.get(url, { tags: { name: 'page' } });
  check(res, { 'page: HTTP 200': (r) => r.status === 200 });
  cacheHit.add(res.headers['X-Tmc-Cache'] === 'HIT');
}

export function search() {
  const term = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];
  const res = http.get(`http://${DOMAIN}/?s=${term}`, { tags: { name: 'search' } });
  check(res, { 'search: HTTP 200': (r) => r.status === 200 });
}

function ms(v) {
  return v === undefined ? 'n/a' : `${Math.round(v)} ms`;
}
function thresholdResult(metric) {
  const t = metric && metric.thresholds ? Object.values(metric.thresholds) : [];
  if (!t.length) return 'n/a';
  return t.every((x) => x.ok) ? 'PASS' : 'FAIL';
}

export function handleSummary(data) {
  const m = data.metrics;
  const runS = data.state.testRunDurationMs / 1000;
  const row = (label, scenario, limit) => {
    const d = m[`http_req_duration{scenario:${scenario}}`];
    const n = m[`http_reqs{scenario:${scenario}}`];
    const count = n ? n.values.count : 0;
    const v = d ? d.values : {};
    return `| ${label} | ${count} | ${(count / DURATION_S).toFixed(1)} | ${ms(v.med)} | ${ms(v['p(95)'])} | ${ms(v['p(99)'])} | ${ms(v.max)} | p95 < ${limit} ms | ${thresholdResult(d)} |`;
  };
  const failed = m.http_req_failed ? m.http_req_failed.values.rate : 0;
  const hit = m.page_cache_hit ? m.page_cache_hit.values.rate : 0;
  const dropped = m.dropped_iterations ? m.dropped_iterations.values.count : 0;
  const allOk = Object.values(m).every((x) => !x.thresholds || Object.values(x.thresholds).every((t) => t.ok));
  const md = [
    `# Capacity test — ${allOk ? 'PASS' : 'FAIL'}`,
    '',
    `Open-model load for ${DURATION} (${runS.toFixed(0)} s including warm-up): ${RATE} page views/s spread over ${PAGES.length} pages ` +
      `(${PREFIXES.length} sites × English and Hindi home pages, main listings) plus ${SEARCH_RATE} uncached searches/s.`,
    '',
    '| Scenario | Requests | Requests/s | Median | p95 | p99 | Max | Threshold | Result |',
    '|---|---|---|---|---|---|---|---|---|',
    row('Anonymous page views (page cache)', 'pages', P95_PAGES),
    row('Site search (uncached, PHP + database)', 'search', P95_SEARCH),
    '',
    '| Measure | Value | Threshold | Result |',
    '|---|---|---|---|',
    `| Failed requests (non-2xx/3xx or network error) | ${(failed * 100).toFixed(2)} % | < 1 % | ${thresholdResult(m.http_req_failed)} |`,
    `| Page views served from the page cache | ${(hit * 100).toFixed(1)} % | > ${(MIN_HIT * 100).toFixed(0)} % | ${thresholdResult(m.page_cache_hit)} |`,
    `| Dropped iterations (server could not keep up with the arrival rate) | ${dropped} | < 1 % of planned | ${thresholdResult(m.dropped_iterations)} |`,
    '',
  ].join('\n');
  return {
    '/out/capacity-report.md': md,
    '/out/capacity-summary.json': JSON.stringify(data, null, 2),
    stdout: `${md}\n`,
  };
}
