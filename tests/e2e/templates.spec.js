'use strict';
/**
 * Every template type (lib/sites.js) on the TMC site, in every browser at every width
 * (R-4.9-2 responsive, R-4.9-3 browsers, R-4.14-3): expected HTTP status, one <h1>, a <main>
 * landmark, no JavaScript errors and no horizontal scrolling.
 */
const { test, expect } = require('@playwright/test');
const { TEMPLATES } = require('../lib/sites');
const { expectNoHorizontalScroll, watchErrors } = require('./helpers');

for (const template of TEMPLATES) {
  test(`template renders: ${template.label}`, { tag: ['@e2e', '@responsive', `@template-${template.id}`] }, async ({ page }) => {
    const errors = watchErrors(page);
    const response = await page.goto(template.path);
    expect(response.status()).toBe(template.status);
    await expect(page.locator('html')).toHaveAttribute('lang', template.lang === 'hi' ? 'hi-IN' : 'en-GB');
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.getByRole('main')).toHaveCount(1);
    await expectNoHorizontalScroll(page);
    expect(errors, 'JavaScript errors').toEqual([]);
  });
}
