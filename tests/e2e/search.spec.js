'use strict';
/**
 * Site search (R-4.3-4, R-4.12-5 basic): the labelled search box in the header works on every
 * page at every width; the result count is announced (role="status"); no-result searches say so.
 */
const { test, expect } = require('@playwright/test');
const { pathOf } = require('./helpers');

test('header search returns results', { tag: ['@e2e', '@search'] }, async ({ page }) => {
  await page.goto(pathOf('page-section-nav'));
  const input = page.getByRole('banner').getByRole('searchbox', { name: 'Search this website' });
  await expect(input).toBeVisible();
  await input.fill('patient');
  await input.press('Enter');

  await expect(page).toHaveURL(/[?&]s=patient\b/);
  await expect(page.locator('h1')).toContainText('patient');
  const count = page.locator('.search-count');
  await expect(count).toHaveAttribute('role', 'status');
  await expect(count).toHaveText(/^\s*\d+ results? found\.\s*$/);
  const results = page.locator('.result-list > li');
  expect(await results.count()).toBeGreaterThan(0);
  await expect(results.first().locator('a').first()).toHaveAttribute('href', /^https?:\/\//);
});

test('search with no matches says so', { tag: ['@e2e', '@search'] }, async ({ page }) => {
  const response = await page.goto('/?s=qqxxzzqq-no-such-term');
  expect(response.status()).toBe(200);
  await expect(page.locator('.search-count')).toHaveText(/^\s*0 results found\.\s*$/);
  await expect(page.locator('.result-list')).toHaveCount(0);
  await expect(page.getByText('No results found. Please try different keywords.')).toBeVisible();
  // The results page offers the search box again, with the query filled in.
  await expect(page.locator('.page-body .search-input')).toHaveValue('qqxxzzqq-no-such-term');
});
