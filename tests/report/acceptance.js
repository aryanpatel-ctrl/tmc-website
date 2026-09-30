#!/usr/bin/env node
'use strict';
/**
 * R-7.1-1 — Go-Live acceptance report, one per website, in the order of the SOW §7.1 checklist.
 *
 * Reads what the quality gates wrote under $QUALITY_OUT (each suite may be missing; it is then
 * reported as NOT RUN, never as passed):
 *   e2e/results.json, visual/results.json      Playwright (functional, cross-browser, visual)
 *   a11y/summary.json                          axe-core WCAG 2.2 AA
 *   lighthouse/summary.json                    Lighthouse CI budgets
 *   html/summary.json                          Nu HTML Checker
 *   links/summary.json, links/inventory/*.json link crawler, published URL inventory
 *   load/summary.json                          k6 load test (manual run against UAT)
 *
 * Items that need people or external bodies (design sign-off, VAPT, STQC / Safe-to-Host, content
 * sign-off, documentation acceptance) are MANUAL: the report lists the evidence to attach.
 *
 * Output: $QUALITY_OUT/acceptance/{index.html, <site>.html, summary.json, summary.md}
 * summary.md is written for the GitHub job summary. Exit code: 0 (reporting only), 2 on a crash.
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { SITES, TEMPLATES, host } = require('../lib/sites');
const { ROOT, outDir, readJson, writeJson } = require('../lib/paths');
const { readResults, passed, failed } = require('../lib/pw-results');
const { esc, page, status, table } = require('../lib/html');

const OUT = outDir('acceptance');
const suite = (name) => readJson(path.join(ROOT, name, 'summary.json'));

function buildInfo() {
  let commit = process.env.GITHUB_SHA || '';
  if (!commit) {
    try {
      commit = execSync('git rev-parse HEAD', { cwd: __dirname, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
    } catch (e) {
      commit = '';
    }
  }
  const run = process.env.GITHUB_RUN_ID && process.env.GITHUB_REPOSITORY ? `${process.env.GITHUB_SERVER_URL || 'https://github.com'}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}` : '';
  return {
    generatedAt: new Date().toISOString(),
    commit,
    ref: process.env.GITHUB_REF_NAME || '',
    run,
    environment: process.env.TMC_ENV || 'ci',
    baseDomain: SITES.length ? host(SITES[0]) : '',
  };
}

/** Playwright suite outcome for one site: tests tagged with the site plus shared (untagged) tests. */
function playwrightFor(results, siteId) {
  if (!results) {
    return null;
  }
  const siteTests = results.tests.filter((test) => test.tags.includes(`site-${siteId}`));
  const shared = results.tests.filter((test) => !test.tags.some((tag) => tag.startsWith('site-')));
  const count = (tests) => ({
    total: tests.length,
    passed: tests.filter(passed).length,
    failed: tests.filter(failed).length,
    skipped: tests.filter((test) => test.status === 'skipped').length,
    flaky: tests.filter((test) => test.status === 'flaky').length,
    failures: tests.filter(failed).slice(0, 10).map((test) => `[${test.project}] ${test.title}`),
  });
  return { site: count(siteTests), shared: count(shared), projects: [...new Set(results.tests.map((test) => test.project))].sort() };
}

function e2eItem(results, siteId) {
  const outcome = playwrightFor(results, siteId);
  if (!outcome) {
    return { status: 'NOT-RUN', evidence: 'The E2E suite did not run.' };
  }
  const { site, shared, projects } = outcome;
  const failedTotal = site.failed + shared.failed;
  const ranSomething = site.total - site.skipped + shared.total - shared.skipped > 0;
  return {
    status: !ranSomething ? 'NOT-RUN' : failedTotal ? 'FAIL' : 'PASS',
    evidence: `Site-specific tests: ${site.passed}/${site.total} passed${site.flaky ? ` (${site.flaky} flaky)` : ''}. Shared template tests (run on the TMC site; all sites use the same templates): ${shared.passed}/${shared.total} passed${shared.flaky ? ` (${shared.flaky} flaky)` : ''}. Browsers and widths: ${projects.join(', ') || '–'}.`,
    details: [...site.failures, ...shared.failures],
  };
}

function visualItem(results, siteId) {
  if (!results) {
    return { status: 'NOT-RUN', evidence: 'The visual regression suite did not run.' };
  }
  const mine = results.tests.filter((test) => test.tags.includes(`site-${siteId}`));
  const missing = mine.filter((test) => test.annotations.some((a) => a.type === 'baseline-missing'));
  const bad = mine.filter(failed);
  const compared = mine.filter((test) => test.status !== 'skipped');
  let state = 'PASS';
  if (bad.length) {
    state = 'FAIL';
  } else if (!mine.length) {
    state = 'NOT-RUN';
  } else if (missing.length || !compared.length) {
    state = 'PENDING';
  }
  return {
    status: state,
    evidence: `${compared.filter(passed).length}/${mine.length} screenshots match the approved baselines${missing.length ? `; ${missing.length} have no approved baseline yet` : ''}.`,
    details: bad.slice(0, 10).map((test) => `[${test.project}] ${test.title}`),
  };
}

function suiteItem(summary, siteId, describe) {
  if (!summary || !summary.sites || !summary.sites[siteId] || summary.sites[siteId].status === 'NOT-RUN') {
    return { status: 'NOT-RUN', evidence: 'This gate did not run for this website.' };
  }
  const site = summary.sites[siteId];
  const describeFailure = (item) =>
    Object.entries(item)
      .filter(([, value]) => value !== '' && value !== undefined && value !== null && !(Array.isArray(value) && !value.length))
      .map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(', ') : value}`)
      .join('; ');
  return { status: site.status, evidence: describe(site, summary), details: (site.failed || []).slice(0, 10).map(describeFailure) };
}

function inventoryFor(siteId) {
  const data = readJson(path.join(ROOT, 'links', 'inventory', `published-${siteId}.json`));
  if (!data || !Array.isArray(data.urls)) {
    return null;
  }
  const counts = {};
  for (const item of data.urls) {
    const key = `${item.type} (${item.lang || '–'})`;
    counts[key] = (counts[key] || 0) + 1;
  }
  return { total: data.urls.length, counts };
}

function checklist(site, sources) {
  const { e2e, visual, a11y, lighthouse, html, links, load } = sources;
  const inv = inventoryFor(site.id);
  const items = [];
  const add = (id, clause, title, result, manual = '') => items.push({ id, clause, title, ...result, manual });

  add('design', '§7.1 (a), §5, R-5-1', 'Conformance to the approved design system, IA and templates (Annexures A–C)', { status: 'MANUAL', evidence: `Visual regression: ${visual.status} — ${visual.evidence}` }, 'Design sign-off by TMC against the Figma (Annexure C) and the deviation register. Visual regression only proves the approved look has not changed.');
  add('visual', 'R-5-1', 'Visual regression against approved baselines', visual);
  add('links', '§7.1 (b), R-4.11-4', 'Zero broken links and no orphaned pages (automated scan)', suiteItem(links, site.id, (s) => `${s.pagesCrawled} pages crawled; ${s.brokenInternal} broken internal links, ${s.missingFragments} links to missing fragments, ${s.orphans} orphaned pages of ${s.published ?? '?'} published (${s.inventorySource}); ${s.externalWarnings} external links did not answer (warnings).`));
  add('wcag', '§7.1 (c), R-4.9-1', 'WCAG 2.2 Level AA — automated scan of every template (axe-core)', suiteItem(a11y, site.id, (s, all) => `${s.checks} scans (${s.templates} templates × 1280/360 px${site.id === 'tmc' ? ' + high-contrast view' : ''}); ${s.failures} failed. ${all.tool}.`));
  add('wcag-manual', '§7.1 (c), R-4.9-1', 'WCAG 2.2 AA / GIGW 3.0 — manual audit (screen readers, keyboard, content)', { status: 'MANUAL', evidence: 'Checklist in docs/testing/quality-gates.md (manual accessibility audit).' }, 'Signed manual audit record (NVDA + Firefox, TalkBack, VoiceOver; keyboard only; zoom 200 %/400 %).');
  add('responsive', '§7.1 (d), R-4.9-2, R-4.9-3, R-4.14-2/3', 'Responsive layouts and major browsers (Chromium, Firefox, WebKit at 360/768/1280 px); functional tests', e2e);
  add('performance', '§7.1 (e), R-4.10-6', 'Performance thresholds per template, desktop and mobile (Lighthouse)', suiteItem(lighthouse, site.id, (s, all) => `${s.checks} measurements (${s.fullTemplateSet ? 'every template type' : 'home pages; other templates are shared and measured on ' + all.referenceSites.join(', ')}); ${s.failures} below budget. Budgets: ${Object.entries(all.budgets).map(([ff, b]) => `${ff} perf ≥ ${Math.round(b.performance * 100)}, a11y ≥ ${Math.round(b.accessibility * 100)}, BP ≥ ${Math.round(b['best-practices'] * 100)}, SEO ≥ ${Math.round(b.seo * 100)}`).join('; ')}.`));
  add('load', '§7.1 (e), R-4.14-4, R-4.7-9', 'Load test against thresholds (k6, UAT)', load && load.status ? { status: load.status, evidence: load.evidence || '' } : { status: 'MANUAL', evidence: 'Run the Load test workflow against UAT (docs/testing/quality-gates.md) and attach its report.' }, load && load.status ? '' : 'k6 report from the manual "Load test (UAT)" workflow.');
  add('html', 'R-4.8-6', 'W3C HTML validity of every template (Nu HTML Checker)', suiteItem(html, site.id, (s, all) => `${s.checks} pages; ${s.failures} with errors; ${s.warnings} warnings (not failing). ${all.tool}.`));
  add('security', '§7.1 (f), R-4.8-7', 'VAPT by a CERT-In empanelled agency: all observations closed; segregation evidence', { status: 'MANUAL', evidence: 'External certification.' }, 'VAPT report and closure report; security architecture document with segregation evidence (R-4.8-1, R-4.8-8).');
  add('certificates', '§7.1 (g), R-4.8-7', 'Safe-to-Host / STQC certificate', { status: 'MANUAL', evidence: 'External certification.' }, 'Certificate copy with validity dates.');
  add(
    'migration',
    '§7.1 (h), R-4.11-3',
    'Content migration complete, correctly rendered and placed in the IA',
    {
      status: 'MANUAL',
      evidence: inv
        ? `Published content inventory: ${inv.total} URLs (${Object.entries(inv.counts).map(([k, v]) => `${k}: ${v}`).join(', ')}). Rendering and placement are verified automatically by the link scan (every URL reachable from the menus and answering) — ${links && links.sites && links.sites[site.id] ? links.sites[site.id].status : 'NOT RUN'}.`
        : 'No published content inventory in this run.',
    },
    'Migration confirmation signed by TMC (content inventory template, R-4.11-5).'
  );
  add('docs', '§7.1 (i), §4.15', 'Documentation delivered and accepted', { status: 'MANUAL', evidence: 'Documentation in docs/ of the repository (handed over in editable form).' }, 'Written acceptance of the documentation set by TMC.');
  return items;
}

function verdict(items) {
  if (items.some((item) => item.status === 'FAIL')) {
    return 'NOT READY';
  }
  if (items.some((item) => item.status === 'NOT-RUN' || item.status === 'PENDING')) {
    return 'INCOMPLETE';
  }
  return 'READY FOR MANUAL SIGN-OFF';
}

function main() {
  const build = buildInfo();
  const e2eResults = readResults(path.join(ROOT, 'e2e', 'results.json'));
  const visualResults = readResults(path.join(ROOT, 'visual', 'results.json'));
  const summaries = { a11y: suite('a11y'), lighthouse: suite('lighthouse'), html: suite('html'), links: suite('links'), load: suite('load') };

  const reports = [];
  for (const site of SITES) {
    const items = checklist(site, {
      e2e: e2eItem(e2eResults, site.id),
      visual: visualItem(visualResults, site.id),
      ...summaries,
    });
    const result = verdict(items);
    reports.push({ site, items, verdict: result });

    const counts = items.reduce((acc, item) => ({ ...acc, [item.status]: (acc[item.status] || 0) + 1 }), {});
    const suiteLinks = [
      ['E2E (Playwright)', '../e2e/html-report/index.html'],
      ['Visual regression (Playwright)', '../visual/html-report/index.html'],
      ['Accessibility (axe)', '../a11y/report.html'],
      ['Lighthouse', '../lighthouse/report.html'],
      ['HTML validity', '../html/report.html'],
      ['Links and orphans', '../links/report.html'],
    ].filter(([, href]) => fs.existsSync(path.join(OUT, href)));
    const body = `<h1>Go-Live acceptance report</h1>
<p class="meta"><strong>${esc(site.name)}</strong> · ${esc(host(site))}</p>
<p class="meta">Generated ${esc(build.generatedAt)} · environment ${esc(build.environment)} · commit <code>${esc(build.commit.slice(0, 12) || 'unknown')}</code>${build.ref ? ` (${esc(build.ref)})` : ''}${build.run ? ` · <a href="${esc(build.run)}">CI run</a>` : ''}</p>
<p>Automated verdict: <strong>${esc(result)}</strong></p>
<ul class="summary">
  ${['PASS', 'FAIL', 'PENDING', 'NOT-RUN', 'MANUAL'].map((key) => `<li><strong>${counts[key] || 0}</strong> ${status(key)}</li>`).join('\n  ')}
</ul>
<p>Checklist of SOW §7.1 (Go-Live acceptance per website). PASS and FAIL come from the automated
quality gates of this CI run. MANUAL items need a document or a signature; the report lists the
evidence to attach. A site is ready for Go-Live only when every automated item passes and every
manual item has been signed off by TMC.</p>
<h2>Checklist</h2>
${table(
  'SOW §7.1 acceptance checklist',
  [
    { label: 'Clause', cell: (i) => esc(i.clause) },
    { label: 'Criterion', cell: (i) => esc(i.title) },
    { label: 'Result', cell: (i) => status(i.status) },
    { label: 'Evidence', cell: (i) => `${esc(i.evidence)}${i.details && i.details.length ? `<br><small>${i.details.map(esc).join('<br>')}</small>` : ''}` },
    { label: 'To attach / sign', cell: (i) => esc(i.manual || '–') },
  ],
  items
)}
<h2>Detailed reports</h2>
${suiteLinks.length ? `<ul>${suiteLinks.map(([label, href]) => `<li><a href="${esc(href)}">${esc(label)}</a></li>`).join('')}</ul>` : '<p>No detailed reports in this bundle.</p>'}
<h2>Sign-off</h2>
${table(
  'Sign-off',
  [
    { label: 'Role', cell: (r) => esc(r) },
    { label: 'Name', cell: () => '&nbsp;' },
    { label: 'Signature and date', cell: () => '&nbsp;' },
  ],
  ['Vendor project manager', 'Vendor QA lead', 'TMC IT representative', 'TMC unit representative']
)}
<p class="meta">Template types covered: ${esc(TEMPLATES.map((t) => t.label).join(', '))}.</p>`;
    fs.writeFileSync(path.join(OUT, `${site.id}.html`), page(`Go-Live acceptance — ${site.name}`, body));
  }

  const index = `<h1>Go-Live acceptance reports</h1>
<p class="meta">Generated ${esc(build.generatedAt)} · commit <code>${esc(build.commit.slice(0, 12) || 'unknown')}</code>${build.run ? ` · <a href="${esc(build.run)}">CI run</a>` : ''}</p>
${table(
  'Acceptance status per website',
  [
    { label: 'Website', cell: (r) => `<a href="${esc(r.site.id)}.html">${esc(r.site.name)}</a>` },
    { label: 'Automated verdict', cell: (r) => esc(r.verdict) },
    ...['PASS', 'FAIL', 'PENDING', 'NOT-RUN', 'MANUAL'].map((key) => ({ label: key.replace('-', ' '), cell: (r) => esc(r.items.filter((i) => i.status === key).length) })),
  ],
  reports
)}`;
  fs.writeFileSync(path.join(OUT, 'index.html'), page('Go-Live acceptance reports', index));

  writeJson(path.join(OUT, 'summary.json'), {
    suite: 'acceptance',
    build,
    sites: Object.fromEntries(reports.map((r) => [r.site.id, { verdict: r.verdict, items: r.items.map(({ id, clause, title, status: s, evidence, manual }) => ({ id, clause, title, status: s, evidence, manual })) }])),
  });

  // Markdown for the GitHub job summary.
  const icon = { PASS: '**PASS**', FAIL: '**FAIL**', PENDING: 'PENDING (baselines to approve)', 'NOT-RUN': 'NOT RUN', MANUAL: 'MANUAL' };
  const gate = (name, value) => `| ${name} | ${icon[value] || value} |`;
  const e2eOverall = e2eResults ? (e2eResults.tests.some(failed) ? 'FAIL' : 'PASS') : 'NOT-RUN';
  const visualOverall = visualResults ? (visualResults.tests.some(failed) ? 'FAIL' : visualResults.tests.some((t) => t.status === 'skipped') ? 'PENDING' : 'PASS') : 'NOT-RUN';
  const md = [
    '## Quality gates and Go-Live acceptance',
    '',
    `Commit \`${build.commit.slice(0, 12)}\` · ${build.generatedAt}`,
    '',
    '| Gate | Result |',
    '|---|---|',
    gate('E2E — Chromium, Firefox, WebKit × 360/768/1280 px', e2eOverall),
    gate('Visual regression (Chromium)', visualOverall),
    gate(`Accessibility — axe WCAG 2.2 AA${summaries.a11y ? ` (${summaries.a11y.totals.scans} scans)` : ''}`, summaries.a11y ? summaries.a11y.status : 'NOT-RUN'),
    gate(`Lighthouse — desktop + mobile${summaries.lighthouse ? ` (${summaries.lighthouse.totals.measurements} measurements)` : ''}`, summaries.lighthouse ? summaries.lighthouse.status : 'NOT-RUN'),
    gate(`HTML validity — Nu HTML Checker${summaries.html ? ` (${summaries.html.totals.pages} pages)` : ''}`, summaries.html ? summaries.html.status : 'NOT-RUN'),
    gate(`Links and orphans${summaries.links ? ` (${summaries.links.totals.urlsChecked} URLs)` : ''}`, summaries.links ? summaries.links.status : 'NOT-RUN'),
    '',
    '| Website | Automated verdict | Pass | Fail | Pending / not run | Manual |',
    '|---|---|---|---|---|---|',
    ...reports.map((r) => `| ${r.site.name} | ${r.verdict} | ${r.items.filter((i) => i.status === 'PASS').length} | ${r.items.filter((i) => i.status === 'FAIL').length} | ${r.items.filter((i) => i.status === 'PENDING' || i.status === 'NOT-RUN').length} | ${r.items.filter((i) => i.status === 'MANUAL').length} |`),
    '',
    'Reports: artifact **quality-reports** (`acceptance/index.html`).',
    '',
  ].join('\n');
  fs.writeFileSync(path.join(OUT, 'summary.md'), md);
  console.log(md);
}

try {
  main();
} catch (error) {
  console.error(error);
  process.exit(2);
}
