#!/usr/bin/env node
'use strict';
/**
 * Load test report: turns the k6 result (tests/load/tmc-load.k6.js → k6-summary.json) into
 *   $QUALITY_OUT/load/summary.json   (read by the Go-Live acceptance report)
 *   $QUALITY_OUT/load/report.html    (self-contained, for reviewers)
 *
 *   node load/report.js [path/to/k6-summary.json]   (default $QUALITY_OUT/load/k6-summary.json)
 *
 * LOAD_EXIT_CODE: exit code of `k6 run` (99 = a threshold was crossed). A missing result or a
 * non-zero exit code is a FAIL. Exit code of this script: 0 (reporting only), 2 on a crash.
 */
const fs = require('fs');
const path = require('path');
const { outDir, readJson, writeJson } = require('../lib/paths');
const { esc, page, status, table } = require('../lib/html');

const OUT = outDir('load');
const SOURCE = path.resolve(process.argv[2] || path.join(OUT, 'k6-summary.json'));
const EXIT_CODE = process.env.LOAD_EXIT_CODE === undefined || process.env.LOAD_EXIT_CODE === '' ? null : Number(process.env.LOAD_EXIT_CODE);

const ms = (value) => (typeof value === 'number' ? `${Math.round(value)} ms` : '–');
const pct = (value) => (typeof value === 'number' ? `${(value * 100).toFixed(2)} %` : '–');

function main() {
  const result = readJson(SOURCE);
  const generatedAt = new Date().toISOString();
  if (!result || !result.data || !result.data.metrics) {
    const summary = {
      suite: 'load',
      title: 'Load test (k6)',
      generatedAt,
      status: 'FAIL',
      evidence: `No k6 result at ${SOURCE}${EXIT_CODE !== null ? ` (k6 exit code ${EXIT_CODE})` : ''}.`,
    };
    writeJson(path.join(OUT, 'summary.json'), summary);
    fs.writeFileSync(path.join(OUT, 'report.html'), page('Load test report', `<h1>Load test report</h1><p>${status('FAIL')} ${esc(summary.evidence)}</p>`));
    console.log(`load: FAIL — ${summary.evidence}`);
    return;
  }

  const metrics = result.data.metrics;
  const values = (name) => (metrics[name] && metrics[name].values) || {};
  const thresholds = [];
  for (const [name, metric] of Object.entries(metrics)) {
    for (const [expression, outcome] of Object.entries(metric.thresholds || {})) {
      if (expression === 'max>=0') {
        continue; // reporting-only sub-metrics
      }
      thresholds.push({ metric: name, expression, ok: !(outcome && outcome.ok === false) });
    }
  }
  const crossed = thresholds.filter((t) => !t.ok);
  const failed = crossed.length > 0 || (EXIT_CODE !== null && EXIT_CODE !== 0);

  const perPage = (result.mix || [])
    .map((item) => ({ name: item.name, weight: item.weight, values: values(`http_req_duration{page:${item.name}}`) }))
    .filter((item) => Object.keys(item.values).length);

  const duration = values('http_req_duration');
  const durationSeconds = result.data.state && result.data.state.testRunDurationMs ? Math.round(result.data.state.testRunDurationMs / 1000) : null;
  const requests = values('http_reqs').count;
  const peakVus = values('vus_max').max || values('vus_max').value;
  const evidence =
    `Profile "${result.profile}" against ${result.scheme}://${result.baseDomain} and the five unit sites: ` +
    `${requests ?? '?'} requests in ${durationSeconds ?? '?'} s, up to ${peakVus ?? '?'} concurrent visitors; ` +
    `p95 ${ms(duration['p(95)'])} (limit ${result.limits.p95} ms), p99 ${ms(duration['p(99)'])} (limit ${result.limits.p99} ms), ` +
    `failed requests ${pct(values('http_req_failed').rate)} (limit ${pct(result.limits.errors)}); ` +
    `${crossed.length ? `thresholds crossed: ${crossed.map((t) => `${t.metric} ${t.expression}`).join(', ')}` : 'all thresholds met'}` +
    `${EXIT_CODE !== null && EXIT_CODE !== 0 && !crossed.length ? `; k6 exit code ${EXIT_CODE}` : ''}.`;

  const summary = {
    suite: 'load',
    title: 'Load test (k6)',
    generatedAt,
    tool: 'k6 (tests/load/tmc-load.k6.js)',
    status: failed ? 'FAIL' : 'PASS',
    profile: result.profile,
    target: `${result.scheme}://${result.baseDomain}`,
    limits: result.limits,
    evidence,
    totals: {
      requests: requests ?? null,
      durationSeconds,
      peakVus: peakVus ?? null,
      p95: duration['p(95)'] ?? null,
      p99: duration['p(99)'] ?? null,
      failedRate: values('http_req_failed').rate ?? null,
      checksRate: values('checks').rate ?? null,
    },
    thresholds,
    pages: perPage.map((item) => ({ name: item.name, weight: item.weight, p95: item.values['p(95)'] ?? null, avg: item.values.avg ?? null, max: item.values.max ?? null })),
    report: 'report.html',
  };
  writeJson(path.join(OUT, 'summary.json'), summary);

  const body = `<h1>Load test report</h1>
<p class="meta">Generated ${esc(generatedAt)} · ${esc(summary.tool)} · profile <strong>${esc(result.profile)}</strong> · ${esc(summary.target)}</p>
<p>Result: ${status(summary.status)}</p>
<p>${esc(evidence)}</p>
<ul class="summary">
  <li><strong>${esc(requests ?? '–')}</strong> requests</li>
  <li><strong>${esc(peakVus ?? '–')}</strong> concurrent visitors (max)</li>
  <li><strong>${esc(ms(duration['p(95)']))}</strong> p95 response time</li>
  <li><strong>${esc(pct(values('http_req_failed').rate))}</strong> failed requests</li>
</ul>
<h2>Thresholds</h2>
${table(
  'k6 thresholds (docs/testing/thresholds.md)',
  [
    { label: 'Metric', cell: (t) => `<code>${esc(t.metric)}</code>` },
    { label: 'Threshold', cell: (t) => `<code>${esc(t.expression)}</code>` },
    { label: 'Result', cell: (t) => status(t.ok ? 'PASS' : 'FAIL') },
  ],
  thresholds,
  'No thresholds in the result.'
)}
<h2>Response times per page type</h2>
${table(
  'Response time per page type',
  [
    { label: 'Page type', cell: (p) => esc(p.name) },
    { label: 'Share of traffic', cell: (p) => esc(`${p.weight} %`) },
    { label: 'Average', cell: (p) => esc(ms(p.values.avg)) },
    { label: 'Median', cell: (p) => esc(ms(p.values.med)) },
    { label: 'p95', cell: (p) => esc(ms(p.values['p(95)'])) },
    { label: 'p99', cell: (p) => esc(ms(p.values['p(99)'])) },
    { label: 'Max', cell: (p) => esc(ms(p.values.max)) },
  ],
  perPage,
  'No per-page data.'
)}
<p>The load generator ran on the machine named in the CI run. When it shares a host with the website
(the UAT runner), the figures include the generator's own CPU use and are conservative.</p>`;
  fs.writeFileSync(path.join(OUT, 'report.html'), page('Load test report', body));
  console.log(`load: ${summary.status} — ${evidence}`);
}

try {
  main();
} catch (error) {
  console.error(error);
  process.exit(2);
}
