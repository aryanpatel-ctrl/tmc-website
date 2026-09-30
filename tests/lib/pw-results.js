'use strict';
/**
 * Reads a Playwright JSON report (results.json) into a flat list of test outcomes.
 *
 *   { title, file, project, tags: ['a11y', 'site-tmc', ...], status, errors: [text] }
 *
 * status is Playwright's outcome: expected (passed), unexpected (failed), flaky (passed on retry)
 * or skipped. Colour codes are stripped from error messages.
 */
const { readJson } = require('./paths');

// eslint-disable-next-line no-control-regex
const ANSI = /\u001b\[[0-9;]*m/g;

function flatten(report) {
  const out = [];
  const walk = (suite, parents) => {
    const titles = suite.title && suite.title !== suite.file ? [...parents, suite.title] : parents;
    for (const spec of suite.specs || []) {
      for (const test of spec.tests || []) {
        const last = (test.results || [])[test.results.length - 1] || {};
        out.push({
          title: [...titles, spec.title].join(' › '),
          file: spec.file || suite.file,
          project: test.projectName,
          tags: (spec.tags || []).map((tag) => String(tag).replace(/^@/, '')),
          status: test.status,
          annotations: test.annotations || [],
          errors: (last.errors || []).map((error) => String(error.message || error.value || '').replace(ANSI, '')),
        });
      }
    }
    for (const child of suite.suites || []) {
      walk(child, titles);
    }
  };
  for (const suite of (report && report.suites) || []) {
    walk(suite, []);
  }
  return out;
}

/** Tests from results.json, or null when the file does not exist (suite did not run). */
function readResults(file) {
  const report = readJson(file);
  if (!report) {
    return null;
  }
  return { tests: flatten(report), stats: report.stats || {}, errors: (report.errors || []).map((e) => String(e.message || '').replace(ANSI, '')) };
}

const passed = (test) => test.status === 'expected' || test.status === 'flaky';
const failed = (test) => test.status === 'unexpected';

module.exports = { failed, flatten, passed, readResults };
