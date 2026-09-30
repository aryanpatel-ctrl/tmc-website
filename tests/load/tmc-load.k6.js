/**
 * R-4.14-4 / R-4.7-9 — load test of the six TMC websites (k6, pinned image grafana/k6 in
 * .github/workflows/load-test.yml).
 *
 * Simulated visitors browse all six websites in English and Hindi with think time between pages:
 * home pages, content pages, tender, event, career and doctor listings, single items, search and
 * the 404 page, plus the theme stylesheet (static file path). Every response is checked for the
 * expected status and a complete HTML document without PHP error output.
 *
 * NEVER run this against production during opening hours, and never from a pull request: it is
 * started by hand (workflow_dispatch) against UAT. See docs/testing/quality-gates.md#load-test.
 *
 * Environment (k6 -e NAME=value):
 *   TMC_BASE_DOMAIN   network base domain (required; UAT: tmc.100-79-142-44.sslip.io)
 *   TMC_SCHEME        http (default) or https
 *   LOAD_PROFILE      smoke | average | peak | stress (default smoke) — see PROFILES below
 *   LOAD_RESOLVE_IP   optional IP address all six host names resolve to (e.g. a Docker host)
 *   LOAD_SUMMARY      file for the machine-readable result (default results/load/k6-summary.json)
 *
 * Thresholds: docs/testing/thresholds.md. k6 exits with code 99 when a threshold is crossed.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

const BASE = (__ENV.TMC_BASE_DOMAIN || '').trim();
const SCHEME = (__ENV.TMC_SCHEME || 'http').trim();
const PROFILE = (__ENV.LOAD_PROFILE || 'smoke').trim();
const SUMMARY_FILE = __ENV.LOAD_SUMMARY || 'results/load/k6-summary.json';

if (!BASE) {
  throw new Error('TMC_BASE_DOMAIN is required, e.g. -e TMC_BASE_DOMAIN=tmc.100-79-142-44.sslip.io');
}
if (SCHEME !== 'http' && SCHEME !== 'https') {
  throw new Error(`TMC_SCHEME must be http or https (got "${SCHEME}")`);
}

const SITES = ['', 'tmh.', 'hbchrcv.', 'mpmmcc.', 'hbchrcmzp.', 'hbchpunjab.'].map((sub) => `${sub}${BASE}`);

/**
 * Traffic mix (weights add up to 100). Paths exist on every site after scripts/setup.sh; the
 * single items are the clearly labelled sample content. Search terms vary so the search results
 * are not all served from one cached page.
 */
const PAGES = [
  { name: 'home', weight: 22, path: () => '/' },
  { name: 'home-hi', weight: 8, path: () => '/hi/' },
  { name: 'page', weight: 10, path: () => pick(['/patient-care/', '/patient-care/patient-guide/', '/about-us/', '/accessibility-statement/']) },
  { name: 'page-hi', weight: 4, path: () => '/hi/rogi-dekhbhal/' },
  { name: 'tenders', weight: 10, path: () => pick(['/tenders/', '/tenders/?view=archive']) },
  { name: 'tender', weight: 4, path: () => '/tenders/sample-tender-laboratory-consumables/' },
  { name: 'events', weight: 6, path: () => pick(['/events/', '/events/?view=calendar', '/events/?view=past']) },
  { name: 'event', weight: 3, path: () => '/events/sample-cme-session/' },
  { name: 'careers', weight: 6, path: () => '/careers/' },
  { name: 'doctors', weight: 6, path: () => pick(['/doctors/', '/doctors/?doctor_name=sample']) },
  { name: 'doctor', weight: 3, path: () => '/doctors/sample-profile-a/' },
  { name: 'departments', weight: 3, path: () => '/departments/' },
  { name: 'search', weight: 5, path: () => `/?s=${encodeURIComponent(pick(['cancer', 'tender', 'appointment', 'radiotherapy', 'patient', 'donate', 'careers']))}` },
  { name: 'not-found', weight: 1, status: 404, path: () => `/load-test-missing-${Math.floor(Math.random() * 1e6)}/` },
  { name: 'static', weight: 9, html: false, path: () => '/wp-content/themes/tmc/assets/css/main.css' },
];
const TOTAL_WEIGHT = PAGES.reduce((sum, page) => sum + page.weight, 0);

/**
 * Load profiles. Visitor numbers are provisional until TMC states the expected peak traffic
 * (RTM "Findings to raise with TMC", item 7); change them only together with
 * docs/testing/thresholds.md.
 */
const PROFILES = {
  // Proves the script and the environment work: 2 visitors for 1 minute.
  smoke: { executor: 'constant-vus', vus: 2, duration: '1m' },
  // A normal busy day.
  average: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '2m', target: 50 },
      { duration: '10m', target: 50 },
      { duration: '1m', target: 0 },
    ],
    gracefulRampDown: '30s',
  },
  // Result day / recruitment notice / tender closing: four times the average.
  peak: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '3m', target: 200 },
      { duration: '10m', target: 200 },
      { duration: '2m', target: 0 },
    ],
    gracefulRampDown: '30s',
  },
  // Finds the breaking point; thresholds are reported but the run is expected to cross them.
  stress: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '5m', target: 200 },
      { duration: '5m', target: 400 },
      { duration: '5m', target: 600 },
      { duration: '2m', target: 0 },
    ],
    gracefulRampDown: '30s',
  },
};

/** Response-time limits per profile, in milliseconds (docs/testing/thresholds.md). */
const LIMITS = {
  smoke: { p95: 1500, p99: 3000, errors: 0.01 },
  average: { p95: 1500, p99: 3000, errors: 0.01 },
  peak: { p95: 3000, p99: 5000, errors: 0.01 },
  stress: { p95: 3000, p99: 5000, errors: 0.05 },
};

if (!PROFILES[PROFILE]) {
  throw new Error(`LOAD_PROFILE must be one of ${Object.keys(PROFILES).join(', ')} (got "${PROFILE}")`);
}
const LIMIT = LIMITS[PROFILE];

const pageErrors = new Rate('tmc_page_errors');

const hosts = {};
if (__ENV.LOAD_RESOLVE_IP) {
  for (const host of SITES) {
    hosts[host] = __ENV.LOAD_RESOLVE_IP;
  }
}

export const options = {
  scenarios: { visitors: PROFILES[PROFILE] },
  hosts,
  userAgent: 'TMC-QualityGates-Load/1.0 (+docs/testing/quality-gates.md)',
  // Keep redirects visible: every URL in the mix answers directly.
  maxRedirects: 0,
  discardResponseBodies: false,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    http_req_failed: [`rate<${LIMIT.errors}`],
    http_req_duration: [`p(95)<${LIMIT.p95}`, `p(99)<${LIMIT.p99}`],
    // Dynamic pages only (the stylesheet is a static file and would flatter the numbers).
    'http_req_duration{kind:html}': [`p(95)<${LIMIT.p95}`],
    tmc_page_errors: [`rate<${LIMIT.errors}`],
    checks: ['rate>0.99'],
    // Per-page statistics for the report (k6 only keeps sub-metrics that have a threshold;
    // "max>=0" always holds, so these never fail the run).
    ...Object.fromEntries(PAGES.map((page) => [`http_req_duration{page:${page.name}}`, ['max>=0']])),
  },
};

function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

function choosePage() {
  let roll = Math.random() * TOTAL_WEIGHT;
  for (const page of PAGES) {
    roll -= page.weight;
    if (roll < 0) {
      return page;
    }
  }
  return PAGES[0];
}

// The 404 page is expected; tell k6 not to count it as a failed request.
const EXPECTED = { 200: http.expectedStatuses(200), 404: http.expectedStatuses(404) };

export default function visitor() {
  const page = choosePage();
  const host = pick(SITES);
  const status = page.status || 200;
  const html = page.html !== false;
  const response = http.get(`${SCHEME}://${host}${page.path()}`, {
    tags: { page: page.name, kind: html ? 'html' : 'static', name: page.name },
    responseCallback: EXPECTED[status],
  });
  const body = typeof response.body === 'string' ? response.body : '';
  const ok = check(response, {
    [`${page.name}: status ${status}`]: (r) => r.status === status,
    [`${page.name}: complete response`]: () => (html ? /<\/html>\s*$/i.test(body) : body.length > 0),
    [`${page.name}: no PHP error output`]: () => !/(Fatal error|Parse error|Warning: |Notice: |Deprecated: )/.test(body),
  });
  pageErrors.add(!ok, { page: page.name });
  // Think time: people read a page for a few seconds before the next click.
  sleep(1 + Math.random() * 4);
}

function metricLine(data, name) {
  const metric = data.metrics[name];
  if (!metric || !metric.values) {
    return `${name}: –`;
  }
  const v = metric.values;
  if (metric.type === 'trend') {
    return `${name}: avg ${Math.round(v.avg)} ms, p95 ${Math.round(v['p(95)'])} ms, p99 ${Math.round(v['p(99)'])} ms, max ${Math.round(v.max)} ms`;
  }
  if (metric.type === 'rate') {
    return `${name}: ${(v.rate * 100).toFixed(2)} %`;
  }
  return `${name}: ${v.count !== undefined ? v.count : JSON.stringify(v)}`;
}

/** Machine-readable result for tests/load/report.js plus a short text summary. */
export function handleSummary(data) {
  const result = {
    profile: PROFILE,
    baseDomain: BASE,
    scheme: SCHEME,
    limits: LIMIT,
    sites: SITES,
    mix: PAGES.map((page) => ({ name: page.name, weight: page.weight })),
    data,
  };
  const lines = [
    '',
    `TMC load test — profile ${PROFILE} — ${SCHEME}://${BASE} (+5 unit sites)`,
    metricLine(data, 'http_reqs'),
    metricLine(data, 'http_req_duration'),
    metricLine(data, 'http_req_failed'),
    metricLine(data, 'tmc_page_errors'),
    metricLine(data, 'checks'),
    '',
  ];
  for (const [name, metric] of Object.entries(data.metrics)) {
    for (const [expression, outcome] of Object.entries(metric.thresholds || {})) {
      if (expression === 'max>=0') {
        continue; // reporting-only sub-metric
      }
      lines.push(`${outcome && outcome.ok === false ? 'FAIL' : 'ok  '}  ${name} ${expression}`);
    }
  }
  lines.push('');
  return {
    stdout: lines.join('\n'),
    [SUMMARY_FILE]: JSON.stringify(result, null, 2),
  };
}
