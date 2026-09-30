#!/usr/bin/env node
'use strict';
/**
 * R-4.8-6 (W3C standards) — HTML validity of every template on every website, checked with the
 * Nu HTML Checker (vnu, the W3C validator engine; pinned npm package vnu-jar, needs Java 11+).
 *
 *   node html/validate.js            all six sites
 *   QUALITY_SITES=tmc,tmh node html/validate.js
 *
 * Every page is fetched as served (so markup added by WordPress and plugins is included), saved to
 * $QUALITY_OUT/html/pages/, and validated in one vnu run. Errors fail the gate (exit code 1);
 * warnings are reported but do not fail it.
 *
 * Output: $QUALITY_OUT/html/{summary.json, results.json, report.html}
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const vnuJar = require('vnu-jar');
const { SITES, TEMPLATES, templateOnSite, url } = require('../lib/sites');
const { mapLimit, request } = require('../lib/http');
const { outDir, writeJson } = require('../lib/paths');
const { esc, page, status, table } = require('../lib/html');

const OUT = outDir('html');
const PAGES = path.join(OUT, 'pages');
const wantedSites = (process.env.QUALITY_SITES || '').split(',').map((s) => s.trim()).filter(Boolean);
const sites = wantedSites.length ? SITES.filter((site) => wantedSites.includes(site.id)) : SITES;

function vnuVersion() {
  const result = spawnSync('java', ['-jar', String(vnuJar), '--version'], { encoding: 'utf8' });
  if (result.error || result.status !== 0) {
    return null;
  }
  return (result.stdout || result.stderr).trim();
}

async function main() {
  const version = vnuVersion();
  if (!version) {
    console.error('Java is required for the Nu HTML Checker (java -jar vnu.jar). Install a JRE 11+ and retry.');
    process.exit(2);
  }
  fs.rmSync(PAGES, { recursive: true, force: true });
  fs.mkdirSync(PAGES, { recursive: true });

  const targets = [];
  for (const site of sites) {
    for (const template of TEMPLATES) {
      if (!templateOnSite(template, site)) {
        continue;
      }
      targets.push({ site: site.id, template: template.id, label: template.label, url: url(site, template.path), expected: template.status });
    }
  }

  // 1. Fetch every page as served.
  await mapLimit(targets, 6, async (target) => {
    const response = await request(target.url, { timeout: 30000 });
    target.status = response.status;
    target.fetchError = response.error || (response.status !== target.expected ? `HTTP ${response.status} (expected ${target.expected})` : '');
    const type = String((response.headers && response.headers['content-type']) || '');
    if (!response.error && type.includes('text/html')) {
      target.file = path.join(PAGES, `${target.site}--${target.template}.html`);
      fs.writeFileSync(target.file, response.body);
    } else if (!target.fetchError) {
      target.fetchError = `not HTML (${type || 'no content type'})`;
    }
  });

  // 2. Validate all saved pages in one run.
  const files = targets.filter((target) => target.file).map((target) => target.file);
  const byFile = new Map(files.map((file) => [path.resolve(file), []]));
  if (files.length) {
    const result = spawnSync('java', ['-Xss1024k', '-jar', String(vnuJar), '--format', 'json', '--stdout', '--exit-zero-always', ...files], {
      encoding: 'utf8',
      maxBuffer: 256 * 1024 * 1024,
    });
    if (result.error || result.status !== 0) {
      console.error('vnu failed to run:', result.error ? result.error.message : result.stderr);
      process.exit(2);
    }
    const report = JSON.parse(result.stdout || '{"messages":[]}');
    for (const message of report.messages || []) {
      let file = '';
      try {
        file = path.resolve(decodeURIComponent(new URL(message.url).pathname));
      } catch (e) {
        file = '';
      }
      const bucket = byFile.get(file);
      if (bucket) {
        bucket.push(message);
      } else {
        // Messages without a page (e.g. vnu internal problems) are attached to every page as errors.
        for (const list of byFile.values()) {
          list.push({ ...message, type: 'error', message: `validator: ${message.message}` });
        }
      }
    }
  }

  // 3. Classify.
  for (const target of targets) {
    const messages = target.file ? byFile.get(path.resolve(target.file)) || [] : [];
    target.errors = messages
      .filter((m) => m.type === 'error' || m.type === 'non-document-error' || (m.type === 'info' && m.subType === 'fatal'))
      .map((m) => ({ message: m.message, line: m.lastLine || 0, column: m.firstColumn || m.lastColumn || 0, extract: (m.extract || '').slice(0, 300) }));
    target.warnings = messages
      .filter((m) => m.type === 'info' && m.subType === 'warning')
      .map((m) => ({ message: m.message, line: m.lastLine || 0, extract: (m.extract || '').slice(0, 300) }));
    target.result = target.fetchError || target.errors.length ? 'FAIL' : 'PASS';
    delete target.file;
  }

  const siteSummary = {};
  for (const site of SITES) {
    const mine = targets.filter((target) => target.site === site.id);
    const failed = mine.filter((target) => target.result === 'FAIL');
    siteSummary[site.id] = {
      status: !mine.length ? 'NOT-RUN' : failed.length ? 'FAIL' : 'PASS',
      checks: mine.length,
      failures: failed.length,
      errors: mine.reduce((sum, target) => sum + target.errors.length + (target.fetchError ? 1 : 0), 0),
      warnings: mine.reduce((sum, target) => sum + target.warnings.length, 0),
      failed: failed.map((target) => ({ template: target.template, errors: target.errors.length, note: target.fetchError })),
    };
  }
  const failedAll = targets.filter((target) => target.result === 'FAIL');
  const summary = {
    suite: 'html',
    title: 'HTML validity (W3C Nu HTML Checker)',
    generatedAt: new Date().toISOString(),
    tool: `vnu ${version}`,
    status: !targets.length ? 'NOT-RUN' : failedAll.length ? 'FAIL' : 'PASS',
    totals: {
      pages: targets.length,
      failedPages: failedAll.length,
      errors: targets.reduce((sum, target) => sum + target.errors.length, 0),
      warnings: targets.reduce((sum, target) => sum + target.warnings.length, 0),
    },
    report: 'report.html',
    sites: siteSummary,
  };
  writeJson(path.join(OUT, 'results.json'), targets);
  writeJson(path.join(OUT, 'summary.json'), summary);

  // 4. HTML report: errors grouped by message, then every page.
  const groups = new Map();
  for (const target of targets) {
    for (const error of target.errors) {
      const entry = groups.get(error.message) || [];
      entry.push({ target, error });
      groups.set(error.message, entry);
    }
  }
  let body = `<h1>HTML validity report</h1>
<p class="meta">Generated ${esc(summary.generatedAt)} · ${esc(summary.tool)} · ${summary.totals.pages} pages</p>
<p>Overall result: ${status(summary.status)}</p>
<ul class="summary">
  <li><strong>${summary.totals.pages}</strong> pages validated</li>
  <li><strong>${summary.totals.failedPages}</strong> pages with errors</li>
  <li><strong>${summary.totals.errors}</strong> errors</li>
  <li><strong>${summary.totals.warnings}</strong> warnings (not failing)</li>
</ul>
<h2>Result by website</h2>
${table(
  'HTML validity by website',
  [
    { label: 'Website', cell: (r) => esc(r.name) },
    { label: 'Result', cell: (r) => status(r.status) },
    { label: 'Pages', cell: (r) => esc(r.checks) },
    { label: 'Pages with errors', cell: (r) => esc(r.failures) },
    { label: 'Warnings', cell: (r) => esc(r.warnings) },
  ],
  SITES.map((site) => ({ name: site.name, ...siteSummary[site.id] }))
)}
<h2>Errors by message</h2>`;
  if (!groups.size) {
    body += '<p>No validation errors.</p>';
  }
  for (const [message, entries] of groups) {
    body += `<h3>${esc(message)}</h3>`;
    body += table(
      `Pages with: ${message}`,
      [
        { label: 'Page', cell: (e) => `${esc(e.target.site)} · ${esc(e.target.label)}<br><a href="${esc(e.target.url)}">${esc(e.target.url)}</a>` },
        { label: 'Line', cell: (e) => esc(e.error.line) },
        { label: 'Source', cell: (e) => `<pre>${esc(e.error.extract)}</pre>` },
      ],
      entries
    );
  }
  body += '<h2>All pages</h2>';
  body += table(
    'Every validated page',
    [
      { label: 'Website', cell: (t) => esc(t.site) },
      { label: 'Template', cell: (t) => `${esc(t.label)}<br><a href="${esc(t.url)}">${esc(t.url)}</a>` },
      { label: 'Result', cell: (t) => status(t.result) },
      { label: 'Errors', cell: (t) => esc(t.fetchError || t.errors.length) },
      { label: 'Warnings', cell: (t) => esc(t.warnings.length) },
    ],
    targets
  );
  const warningGroups = new Map();
  for (const target of targets) {
    for (const warning of target.warnings) {
      warningGroups.set(warning.message, (warningGroups.get(warning.message) || 0) + 1);
    }
  }
  body += '<h2>Warnings</h2>';
  body += table(
    'Warnings by message (reported, not failing)',
    [
      { label: 'Message', cell: (w) => esc(w[0]) },
      { label: 'Occurrences', cell: (w) => esc(w[1]) },
    ],
    [...warningGroups.entries()]
  );
  fs.writeFileSync(path.join(OUT, 'report.html'), page('HTML validity report', body));

  console.log(`html: ${summary.status} — ${summary.totals.pages} pages, ${summary.totals.errors} errors, ${summary.totals.warnings} warnings → ${path.join(OUT, 'report.html')}`);
  for (const [message, entries] of groups) {
    console.log(`  ERROR ×${entries.length}: ${message}`);
  }
  process.exit(summary.status === 'FAIL' ? 1 : 0);
}

main().catch((error) => {
  console.error(error);
  process.exit(2);
});
