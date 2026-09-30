#!/usr/bin/env node
'use strict';
/**
 * Lists the failed and flaky tests of a Playwright suite run (reads $QUALITY_OUT/<suite>/results.json).
 *
 *   node bin/failures.js e2e|visual|a11y
 */
const path = require('path');
const { outDir } = require('../lib/paths');
const { readResults } = require('../lib/pw-results');

const suite = process.argv[2] || 'e2e';
const run = readResults(path.join(outDir(suite), 'results.json'));
if (!run) {
  console.error(`no results for suite "${suite}" in ${outDir(suite)}`);
  process.exit(2);
}
const stats = run.stats;
console.log(`${suite}: ${stats.expected || 0} passed, ${stats.unexpected || 0} failed, ${stats.flaky || 0} flaky, ${stats.skipped || 0} skipped`);
for (const test of run.tests.filter((t) => t.status === 'unexpected' || t.status === 'flaky')) {
  console.log(`\n[${test.status}] [${test.project}] ${test.title}`);
  for (const error of test.errors.slice(0, 1)) {
    console.log('  ' + error.split('\n').slice(0, 12).join('\n  '));
  }
}
for (const error of run.errors) {
  console.log(`\n[run error] ${error.split('\n')[0]}`);
}
process.exit(stats.unexpected ? 1 : 0);
