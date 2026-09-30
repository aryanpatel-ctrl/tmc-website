'use strict';
/**
 * Where every quality tool writes its results.
 *
 *   $QUALITY_OUT/<suite>/   (default tests/results/<suite>/)
 *
 * Suites: e2e, visual, a11y, lighthouse, html, links, acceptance. The acceptance report reads the
 * summary.json of each suite from the same root, so all tools must agree on this layout.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(process.env.QUALITY_OUT || path.join(__dirname, '..', 'results'));

function outDir(suite) {
  const dir = path.join(ROOT, suite);
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

function writeJson(file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n');
}

function readJson(file, fallback = null) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    return fallback;
  }
}

module.exports = { ROOT, outDir, readJson, writeJson };
