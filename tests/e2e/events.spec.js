'use strict';
/**
 * Events and the event calendar (R-4.3-5): upcoming / past lists, the month calendar (a data
 * table on wide screens, an agenda list on phones), month navigation and the .ics download.
 * Uses the sample events seeded by migration 001 (one 30 days ahead, one 20 days ago).
 */
const { test, expect } = require('@playwright/test');
const { expectNoHorizontalScroll } = require('./helpers');
const { requestOnce } = require('../lib/http');

const UPCOMING = 'Sample event: continuing medical education session';
const PAST = 'Sample event: blood donation camp';

test('upcoming and past events', { tag: ['@e2e', '@events'] }, async ({ page }) => {
  await page.goto('/events/');
  const tabs = page.getByRole('navigation', { name: 'Events' });
  await expect(tabs.getByRole('link', { name: 'Upcoming' })).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('.event-cards')).toContainText(UPCOMING);
  await expect(page.locator('.event-cards')).not.toContainText(PAST);

  await tabs.getByRole('link', { name: 'Past' }).click();
  await expect(page).toHaveURL(/[?&]view=past\b/);
  await expect(page.locator('.event-cards')).toContainText(PAST);
  await expect(page.locator('.event-cards')).not.toContainText(UPCOMING);
  await expectNoHorizontalScroll(page);
});

test('month calendar shows events and navigates between months', { tag: ['@e2e', '@events'] }, async ({ page }) => {
  // Month of the upcoming sample event, read from its page (dates are relative to set-up time).
  await page.goto('/events/sample-cme-session/');
  const start = await page.locator('dl.details dd time').first().getAttribute('datetime');
  const month = start.slice(0, 7);

  await page.goto(`/events/?view=calendar&month=${month}`);
  await expect(page.getByRole('navigation', { name: 'Events' }).getByRole('link', { name: 'Calendar' })).toHaveAttribute('aria-current', 'page');
  const title = page.locator('#calendar-title');
  const monthLabel = (await title.textContent()).trim();
  expect(monthLabel).toMatch(/^[A-Z][a-z]+ \d{4}$/);

  const grid = page.locator('table.cal-grid');
  const agenda = page.locator('.cal-agenda');
  if (page.viewportSize().width >= 768) {
    await expect(grid).toBeVisible();
    await expect(agenda).toBeHidden();
    await expect(grid).toHaveAttribute('aria-labelledby', 'calendar-title');
    await expect(grid.locator('thead th[scope="col"]')).toHaveCount(7);
    await expect(grid.locator('td.has-events a', { hasText: UPCOMING })).toBeVisible();
  } else {
    // Phones get an agenda list instead of a seven-column table.
    await expect(grid).toBeHidden();
    await expect(agenda).toBeVisible();
    await expect(agenda.locator('a', { hasText: UPCOMING })).toBeVisible();
  }
  await expectNoHorizontalScroll(page);

  // Next month, then back.
  const next = page.locator('.calendar-nav a').last();
  await next.click();
  await expect(page).toHaveURL(/[?&]month=\d{4}-\d{2}\b/);
  await expect(title).not.toHaveText(monthLabel);
  await page.locator('.calendar-nav a').first().click();
  await expect(page.locator('#calendar-title')).toHaveText(monthLabel);
});

test('event can be added to a calendar (.ics)', { tag: ['@e2e', '@events'] }, async ({ page }) => {
  await page.goto('/events/sample-cme-session/');
  const link = page.getByRole('link', { name: /Add to calendar/ });
  await expect(link).toHaveAttribute('href', /[?&]ics=1$/);
  // Node client (lib/http.js resolves *.localhost itself, like the browsers do).
  const response = await requestOnce(await link.getAttribute('href'));
  expect(response.status, response.error).toBe(200);
  expect(response.headers['content-type']).toContain('text/calendar');
  const body = response.body;
  expect(body).toContain('BEGIN:VCALENDAR');
  expect(body).toMatch(/DTSTART:\d{8}T\d{6}Z/);
  expect(body).toContain('SUMMARY:Sample event');
});
