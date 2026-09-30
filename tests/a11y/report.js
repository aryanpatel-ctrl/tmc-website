#!/usr/bin/env node
'use strict';
/**
 * Accessibility report: combines the per-page axe results written by axe.spec.js with the
 * Playwright outcome of each scan into
 *   $QUALITY_OUT/a11y/summary.json   (read by the Go-Live acceptance report)
 *   $QUALITY_OUT/a11y/report.html    (self-contained, for reviewers and auditors)
 *
 * A page that was not scanned (navigation error, wrong HTTP status) counts as a failure.
 * Exit code 0 always: the gate itself is the Playwright run; this only reports.
 */
const fs = require('fs');
const path = require('path');
const { PROJECTS, TARGETS, WCAG_TAGS, resultName } = require('./targets');
const { SITES } = require('../lib/sites');
const { outDir, readJson, writeJson } = require('../lib/paths');
const { readResults, passed } = require('../lib/pw-results');
const { esc, page, status, table } = require('../lib/html');

const OUT = outDir('a11y');
const run = readResults(path.join(OUT, 'results.json'));

function outcomeFor(target, project) {
  if (!run) {
    return null;
  }
  const high = target.contrast === 'high';
  return run.tests.find(
    (test) =>
      test.project === project &&
      test.tags.includes(`site-${target.site.id}`) &&
      test.tags.includes(`template-${target.template.id}`) &&
      test.tags.includes('high-contrast') === high
  );
}

const rows = [];
for (const target of TARGETS) {
  for (const project of PROJECTS) {
    const result = readJson(path.join(OUT, 'axe', project, resultName(target)));
    const outcome = outcomeFor(target, project);
    let state;
    let note = '';
    if (!outcome && !result) {
      state = 'NOT-RUN';
      note = 'not part of this run';
    } else if (!result) {
      state = 'FAIL';
      note = (outcome.errors[0] || 'page could not be scanned').split('\n')[0];
    } else if (result.violations.length) {
      state = 'FAIL';
    } else if (outcome && !passed(outcome)) {
      state = 'FAIL';
      note = (outcome.errors[0] || '').split('\n')[0];
    } else {
      state = 'PASS';
    }
    rows.push({
      site: target.site.id,
      template: target.template.id,
      label: target.template.label,
      contrast: target.contrast,
      project,
      url: target.url,
      state,
      note,
      violations: result ? result.violations : [],
      incomplete: result ? result.incomplete.length : 0,
      axeVersion: result ? result.axeVersion : '',
    });
  }
}

const executed = rows.filter((row) => row.state !== 'NOT-RUN');
const siteSummary = {};
for (const site of SITES) {
  const mine = executed.filter((row) => row.site === site.id);
  const failures = mine.filter((row) => row.state === 'FAIL');
  siteSummary[site.id] = {
    status: !mine.length ? 'NOT-RUN' : failures.length ? 'FAIL' : 'PASS',
    checks: mine.length,
    failures: failures.length,
    violations: mine.reduce((sum, row) => sum + row.violations.reduce((n, v) => n + v.nodes.length, 0), 0),
    templates: [...new Set(mine.map((row) => row.template))].length,
    failed: failures.map((row) => ({ template: row.template, project: row.project, contrast: row.contrast, rules: row.violations.map((v) => v.id), note: row.note })),
  };
}
const overallFailures = executed.filter((row) => row.state === 'FAIL');
const axeVersion = (executed.find((row) => row.axeVersion) || {}).axeVersion || '';
const summary = {
  suite: 'a11y',
  title: 'Accessibility (axe-core, WCAG 2.0/2.1/2.2 A + AA)',
  generatedAt: new Date().toISOString(),
  tool: `axe-core ${axeVersion}`.trim(),
  tags: WCAG_TAGS,
  status: !executed.length ? 'NOT-RUN' : overallFailures.length ? 'FAIL' : 'PASS',
  totals: {
    scans: executed.length,
    failedScans: overallFailures.length,
    violationNodes: executed.reduce((sum, row) => sum + row.violations.reduce((n, v) => n + v.nodes.length, 0), 0),
    needsReview: executed.reduce((sum, row) => sum + row.incomplete, 0),
  },
  report: 'report.html',
  sites: siteSummary,
};
writeJson(path.join(OUT, 'summary.json'), summary);

/* ---------------------------------------------------------------- HTML */

const byRule = new Map();
for (const row of executed) {
  for (const violation of row.violations) {
    const entry = byRule.get(violation.id) || { violation, pages: [] };
    entry.pages.push({ row, nodes: violation.nodes });
    byRule.set(violation.id, entry);
  }
}

let body = `<h1>Accessibility report</h1>
<p class="meta">Generated ${esc(summary.generatedAt)} · ${esc(summary.tool)} · rules tagged ${esc(WCAG_TAGS.join(', '))} · widths 1280 px and 360 px</p>
<p>Overall result: ${status(summary.status)}</p>
<ul class="summary">
  <li><strong>${summary.totals.scans}</strong> page scans</li>
  <li><strong>${summary.totals.failedScans}</strong> failed scans</li>
  <li><strong>${summary.totals.violationNodes}</strong> failing elements</li>
  <li><strong>${summary.totals.needsReview}</strong> items for manual review (axe "incomplete")</li>
</ul>
<p>Automated testing covers only part of WCAG 2.2. Success criteria that need human judgement are
verified with the manual checklist in <code>docs/testing/quality-gates.md</code>.</p>
<h2>Result by website</h2>
${table(
  'Accessibility result by website',
  [
    { label: 'Website', cell: (r) => esc(r.name) },
    { label: 'Result', cell: (r) => status(r.status) },
    { label: 'Scans', cell: (r) => esc(r.checks) },
    { label: 'Failed scans', cell: (r) => esc(r.failures) },
  ],
  SITES.map((site) => ({ name: site.name, ...siteSummary[site.id] }))
)}`;

body += '<h2>Violations by rule</h2>';
if (!byRule.size) {
  body += '<p>No WCAG violations were found.</p>';
}
for (const [id, entry] of byRule) {
  body += `<h3>${esc(id)} (${esc(entry.violation.impact)}): ${esc(entry.violation.help)}</h3>
<p>WCAG: ${esc(entry.violation.tags.join(', '))} · <a href="${esc(entry.violation.helpUrl)}">rule documentation</a></p>`;
  body += table(
    `Pages failing ${id}`,
    [
      { label: 'Page', cell: (p) => `${esc(p.row.site)} · ${esc(p.row.label)}${p.row.contrast === 'high' ? ' (high contrast)' : ''} · ${esc(p.row.project)}<br><a href="${esc(p.row.url)}">${esc(p.row.url)}</a>` },
      { label: 'Elements', cell: (p) => p.nodes.map((node) => `<code>${esc(node.target)}</code><pre>${esc(node.html)}</pre>`).join('') },
    ],
    entry.pages
  );
}

body += '<h2>All scans</h2>';
body += table(
  'Every page scan',
  [
    { label: 'Website', cell: (r) => esc(r.site) },
    { label: 'Template', cell: (r) => `${esc(r.label)}${r.contrast === 'high' ? ' (high contrast)' : ''}<br><a href="${esc(r.url)}">${esc(r.url)}</a>` },
    { label: 'Width', cell: (r) => esc(r.project.replace('a11y-', '') + ' px') },
    { label: 'Result', cell: (r) => status(r.state) },
    { label: 'Rules / note', cell: (r) => esc(r.violations.map((v) => v.id).join(', ') || r.note) },
  ],
  rows
);

fs.writeFileSync(path.join(OUT, 'report.html'), page('Accessibility report', body));
console.log(`a11y report: ${summary.status} — ${summary.totals.scans} scans, ${summary.totals.failedScans} failed → ${path.join(OUT, 'report.html')}`);
