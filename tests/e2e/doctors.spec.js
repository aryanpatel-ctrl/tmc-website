'use strict';
/**
 * Find a Doctor (R-4.14-2): labelled filter form (name, department) that works with and without
 * JavaScript; the number of results is announced (role="status"). A search without matches
 * shows "0 doctors found" on the listing (it used to return "Page not found": the field was called
 * "name", a reserved WordPress query variable).
 * Uses the sample doctor profiles and departments seeded by migration 001.
 */
const { test, expect } = require('@playwright/test');
const { expectNoHorizontalScroll, pathOf } = require('./helpers');

async function resultCount(page) {
  const text = await page.locator('.search-count').textContent();
  const match = text.match(/(\d+) doctors? found/);
  expect(match, `result count text "${text}"`).not.toBeNull();
  return Number(match[1]);
}

async function filterByDepartment(page) {
  await page.goto(pathOf('doctors'));
  await expect(page.locator('h1')).toHaveText('Find a doctor');
  await expect(page.locator('.search-count')).toHaveAttribute('role', 'status');
  const all = await resultCount(page);
  expect(all).toBeGreaterThan(0);
  await expect(page.locator('.doctor-card')).toHaveCount(Math.min(all, 24));

  await page.getByLabel('Department').selectOption({ label: 'Pathology' });
  await page.getByRole('button', { name: 'Find' }).click();
  await expect(page).toHaveURL(/[?&]department=\d+/);
  const filtered = await resultCount(page);
  expect(filtered).toBeGreaterThan(0);
  expect(filtered).toBeLessThan(all);
  const cards = page.locator('.doctor-card');
  await expect(cards).toHaveCount(filtered);
  for (const card of await cards.all()) {
    await expect(card).toContainText('Pathology');
  }
  // The chosen filter stays selected, and "Clear" resets it.
  await expect(page.getByLabel('Department')).toHaveValue(/^[1-9]\d*$/);
  await page.getByRole('link', { name: 'Clear' }).click();
  expect(await resultCount(page)).toBe(all);
  await expectNoHorizontalScroll(page);
}

test('filter by department', { tag: ['@e2e', '@doctors'] }, async ({ page }) => {
  await filterByDepartment(page);
});

test('filter by name', { tag: ['@e2e', '@doctors'] }, async ({ page }) => {
  await page.goto(pathOf('doctors'));
  const name = (await page.locator('.doctor-card .card-title').first().textContent()).trim();
  await page.getByLabel('Name').fill(name);
  await page.getByRole('button', { name: 'Find' }).click();
  // Stays on the listing (the field must not collide with WordPress's reserved "name" query var).
  await expect(page).toHaveURL(/\/doctors\/\?doctor_name=/);
  await expect(page.locator('h1')).toHaveText('Find a doctor');
  expect(await resultCount(page)).toBeGreaterThan(0);
  await expect(page.locator('.doctor-card .card-title', { hasText: name })).toHaveCount(1);
  await expect(page.getByLabel('Name')).toHaveValue(name);
});

test('name search with no match', { tag: ['@e2e', '@doctors'] }, async ({ page }) => {
  await page.goto(pathOf('doctors'));
  await page.getByLabel('Name').fill('qqxxzz-no-such-doctor');
  await page.getByRole('button', { name: 'Find' }).click();
  await expect(page).toHaveURL(/[?&]doctor_name=qqxxzz-no-such-doctor/);
  await expect(page.locator('h1')).toHaveText('Find a doctor');
  expect(await resultCount(page)).toBe(0);
  await expect(page.locator('.doctor-card')).toHaveCount(0);
  await expect(page.getByLabel('Name')).toHaveValue('qqxxzz-no-such-doctor');
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('filter by department', { tag: ['@e2e', '@doctors', '@no-js'] }, async ({ page }) => {
    await filterByDepartment(page);
  });
});
