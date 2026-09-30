'use strict';
/**
 * Page not found (GIGW): real 404 status, a helpful page with search and a link home.
 */
const { test, expect } = require('@playwright/test');
const { expectNoHorizontalScroll, pathOf } = require('./helpers');

test('missing page returns 404 with search and a way home', { tag: ['@e2e', '@404'] }, async ({ page, baseURL }) => {
  const response = await page.goto(pathOf('not-found'));
  expect(response.status()).toBe(404);
  await expect(page).toHaveTitle(/Page not found/);
  await expect(page.locator('h1')).toHaveText('Page not found');
  await expect(page.locator('.page-body').getByRole('searchbox', { name: 'Search this website' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Go to home page' })).toHaveAttribute('href', `${baseURL}/`);
  await expect(page.getByRole('navigation', { name: 'You are here' }).locator('[aria-current="page"]')).toHaveText('Page not found');
  await expectNoHorizontalScroll(page);

  await page.getByRole('link', { name: 'Go to home page' }).click();
  await expect(page).toHaveURL(`${baseURL}/`);
});
