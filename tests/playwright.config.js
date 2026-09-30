'use strict';
/**
 * Playwright configuration for the browser-based quality gates.
 *
 * QUALITY_SUITE chooses what runs (one suite per invocation, so each writes its own report):
 *   e2e     functional tests, tests/e2e/*.spec.js, on Chromium, Firefox and WebKit at 360, 768
 *           and 1280 px (9 projects; QUALITY_BROWSERS=chromium,firefox limits the browsers)
 *   visual  screenshot comparison against approved baselines, Chromium at 360 and 1280 px
 *   a11y    axe-core WCAG 2.0/2.1/2.2 A + AA scan of every template on every site, 1280 and 360 px
 *
 * Reports go to $QUALITY_OUT/<suite>/ (default tests/results/<suite>/): results.json (read by the
 * acceptance report), html-report/ and test artifacts (traces, screenshots of failures).
 *
 * Other environment variables:
 *   QUALITY_WORKERS          parallel workers (default: 2 in CI, half the CPU cores locally)
 *   QUALITY_LOCALHOST_PROXY  1 = route WebKit through lib/localhost-proxy.js (developer Macs)
 *   QUALITY_UPDATE_BASELINES 1 = write new visual baselines (only inside the CI container)
 */
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');
const { SITES, origin } = require('./lib/sites');
const { outDir } = require('./lib/paths');
const { PORT: PROXY_PORT } = require('./lib/localhost-proxy');

const SUITE = process.env.QUALITY_SUITE || 'e2e';
const OUT = outDir(SUITE);
const CI = !!process.env.CI;
const PROXY = process.env.QUALITY_LOCALHOST_PROXY === '1';

const BROWSERS = {
  chromium: devices['Desktop Chrome'],
  firefox: devices['Desktop Firefox'],
  webkit: devices['Desktop Safari'],
};
// Phone, tablet and desktop breakpoints of the theme (the main menu collapses below 1024 px).
const VIEWPORTS = {
  360: { width: 360, height: 780 },
  768: { width: 768, height: 1024 },
  1280: { width: 1280, height: 800 },
};

function project(name, browser, width, testDir) {
  return {
    name,
    testDir,
    metadata: { browser, width },
    use: {
      ...BROWSERS[browser],
      viewport: VIEWPORTS[width],
      deviceScaleFactor: 1,
      ...(PROXY && browser === 'webkit' ? { proxy: { server: `http://127.0.0.1:${PROXY_PORT}` } } : {}),
    },
  };
}

function projects() {
  if (SUITE === 'visual') {
    return [1280, 360].map((width) => project(`visual-${width}`, 'chromium', width, './visual'));
  }
  if (SUITE === 'a11y') {
    return [1280, 360].map((width) => project(`a11y-${width}`, 'chromium', width, './a11y'));
  }
  if (SUITE !== 'e2e') {
    throw new Error(`QUALITY_SUITE must be e2e, visual or a11y (got "${SUITE}")`);
  }
  const wanted = (process.env.QUALITY_BROWSERS || 'chromium,firefox,webkit').split(',').map((b) => b.trim()).filter(Boolean);
  const list = [];
  for (const browser of wanted) {
    if (!BROWSERS[browser]) {
      throw new Error(`unknown browser "${browser}" in QUALITY_BROWSERS`);
    }
    for (const width of Object.keys(VIEWPORTS)) {
      list.push(project(`e2e-${browser}-${width}`, browser, Number(width), './e2e'));
    }
  }
  return list;
}

module.exports = defineConfig({
  globalSetup: require.resolve('./lib/global-setup'),
  outputDir: path.join(OUT, 'artifacts'),
  // Baselines are platform-specific; only the Linux ones (made in the pinned CI container) are committed.
  snapshotPathTemplate: '{testDir}/baselines/{arg}-{projectName}-{platform}{ext}',
  // Visual baselines change only on purpose: QUALITY_UPDATE_BASELINES=1 rewrites them all
  // (docs/testing/quality-gates.md#visual-regression-baselines); otherwise missing ones are skipped.
  updateSnapshots: process.env.QUALITY_UPDATE_BASELINES === '1' ? 'all' : 'none',
  fullyParallel: true,
  forbidOnly: CI,
  retries: CI && SUITE === 'e2e' ? 1 : 0,
  workers: process.env.QUALITY_WORKERS ? Number(process.env.QUALITY_WORKERS) : CI ? 2 : '50%',
  timeout: 60000,
  expect: {
    timeout: 10000,
    toHaveScreenshot: {
      // Anti-aliasing noise is tolerated; a moved, resized or restyled component is not.
      maxDiffPixelRatio: 0.002,
      threshold: 0.2,
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
    },
  },
  reporter: [
    [CI ? 'dot' : 'list'],
    ['json', { outputFile: path.join(OUT, 'results.json') }],
    ['html', { outputFolder: path.join(OUT, 'html-report'), open: 'never' }],
  ],
  use: {
    baseURL: origin(SITES[0]),
    locale: 'en-GB',
    timezoneId: 'Asia/Kolkata',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 15000,
    navigationTimeout: 30000,
  },
  projects: projects(),
});
