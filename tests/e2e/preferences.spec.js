'use strict';
/**
 * GIGW accessibility options (R-4.9-1): text size and high contrast change the page, are
 * announced as pressed buttons, and are remembered across reloads and pages (per visitor).
 * At the largest text size the page must still not scroll sideways (WCAG 1.4.4 / 1.4.10).
 */
const { test, expect } = require('@playwright/test');
const { expectNoHorizontalScroll, pathOf } = require('./helpers');

const fontSize = (page) => page.locator('html').evaluate((el) => el.style.fontSize);

test('text size is applied and remembered', { tag: ['@e2e', '@prefs'] }, async ({ page }) => {
  await page.goto('/');
  const normal = page.locator('[data-tmc-font="100"]');
  const larger = page.locator('[data-tmc-font="115"]');
  await expect(normal).toHaveAttribute('aria-pressed', 'true');
  await expect(larger).toHaveAccessibleName('Increase text size');

  const before = await page.locator('main p').first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
  await larger.click();
  expect(await fontSize(page)).toBe('115%');
  await expect(larger).toHaveAttribute('aria-pressed', 'true');
  await expect(normal).toHaveAttribute('aria-pressed', 'false');
  const after = await page.locator('main p').first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
  expect(after).toBeGreaterThan(before);

  await page.reload();
  expect(await fontSize(page)).toBe('115%');
  await expect(larger).toHaveAttribute('aria-pressed', 'true');

  await page.goto(pathOf('page-section-nav'));
  expect(await fontSize(page)).toBe('115%');
  await expect(page.locator('[data-tmc-font="115"]')).toHaveAttribute('aria-pressed', 'true');

  await page.locator('[data-tmc-font="100"]').click();
  expect(await fontSize(page)).toBe('');
  await page.reload();
  await expect(page.locator('[data-tmc-font="100"]')).toHaveAttribute('aria-pressed', 'true');
});

test('high contrast is applied and remembered', { tag: ['@e2e', '@prefs'] }, async ({ page }) => {
  await page.goto('/');
  const html = page.locator('html');
  const high = page.locator('[data-tmc-contrast="high"]');
  const standard = page.locator('[data-tmc-contrast="normal"]');
  await expect(html).toHaveAttribute('data-contrast', 'normal');
  await expect(high).toHaveAccessibleName('High contrast');

  await high.click();
  await expect(html).toHaveAttribute('data-contrast', 'high');
  await expect(high).toHaveAttribute('aria-pressed', 'true');
  await expect(standard).toHaveAttribute('aria-pressed', 'false');
  expect(await page.locator('body').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(0, 0, 0)');

  await page.reload();
  await expect(html).toHaveAttribute('data-contrast', 'high');
  await page.goto(pathOf('tenders'));
  await expect(html).toHaveAttribute('data-contrast', 'high');
  await expect(page.locator('[data-tmc-contrast="high"]')).toHaveAttribute('aria-pressed', 'true');

  await page.locator('[data-tmc-contrast="normal"]').click();
  await expect(html).toHaveAttribute('data-contrast', 'normal');
  await page.reload();
  await expect(html).toHaveAttribute('data-contrast', 'normal');
});

for (const templateId of ['home', 'page-section-nav', 'tenders', 'events-calendar', 'doctors']) {
  test(`largest text size does not cause sideways scrolling: ${templateId}`, { tag: ['@e2e', '@prefs', '@responsive'] }, async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-tmc-font="130"]').click();
    await page.goto(pathOf(templateId));
    expect(await fontSize(page)).toBe('130%');
    await expectNoHorizontalScroll(page);
  });
}
