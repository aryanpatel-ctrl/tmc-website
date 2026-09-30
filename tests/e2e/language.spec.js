'use strict';
/**
 * Language switch (R-4.13-1): English ⇄ Hindi goes to the translation of the same page, sets the
 * document language, and marks the current language.
 */
const { test, expect } = require('@playwright/test');
const { pathOf } = require('./helpers');

test('switches to the Hindi translation of the same page and back', { tag: ['@e2e', '@i18n'] }, async ({ page }) => {
  const englishPath = pathOf('page-section-nav');
  const hindiPath = pathOf('page-hi');
  await page.goto(englishPath);
  await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');

  const hindi = page.locator('.lang-switch a[hreflang="hi"]');
  await expect(hindi).toHaveAttribute('lang', 'hi-IN');
  await hindi.click();
  await expect(page).toHaveURL(new RegExp(`${hindiPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
  await expect(page.locator('html')).toHaveAttribute('lang', 'hi-IN');
  await expect(page.locator('.lang-switch a[hreflang="hi"]')).toHaveAttribute('aria-current', 'true');
  await expect(page.locator('.lang-switch a[hreflang="en"]')).not.toHaveAttribute('aria-current', /.*/);

  await page.locator('.lang-switch a[hreflang="en"]').click();
  await expect(page).toHaveURL(new RegExp(`${englishPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
  await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');
});

test('home page switches between / and /hi/', { tag: ['@e2e', '@i18n'] }, async ({ page }) => {
  await page.goto('/');
  await page.locator('.lang-switch a[hreflang="hi"]').click();
  await expect(page).toHaveURL(/\/hi\/$/);
  await expect(page.locator('html')).toHaveAttribute('lang', 'hi-IN');
  await page.locator('.lang-switch a[hreflang="en"]').click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');
});
