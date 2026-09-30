'use strict';
/**
 * R-5-1 — visual regression: screenshots of the key templates compared with approved baselines
 * (Chromium, 1280 and 360 px). A moved, resized or restyled component fails the test.
 *
 * What is captured
 *   - full page of every template flagged `visual` in lib/sites.js, on one unit site (VISUAL_SITE,
 *     default hbchrcv). The unit sites carry only sample content with dates relative to set-up
 *     time, so the pages look the same on every fresh CI stack. The TMC and TMH sites also list
 *     the real EOI, which moves to the archive on its closing date and would change the pages.
 *   - the first screen (viewport) of the home page of every site: identity, hero and navigation.
 *
 * Dynamic values (dates, "last updated", copyright year) are masked. Animations are disabled and
 * reduced motion is requested (the notice board does not scroll).
 *
 * Baselines: tests/visual/baselines/*-linux.png, made inside the pinned Playwright container of the
 * CI job so fonts and rendering are identical. When no approved baseline exists for a screenshot,
 * the test is skipped and reported as "baseline missing" (never silently passed). Update procedure:
 * docs/testing/quality-gates.md#visual-regression-baselines.
 */
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { SITES, TEMPLATES, siteById, url } = require('../lib/sites');

const VISUAL_SITE = siteById(process.env.VISUAL_SITE || 'hbchrcv');
const MASK = ['time', '.last-updated', '.entry-updated', '.footer-meta', '.event-date', '.dated-meta', '.cal-grid .is-today'];

test.use({ reducedMotion: 'reduce', colorScheme: 'light' });

const SHOTS = [
  ...TEMPLATES.filter((template) => template.visual).map((template) => ({
    name: `${VISUAL_SITE.id}-${template.id}`,
    site: VISUAL_SITE,
    template,
    fullPage: true,
  })),
  ...SITES.map((site) => ({
    name: `${site.id}-home-first-screen`,
    site,
    template: TEMPLATES.find((template) => template.id === 'home'),
    fullPage: false,
  })),
];

for (const shot of SHOTS) {
  test(shot.name, { tag: ['@visual', `@site-${shot.site.id}`, `@template-${shot.template.id}`] }, async ({ page }, testInfo) => {
    const file = `${shot.name}.png`;
    const baseline = testInfo.snapshotPath(file, { kind: 'screenshot' });
    if (testInfo.config.updateSnapshots === 'none' && !fs.existsSync(baseline)) {
      testInfo.annotations.push({ type: 'baseline-missing', description: baseline });
      test.skip(true, `no approved baseline yet (${file}); see docs/testing/quality-gates.md`);
    }

    const response = await page.goto(url(shot.site, shot.template.path), { waitUntil: 'load' });
    expect(response.status()).toBe(shot.template.status);
    await page.evaluate(() => document.fonts.ready);
    // Lazy images below the fold: bring them in before a full-page capture.
    if (shot.fullPage) {
      await page.evaluate(async () => {
        for (const img of document.querySelectorAll('img[loading="lazy"]')) {
          img.loading = 'eager';
        }
        const pending = [...document.images].filter((img) => !img.complete);
        await Promise.all(
          pending.map(
            (img) =>
              new Promise((resolve) => {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
              })
          )
        );
      });
    }

    await expect(page).toHaveScreenshot(file, {
      fullPage: shot.fullPage,
      mask: MASK.map((selector) => page.locator(selector)),
    });
  });
}
