'use strict';
/**
 * Site search (R-4.3-4, R-4.12-5): the labelled search box in the header works on every page at
 * every width; the result count is announced (role="status"); no-result searches say so and offer
 * help; results can be narrowed by content type; the header box is an ARIA 1.2 combobox with
 * keyboard-operable suggestions (W1: src/themes/tmc/assets/js/features/search.js).
 */
const { test, expect } = require('@playwright/test');
const { pathOf } = require('./helpers');

/** The header search box. search.js upgrades the plain searchbox to a combobox. */
function headerSearch(page) {
  return page.getByRole('banner').getByRole('combobox', { name: 'Search this website' });
}

test('header search returns results', { tag: ['@e2e', '@search'] }, async ({ page }) => {
  await page.goto(pathOf('page-section-nav'));
  const input = headerSearch(page);
  await expect(input).toBeVisible();
  await input.fill('patient');
  await input.press('Enter');

  await expect(page).toHaveURL(/[?&]s=patient\b/);
  await expect(page.locator('h1')).toContainText('patient');
  const count = page.locator('.search-count');
  await expect(count).toHaveAttribute('role', 'status');
  await expect(count).toHaveText(/^\s*(\d+ results? found\.|Showing \d+–\d+ of \d+ results\.)\s*$/);
  const results = page.locator('.result-list > li');
  expect(await results.count()).toBeGreaterThan(0);
  await expect(results.first().locator('a').first()).toHaveAttribute('href', /^https?:\/\//);
});

test('search with no matches says so', { tag: ['@e2e', '@search'] }, async ({ page }) => {
  const response = await page.goto('/?s=qqxxzzqq-no-such-term');
  expect(response.status()).toBe(200);
  await expect(page.locator('.search-count')).toHaveText(/^\s*No results found\.\s*$/);
  await expect(page.locator('.result-list')).toHaveCount(0);
  await expect(page.locator('.search-help')).toContainText('Check the spelling of your search words.');
  // The results page offers a labelled "Refine search" form, with the query filled in.
  const refine = page.getByRole('search', { name: 'Refine search' });
  await expect(refine.getByLabel('Search for')).toHaveValue('qqxxzzqq-no-such-term');
});

test('results can be narrowed to one content type', { tag: ['@e2e', '@search'] }, async ({ page }) => {
  const response = await page.goto('/?s=sample&type=tmc_tender');
  expect(response.status()).toBe(200);
  const refine = page.getByRole('search', { name: 'Refine search' });
  await expect(refine.getByLabel('Show')).toHaveValue('tmc_tender');
  const results = page.locator('.result-list > li');
  expect(await results.count()).toBeGreaterThan(0);
  // Every result links to a tender.
  const hrefs = await results.evaluateAll((items) => items.map((li) => (li.querySelector('a') || {}).href || ''));
  for (const href of hrefs) {
    expect(href).toMatch(/\/tenders\//);
  }
});

test('suggestions combobox is operable with the keyboard', { tag: ['@e2e', '@search'] }, async ({ page }) => {
  await page.goto('/');
  const input = headerSearch(page);
  await expect(input).toHaveAttribute('aria-expanded', 'false');
  const listId = await input.getAttribute('aria-controls');
  expect(listId).toBeTruthy();
  const list = page.locator(`[id="${listId}"]`);

  await input.click();
  await input.pressSequentially('sample', { delay: 30 });
  await expect(list).toBeVisible();
  await expect(input).toHaveAttribute('aria-expanded', 'true');
  const options = list.getByRole('option');
  const total = await options.count();
  expect(total).toBeGreaterThan(0);

  // Down selects the first option; focus stays in the text box (aria-activedescendant).
  await input.press('ArrowDown');
  await expect(options.first()).toHaveAttribute('aria-selected', 'true');
  await expect(input).toHaveAttribute('aria-activedescendant', await options.first().getAttribute('id'));
  await expect(input).toBeFocused();

  // Up from the first option wraps to the last.
  await input.press('ArrowUp');
  await expect(options.nth(total - 1)).toHaveAttribute('aria-selected', 'true');

  // Escape closes the list; a second Escape clears the box.
  await input.press('Escape');
  await expect(list).toBeHidden();
  await expect(input).toHaveAttribute('aria-expanded', 'false');
  await input.press('Escape');
  await expect(input).toHaveValue('');

  // Enter on a highlighted suggestion opens it. Documents (PDF) open as downloads, so choose the
  // first suggestion that is a web page.
  await input.pressSequentially('sample', { delay: 30 });
  await expect(list).toBeVisible();
  const types = await options.locator('.suggest-type').allTextContents();
  const index = types.findIndex((type) => !/^\s*Document\s*$/i.test(type));
  expect(index, `a suggestion that is a web page (types: ${types.join(', ')})`).toBeGreaterThanOrEqual(0);
  for (let step = 0; step <= index; step++) {
    await input.press('ArrowDown');
  }
  await expect(options.nth(index)).toHaveAttribute('aria-selected', 'true');
  const target = await options.nth(index).locator('.suggest-title').textContent();
  await Promise.all([page.waitForURL((url) => url.pathname !== '/'), input.press('Enter')]);
  await expect(page.locator('h1')).toContainText((target || '').trim().slice(0, 20));
});
