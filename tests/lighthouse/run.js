#!/usr/bin/env node
'use strict';
/**
 * R-4.10-6 / R-4.14-4 — performance, accessibility, best-practice and SEO scores per template type,
 * desktop and mobile, measured with Lighthouse CI (@lhci/cli, pinned) against budgets.json.
 *
 * Pages: every template flagged `lighthouse` in lib/sites.js on the sites in LIGHTHOUSE_SITES
 * (default tmc,tmh: the umbrella site and one unit site), plus the English and Hindi home pages of
 * every other site. All sites share one theme, so a template measured on one site measures the
 * same code everywhere; the home pages differ in content and are measured per site.
 *
 * Method: `lhci collect` (Lighthouse, simulated throttling, LIGHTHOUSE_RUNS runs, default 1) and
 * `lhci upload --target=filesystem` per page and form factor. A page that misses the performance
 * budget is measured again LIGHTHOUSE_RECHECK_RUNS times (default 3) and judged on the median run,
 * because single-run performance scores vary by a few points on shared CI machines. Accessibility,
 * best-practice and SEO scores are deterministic and are never re-measured.
 *
 * Outside production (TMC_ENV != production) the "is-crawlable" audit is skipped: non-production
 * environments are deliberately "noindex". It is verified on production (docs/testing/thresholds.md).
 *
 * Environment: LIGHTHOUSE_SITES, LIGHTHOUSE_TEMPLATES (template ids; default all flagged), LIGHTHOUSE_FORM_FACTORS (desktop,mobile), LIGHTHOUSE_RUNS,
 * LIGHTHOUSE_RECHECK_RUNS, CHROME_PATH (default: Playwright's pinned Chromium), TMC_ENV.
 * Output: $QUALITY_OUT/lighthouse/{summary.json, report.html, <form factor>/<page>/reports/*.html}
 * Exit code 1 when any page misses a category budget.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { SITES, TEMPLATES, templateOnSite, url } = require('../lib/sites');
const { outDir, readJson, writeJson } = require('../lib/paths');
const { esc, page, status, table } = require('../lib/html');
const budgets = require('./budgets.json');

const OUT = outDir('lighthouse');
const LHCI = path.join(path.dirname(require.resolve('@lhci/cli/package.json')), 'src', 'cli.js');
const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];
const METRICS = Object.keys(budgets.metrics.desktop);
const list = (value, fallback) => (value || fallback).split(',').map((item) => item.trim()).filter(Boolean);
const FULL_SITES = list(process.env.LIGHTHOUSE_SITES, 'tmc,tmh');
const FORM_FACTORS = list(process.env.LIGHTHOUSE_FORM_FACTORS, 'desktop,mobile');
const RUNS = Math.max(1, Number(process.env.LIGHTHOUSE_RUNS || 1));
const RECHECK_RUNS = Math.max(0, Number(process.env.LIGHTHOUSE_RECHECK_RUNS || 3));
const ONLY_TEMPLATES = list(process.env.LIGHTHOUSE_TEMPLATES, '');
const PRODUCTION = process.env.TMC_ENV === 'production';

function chromePath() {
  if (process.env.CHROME_PATH) {
    return process.env.CHROME_PATH;
  }
  try {
    const candidate = require('@playwright/test').chromium.executablePath();
    return fs.existsSync(candidate) ? candidate : undefined;
  } catch (e) {
    return undefined;
  }
}

function pages() {
  const out = [];
  for (const site of SITES) {
    const full = FULL_SITES.includes(site.id);
    for (const template of TEMPLATES) {
      if ((ONLY_TEMPLATES.length && !ONLY_TEMPLATES.includes(template.id)) || !templateOnSite(template, site)) {
        continue;
      }
      if (template.lighthouse && (full || template.id === 'home' || template.id === 'home-hi')) {
        out.push({ site: site.id, template: template.id, label: template.label, url: url(site, template.path) });
      }
    }
  }
  return out;
}

function lhci(args, cwd) {
  const result = spawnSync(process.execPath, [LHCI, ...args], { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (result.status !== 0) {
    throw new Error(`lhci ${args[0]} failed (${result.status}): ${(result.stderr || result.stdout || '').trim().split('\n').slice(-5).join(' | ')}`);
  }
}

/** Measures one page in one form factor; returns the representative (median) run. */
function measure(target, formFactor, runs, dir) {
  fs.rmSync(dir, { recursive: true, force: true });
  fs.mkdirSync(dir, { recursive: true });
  const config = {
    ci: {
      collect: {
        url: [target.url],
        numberOfRuns: runs,
        chromePath: chromePath(),
        settings: {
          ...(formFactor === 'desktop' ? { preset: 'desktop' } : {}),
          onlyCategories: CATEGORIES,
          skipAudits: PRODUCTION ? [] : budgets.skipAuditsOutsideProduction,
          chromeFlags: '--headless=new --no-sandbox --disable-dev-shm-usage',
          maxWaitForLoad: 45000,
          locale: 'en-GB',
        },
      },
      upload: { target: 'filesystem', outputDir: path.join(dir, 'reports') },
    },
  };
  const configFile = path.join(dir, 'lighthouserc.json');
  fs.writeFileSync(configFile, JSON.stringify(config, null, 2));
  lhci(['collect', `--config=${configFile}`], dir);
  lhci(['upload', `--config=${configFile}`], dir);
  const manifest = readJson(path.join(dir, 'reports', 'manifest.json'), []);
  const entry = manifest.find((item) => item.isRepresentativeRun) || manifest[0];
  if (!entry) {
    throw new Error('no Lighthouse result');
  }
  const lhr = readJson(entry.jsonPath, {});
  const metrics = {};
  for (const id of METRICS) {
    const audit = (lhr.audits || {})[id];
    metrics[id] = audit && typeof audit.numericValue === 'number' ? audit.numericValue : null;
  }
  const runtimeError = lhr.runtimeError && lhr.runtimeError.code !== 'NO_ERROR' ? lhr.runtimeError.message : '';
  return {
    scores: entry.summary,
    metrics,
    runs,
    lighthouseVersion: lhr.lighthouseVersion || '',
    benchmarkIndex: lhr.environment ? lhr.environment.benchmarkIndex : null,
    report: path.relative(OUT, entry.htmlPath),
    runtimeError,
  };
}

function judge(result, formFactor) {
  const failures = [];
  const warnings = [];
  if (result.runtimeError) {
    failures.push(`Lighthouse error: ${result.runtimeError}`);
  }
  for (const category of CATEGORIES) {
    const min = budgets.categories[formFactor][category];
    const score = result.scores[category];
    if (typeof score !== 'number' || score < min) {
      failures.push(`${category} ${typeof score === 'number' ? Math.round(score * 100) : 'n/a'} < ${Math.round(min * 100)}`);
    }
  }
  for (const [metric, max] of Object.entries(budgets.metrics[formFactor])) {
    const value = result.metrics[metric];
    if (typeof value === 'number' && value > max) {
      warnings.push(`${metric} ${metric === 'cumulative-layout-shift' ? value.toFixed(3) : Math.round(value) + ' ms'} > ${max}${metric === 'cumulative-layout-shift' ? '' : ' ms'}`);
    }
  }
  return { failures, warnings };
}

function main() {
  const chrome = chromePath();
  console.log(`lighthouse: ${pages().length} pages × ${FORM_FACTORS.join('+')}, ${RUNS} run(s), recheck ${RECHECK_RUNS}; Chrome: ${chrome || 'system default'}`);
  const rows = [];
  for (const formFactor of FORM_FACTORS) {
    if (!budgets.categories[formFactor]) {
      throw new Error(`no budgets for form factor "${formFactor}"`);
    }
    for (const target of pages()) {
      const dir = path.join(OUT, formFactor, `${target.site}--${target.template}`);
      let result;
      let verdict;
      try {
        result = measure(target, formFactor, RUNS, dir);
        verdict = judge(result, formFactor);
        const onlyPerformance = verdict.failures.length && verdict.failures.every((failure) => failure.startsWith('performance '));
        if (onlyPerformance && RECHECK_RUNS > 1) {
          console.log(`  recheck ${formFactor} ${target.url}: ${verdict.failures.join('; ')}`);
          result = measure(target, formFactor, RECHECK_RUNS, `${dir}--recheck`);
          result.recheck = true;
          verdict = judge(result, formFactor);
        }
      } catch (error) {
        result = { scores: {}, metrics: {}, runs: RUNS, report: '', runtimeError: error.message };
        verdict = { failures: [error.message], warnings: [] };
      }
      const row = { ...target, formFactor, ...result, ...verdict, result: verdict.failures.length ? 'FAIL' : 'PASS' };
      rows.push(row);
      const scores = CATEGORIES.map((c) => `${c.replace('best-practices', 'bp')} ${typeof row.scores[c] === 'number' ? Math.round(row.scores[c] * 100) : '-'}`).join(', ');
      console.log(`  ${row.result} ${formFactor.padEnd(7)} ${target.site}/${target.template}: ${scores}${row.failures.length ? ' — ' + row.failures.join('; ') : ''}`);
    }
  }

  const versions = [...new Set(rows.map((row) => row.lighthouseVersion).filter(Boolean))];
  const siteSummary = {};
  for (const site of SITES) {
    const mine = rows.filter((row) => row.site === site.id);
    const failed = mine.filter((row) => row.result === 'FAIL');
    siteSummary[site.id] = {
      status: !mine.length ? 'NOT-RUN' : failed.length ? 'FAIL' : 'PASS',
      checks: mine.length,
      failures: failed.length,
      templates: [...new Set(mine.map((row) => row.template))],
      fullTemplateSet: FULL_SITES.includes(site.id),
      failed: failed.map((row) => ({ template: row.template, formFactor: row.formFactor, failures: row.failures })),
    };
  }
  const failedAll = rows.filter((row) => row.result === 'FAIL');
  const summary = {
    suite: 'lighthouse',
    title: 'Performance and quality scores (Lighthouse CI)',
    generatedAt: new Date().toISOString(),
    tool: `Lighthouse ${versions.join(', ') || '?'} via @lhci/cli ${require('@lhci/cli/package.json').version}`,
    status: !rows.length ? 'NOT-RUN' : failedAll.length ? 'FAIL' : 'PASS',
    budgets: budgets.categories,
    skippedAudits: PRODUCTION ? [] : budgets.skipAuditsOutsideProduction,
    referenceSites: FULL_SITES,
    totals: { measurements: rows.length, failed: failedAll.length, rechecked: rows.filter((row) => row.recheck).length },
    report: 'report.html',
    sites: siteSummary,
    pages: rows.map((row) => ({
      site: row.site,
      template: row.template,
      formFactor: row.formFactor,
      url: row.url,
      result: row.result,
      scores: row.scores,
      metrics: row.metrics,
      failures: row.failures,
      warnings: row.warnings,
      runs: row.runs,
      recheck: !!row.recheck,
      benchmarkIndex: row.benchmarkIndex,
      report: row.report,
    })),
  };
  writeJson(path.join(OUT, 'summary.json'), summary);

  const pct = (value) => (typeof value === 'number' ? String(Math.round(value * 100)) : '–');
  const ms = (value) => (typeof value === 'number' ? `${(value / 1000).toFixed(2)} s` : '–');
  let body = `<h1>Lighthouse report</h1>
<p class="meta">Generated ${esc(summary.generatedAt)} · ${esc(summary.tool)} · simulated throttling</p>
<p>Overall result: ${status(summary.status)}</p>
<ul class="summary">
  <li><strong>${rows.length}</strong> measurements</li>
  <li><strong>${failedAll.length}</strong> below budget</li>
  <li><strong>${summary.totals.rechecked}</strong> re-measured (median of ${RECHECK_RUNS})</li>
</ul>
<h2>Budgets</h2>
${table(
  'Minimum category scores (0–100)',
  [
    { label: 'Form factor', cell: (r) => esc(r[0]) },
    ...CATEGORIES.map((category) => ({ label: category, cell: (r) => esc(pct(r[1][category])) })),
  ],
  Object.entries(budgets.categories)
)}
<p>Metric limits (warnings only): ${esc(Object.entries(budgets.metrics).map(([ff, m]) => `${ff}: ${Object.entries(m).map(([k, v]) => `${k} ≤ ${v}`).join(', ')}`).join(' · '))}.
${summary.skippedAudits.length ? `Skipped outside production: ${esc(summary.skippedAudits.join(', '))} (non-production sites are deliberately not indexable).` : ''}
Justifications: <code>docs/testing/thresholds.md</code>.</p>
<h2>Results</h2>
${table(
  'Lighthouse scores per page and form factor',
  [
    { label: 'Page', cell: (r) => `${esc(r.site)} · ${esc(r.label)}<br><a href="${esc(r.url)}">${esc(r.url)}</a>` },
    { label: 'Form factor', cell: (r) => esc(r.formFactor) },
    { label: 'Result', cell: (r) => status(r.result) },
    ...CATEGORIES.map((category) => ({ label: category, cell: (r) => esc(pct(r.scores[category])) })),
    { label: 'LCP', cell: (r) => esc(ms(r.metrics['largest-contentful-paint'])) },
    { label: 'TBT', cell: (r) => esc(typeof r.metrics['total-blocking-time'] === 'number' ? `${Math.round(r.metrics['total-blocking-time'])} ms` : '–') },
    { label: 'CLS', cell: (r) => esc(typeof r.metrics['cumulative-layout-shift'] === 'number' ? r.metrics['cumulative-layout-shift'].toFixed(3) : '–') },
    { label: 'Notes', cell: (r) => esc([...r.failures, ...r.warnings.map((w) => `warning: ${w}`), r.recheck ? `median of ${r.runs} runs` : ''].filter(Boolean).join('; ')) },
    { label: 'Report', cell: (r) => (r.report ? `<a href="${esc(r.report)}">full report</a>` : '–') },
  ],
  rows
)}`;
  fs.writeFileSync(path.join(OUT, 'report.html'), page('Lighthouse report', body));
  console.log(`lighthouse: ${summary.status} — ${rows.length} measurements, ${failedAll.length} below budget → ${path.join(OUT, 'report.html')}`);
  process.exit(summary.status === 'FAIL' ? 1 : 0);
}

main();
