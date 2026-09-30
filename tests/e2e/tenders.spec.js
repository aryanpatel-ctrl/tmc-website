'use strict';
/**
 * Tenders & EOIs (R-4.6-5 automatic expiry, GIGW tender publishing): current list, archive,
 * single tender with key facts and downloadable documents (type and size shown).
 * Uses the clearly labelled sample tenders seeded by migration 001 on every site.
 */
const { test, expect } = require('@playwright/test');
const { expectNoHorizontalScroll, pathOf } = require('./helpers');
const { requestOnce } = require('../lib/http');

const CURRENT_SAMPLE = 'Sample tender: supply of laboratory consumables';
const CLOSED_SAMPLE = 'Sample EOI: hospital information display screens';

test('current tenders list and archive', { tag: ['@e2e', '@tenders'] }, async ({ page }) => {
  await page.goto(pathOf('tenders'));
  await expect(page.locator('h1')).toHaveText('Tenders & EOIs');
  const tabs = page.getByRole('navigation', { name: 'Tenders & EOIs' });
  await expect(tabs.getByRole('link', { name: 'Current' })).toHaveAttribute('aria-current', 'page');

  const table = page.locator('table.data-table');
  await expect(table.locator('caption')).toHaveText('Open tenders and EOIs');
  await expect(table.locator('thead th')).toHaveCount(7);
  const currentRow = table.locator('tbody tr', { hasText: CURRENT_SAMPLE });
  await expect(currentRow).toHaveCount(1);
  await expect(currentRow.locator('.badge')).toHaveText('Open');
  await expect(table.locator('tbody tr', { hasText: CLOSED_SAMPLE })).toHaveCount(0);
  await expectNoHorizontalScroll(page);

  await tabs.getByRole('link', { name: 'Archive' }).click();
  await expect(page).toHaveURL(/[?&]view=archive\b/);
  await expect(page.getByRole('navigation', { name: 'Tenders & EOIs' }).getByRole('link', { name: 'Archive' })).toHaveAttribute('aria-current', 'page');
  const archive = page.locator('table.data-table');
  await expect(archive.locator('caption')).toHaveText('Archived tenders and EOIs');
  const closedRow = archive.locator('tbody tr', { hasText: CLOSED_SAMPLE });
  await expect(closedRow).toHaveCount(1);
  await expect(closedRow.locator('.badge')).toHaveText('Closed');
  await expect(archive.locator('tbody tr', { hasText: CURRENT_SAMPLE })).toHaveCount(0);
  await expectNoHorizontalScroll(page);
});

test('single tender shows key facts and documents', { tag: ['@e2e', '@tenders'] }, async ({ page }) => {
  await page.goto(pathOf('tenders'));
  await page.locator('table.data-table tbody tr', { hasText: CURRENT_SAMPLE }).locator('th a').click();
  await expect(page.locator('h1')).toContainText(CURRENT_SAMPLE);

  const details = page.locator('dl.details');
  await expect(details.locator('div', { has: page.locator('dt', { hasText: 'Reference no.' }) }).locator('dd')).toHaveText('DEMO/2026-27/T-001');
  await expect(details.locator('dt', { hasText: 'Last date and time of submission' })).toHaveCount(1);
  await expect(details.locator('dd time').first()).toHaveAttribute('datetime', /^\d{4}-\d{2}-\d{2}T/);

  // Documents show file type and size, and the file downloads.
  const doc = page.locator('.doc-list li').first();
  await expect(doc.locator('.doc-meta')).toHaveText(/\(PDF, [\d.]+ (B|KB)\)/);
  const href = await doc.locator('a.doc-link').getAttribute('href');
  // Node client (lib/http.js resolves *.localhost itself, like the browsers do).
  const file = await requestOnce(href, { method: 'HEAD', readBody: false });
  expect(file.status, file.error).toBe(200);
  expect(file.headers['content-type']).toContain('application/pdf');

  await expect(page.getByRole('link', { name: 'All tenders and EOIs' })).toHaveAttribute('href', /\/tenders\/$/);
  await expectNoHorizontalScroll(page);
});
