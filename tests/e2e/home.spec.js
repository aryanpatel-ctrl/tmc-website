'use strict';
/**
 * Home pages of all six websites (R-4.14-2, R-4.9-2/3): identity, landmarks, skip link, network
 * links, no script errors and no horizontal scrolling — in every browser and at every width.
 */
const { test, expect } = require('@playwright/test');
const { SITES, expectNoHorizontalScroll, origin, tabKey, watchErrors } = require('./helpers');

for (const site of SITES) {
  test(`${site.id}: home page (English)`, { tag: ['@e2e', '@home', `@site-${site.id}`] }, async ({ page, browserName }) => {
    const errors = watchErrors(page);
    const response = await page.goto(`${origin(site)}/`);
    expect(response.status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');
    await expect(page).toHaveTitle(/\S/);
    await expect(page.locator('h1')).toHaveCount(1);

    // Landmarks: banner, main menu, main, contentinfo.
    await expect(page.getByRole('banner')).toBeVisible();
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('contentinfo')).toBeVisible();
    await expect(page.locator('nav#primary-nav')).toHaveAttribute('aria-label', /\S/);

    // "Skip to main content" is the first thing keyboard users reach, and it moves focus to <main>.
    await page.keyboard.press(tabKey(browserName));
    const skip = page.locator('.skip-link');
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();
    await expect(skip).toHaveAttribute('href', '#main');
    await page.keyboard.press('Enter');
    await expect(page.locator('main#main')).toBeFocused();

    // The TMC network block links to all six websites and marks the current one.
    const cards = page.locator('.network-card a');
    await expect(cards).toHaveCount(SITES.length);
    await expect(page.locator('.network-card a[aria-current="page"]')).toHaveCount(1);
    for (const other of SITES) {
      await expect(page.locator(`.network-card a[href="${origin(other)}/"]`)).toHaveCount(1);
    }

    await expectNoHorizontalScroll(page);
    expect(errors, 'JavaScript errors').toEqual([]);
  });

  test(`${site.id}: home page (Hindi)`, { tag: ['@e2e', '@home', '@i18n', `@site-${site.id}`] }, async ({ page }) => {
    const errors = watchErrors(page);
    const response = await page.goto(`${origin(site)}/hi/`);
    expect(response.status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('lang', 'hi-IN');
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('.lang-switch a[hreflang="hi"]')).toHaveAttribute('aria-current', 'true');
    await expectNoHorizontalScroll(page);
    expect(errors, 'JavaScript errors').toEqual([]);
  });
}
