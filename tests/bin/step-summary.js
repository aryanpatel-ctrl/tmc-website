#!/usr/bin/env node
'use strict';
/**
 * Markdown summary of one quality gate, for the GitHub job summary ($GITHUB_STEP_SUMMARY).
 *
 *   node bin/step-summary.js e2e|visual|a11y|html|lighthouse|links|load
 *
 * Playwright suites are read from <suite>/results.json, the others from <suite>/summary.json.
 * Prints to stdout; never fails (exit code 0), so it can run after a failed gate.
 */
const path = require('path');
const { outDir, readJson } = require('../lib/paths');
const { readResults, failed } = require('../lib/pw-results');

const TITLES = {
  e2e: 'E2E — Chromium, Firefox, WebKit at 360/768/1280 px',
  visual: 'Visual regression (Chromium)',
  a11y: 'Accessibility — axe-core WCAG 2.0/2.1/2.2 A + AA',
  html: 'HTML validity — Nu HTML Checker',
  lighthouse: 'Lighthouse — desktop and mobile budgets',
  links: 'Links and orphaned pages',
  load: 'Load test (k6)',
};

const suite = process.argv[2] || '';
const dir = outDir(suite || 'unknown');
const lines = [`### ${TITLES[suite] || suite}`, ''];

if (['e2e', 'visual', 'a11y'].includes(suite)) {
  const run = readResults(path.join(dir, 'results.json'));
  if (!run) {
    lines.push('**NOT RUN** — no Playwright results.');
  } else {
    const s = run.stats;
    const bad = run.tests.filter(failed);
    const missing = run.tests.filter((t) => t.annotations.some((a) => a.type === 'baseline-missing')).length;
    lines.push(`**${bad.length || run.errors.length ? 'FAIL' : missing ? 'PENDING' : 'PASS'}** — ${s.expected || 0} passed, ${s.unexpected || 0} failed, ${s.flaky || 0} flaky, ${s.skipped || 0} skipped${missing ? ` (${missing} without an approved baseline)` : ''}.`);
    for (const test of bad.slice(0, 25)) {
      lines.push(`- \`${test.project}\` ${test.title.replace(/\|/g, '\\|')}`);
    }
    if (bad.length > 25) {
      lines.push(`- … ${bad.length - 25} more (see the HTML report in the artifact)`);
    }
    for (const error of run.errors.slice(0, 5)) {
      lines.push(`- run error: ${error.split('\n')[0]}`);
    }
  }
} else {
  const summary = readJson(path.join(dir, 'summary.json'));
  if (!summary) {
    lines.push('**NOT RUN** — no summary.json.');
  } else {
    lines.push(`**${summary.status}** — ${summary.tool || ''}`);
    if (summary.evidence) {
      lines.push('', summary.evidence);
    }
    if (summary.totals) {
      lines.push('', Object.entries(summary.totals).map(([key, value]) => `${key}: ${typeof value === 'number' && !Number.isInteger(value) ? Number(value.toFixed(3)) : value}`).join(' · '));
    }
    if (summary.sites) {
      lines.push('', '| Website | Result |', '|---|---|');
      for (const [site, data] of Object.entries(summary.sites)) {
        lines.push(`| ${site} | ${data.status}${data.failures ? ` (${data.failures} failing)` : ''} |`);
      }
    }
  }
}
lines.push('');
console.log(lines.join('\n'));
