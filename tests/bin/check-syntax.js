#!/usr/bin/env node
'use strict';
/**
 * Static check of the quality tooling (no website needed): JavaScript syntax of every file,
 * valid JSON, and every Playwright suite loads and lists its tests.
 *
 *   npm run check
 *
 * k6 scripts (*.k6.js) are ES modules for the k6 runtime, not Node; they are checked with
 * `k6 inspect` in .github/workflows/quality.yml instead.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const SKIP_DIRS = new Set(['node_modules', 'results', 'test-results', 'playwright-report', '.lighthouseci', 'baselines']);
let failed = 0;

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) {
        walk(path.join(dir, entry.name), out);
      }
    } else {
      out.push(path.join(dir, entry.name));
    }
  }
  return out;
}

const files = walk(ROOT);
for (const file of files.filter((f) => f.endsWith('.js') && !f.endsWith('.k6.js'))) {
  const result = spawnSync(process.execPath, ['--check', file], { encoding: 'utf8' });
  if (result.status !== 0) {
    console.error(`syntax error: ${path.relative(ROOT, file)}\n${result.stderr}`);
    failed += 1;
  }
}
for (const file of files.filter((f) => f.endsWith('.json'))) {
  try {
    JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    console.error(`invalid JSON: ${path.relative(ROOT, file)}: ${error.message}`);
    failed += 1;
  }
}

const cli = path.join(path.dirname(require.resolve('@playwright/test/package.json')), 'cli.js');
for (const suite of ['e2e', 'visual', 'a11y']) {
  const result = spawnSync(process.execPath, [cli, 'test', '--list', '--reporter=list'], {
    cwd: ROOT,
    encoding: 'utf8',
    env: { ...process.env, QUALITY_SUITE: suite, QUALITY_OUT: path.join(ROOT, 'results', '.check') },
  });
  const total = (result.stdout.match(/Total: (\d+) tests? in (\d+) files?/) || [])[0];
  if (result.status !== 0 || !total) {
    console.error(`playwright suite "${suite}" does not load:\n${result.stderr || result.stdout}`);
    failed += 1;
  } else {
    console.log(`${suite}: ${total}`);
  }
}
fs.rmSync(path.join(ROOT, 'results', '.check'), { recursive: true, force: true });

if (failed) {
  console.error(`quality tooling check FAILED (${failed})`);
  process.exit(1);
}
console.log('quality tooling check passed');
