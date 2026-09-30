'use strict';
/**
 * Shared helpers for the E2E specs.
 *
 * Tags: every test carries @e2e plus one feature tag (@home, @nav, @prefs, @i18n, @search,
 * @tenders, @events, @doctors, @404, @responsive). Run one feature with
 *   npx playwright test --grep @tenders
 * A new feature adds tests/e2e/<feature>.spec.js with its own tag — nothing else to register.
 */
const { expect } = require('@playwright/test');
const { SITES, TEMPLATES, origin, siteById } = require('../lib/sites');

const MAIN = SITES[0];

/** Path of a template from lib/sites.js (so QUALITY_URLS_FILE overrides apply here too). */
function pathOf(templateId) {
  const template = TEMPLATES.find((candidate) => candidate.id === templateId);
  if (!template) {
    throw new Error(`unknown template ${templateId}`);
  }
  return template.path;
}

/** The main menu is collapsed behind the "Menu" button below 1024 px (main.css). */
function menuCollapsed(page) {
  return page.viewportSize().width < 1024;
}

/**
 * The key that moves focus to the next link or button. Safari on macOS skips links and buttons on
 * Tab unless Option is held (system default); Linux WebKit, Chromium and Firefox use plain Tab.
 */
function tabKey(browserName) {
  return browserName === 'webkit' && process.platform === 'darwin' ? 'Alt+Tab' : 'Tab';
}

/**
 * Browser messages that are not defects of the site:
 * - failed resource loads are reported by the link checker (the 404 page logs one by design);
 * - WebKit refuses speculative prefetch (WordPress speculation rules) over plain HTTP, which only
 *   the local and CI stacks use.
 */
const IGNORED_CONSOLE = [/Failed to load resource/i, /Prefetch request denied: URL must be secure/i];

/** Collects uncaught JavaScript errors and console errors. */
function watchErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(`uncaught: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error' && !IGNORED_CONSOLE.some((pattern) => pattern.test(message.text()))) {
      errors.push(`console: ${message.text()}`);
    }
  });
  return errors;
}

/** R-4.9-2: no horizontal scrolling at any breakpoint (WCAG 1.4.10 Reflow at 320 CSS px). */
async function expectNoHorizontalScroll(page) {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(scrollWidth, `page is ${scrollWidth}px wide in a ${clientWidth}px viewport`).toBeLessThanOrEqual(clientWidth + 1);
}

/** Id of the element that has keyboard focus, or a short description when it has no id. */
async function focusedDescription(page) {
  return page.evaluate(() => {
    const el = document.activeElement;
    if (!el || el === document.body) {
      return 'body';
    }
    return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${String(el.className || '').split(' ')[0]} "${(el.textContent || '').trim().slice(0, 40)}"`;
  });
}

module.exports = {
  MAIN,
  SITES,
  expectNoHorizontalScroll,
  focusedDescription,
  menuCollapsed,
  origin,
  pathOf,
  siteById,
  tabKey,
  watchErrors,
};
