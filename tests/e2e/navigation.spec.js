'use strict';
/**
 * Main menu (R-4.9-1, WCAG 2.1.1 / 2.4.3 / 4.1.2): W3C disclosure navigation pattern.
 * Keyboard only: open a submenu with Enter or Space, move into it, close it with Escape and get
 * focus back on its button; one submenu open at a time. Below 1024 px the whole menu sits behind
 * the "Menu" button, which Escape also closes. Without JavaScript every link stays reachable.
 */
const { test, expect } = require('@playwright/test');
const { focusedDescription, menuCollapsed, tabKey } = require('./helpers');

test.describe('main menu', () => {
  test('disclosure submenus work with the keyboard', { tag: ['@e2e', '@nav'] }, async ({ page, browserName }) => {
    const TAB = tabKey(browserName);
    await page.goto('/');

    const menuButton = page.locator('.nav-toggle');
    const nav = page.locator('#primary-nav');
    const items = page.locator('.nav-item.has-sub');
    const first = items.nth(0);
    const second = items.nth(1);
    const firstToggle = first.locator('.sub-toggle');
    const firstPanel = first.locator('.nav-panel');
    const secondToggle = second.locator('.sub-toggle');

    if (menuCollapsed(page)) {
      await expect(nav).toBeHidden();
      await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
      await expect(menuButton).toHaveAttribute('aria-controls', 'primary-nav');
      await menuButton.focus();
      await page.keyboard.press('Enter');
      await expect(menuButton).toHaveAttribute('aria-expanded', 'true');
      await expect(nav).toBeVisible();
    } else {
      await expect(menuButton).toBeHidden();
      await expect(nav).toBeVisible();
    }

    // Link first, then its submenu button.
    await first.locator('.nav-link').focus();
    await page.keyboard.press(TAB);
    await expect(firstToggle, await focusedDescription(page)).toBeFocused();
    await expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(firstToggle).toHaveAccessibleName(/\S/);
    await expect(firstPanel).toBeHidden();

    // Enter opens; focus moves into the panel with Tab.
    await page.keyboard.press('Enter');
    await expect(firstToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(firstPanel).toBeVisible();
    await page.keyboard.press(TAB);
    await expect(firstPanel.locator('a').first(), await focusedDescription(page)).toBeFocused();

    // Escape closes and returns focus to the button.
    await page.keyboard.press('Escape');
    await expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(firstPanel).toBeHidden();
    await expect(firstToggle).toBeFocused();

    // Space opens too; opening another submenu closes the first.
    await page.keyboard.press('Space');
    await expect(firstToggle).toHaveAttribute('aria-expanded', 'true');
    await secondToggle.focus();
    await page.keyboard.press('Enter');
    await expect(secondToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('.sub-toggle[aria-expanded="true"]')).toHaveCount(1);

    await page.keyboard.press('Escape');
    await expect(secondToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(secondToggle).toBeFocused();

    if (menuCollapsed(page)) {
      // A second Escape closes the whole menu and returns focus to the Menu button.
      await page.keyboard.press('Escape');
      await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
      await expect(nav).toBeHidden();
      await expect(menuButton).toBeFocused();
    }
  });

  test('current section is marked in the menu', { tag: ['@e2e', '@nav'] }, async ({ page }) => {
    await page.goto('/patient-care/patient-guide/');
    await expect(page.locator('.nav-item.is-active > .nav-link')).toHaveText(/\S/);
    await expect(page.locator('#primary-nav a[aria-current="page"]')).toHaveCount(1);
    // Breadcrumbs name the ancestors and mark the current page.
    const crumbs = page.getByRole('navigation', { name: 'You are here' });
    await expect(crumbs.locator('li')).toHaveCount(3);
    await expect(crumbs.locator('[aria-current="page"]')).toHaveCount(1);
  });
});

test.describe('main menu without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('every submenu link is reachable', { tag: ['@e2e', '@nav', '@no-js'] }, async ({ page, browserName }) => {
    const TAB = tabKey(browserName);
    await page.goto('/');
    await expect(page.locator('html')).not.toHaveClass(/\bjs\b/);
    const nav = page.locator('#primary-nav');
    await expect(nav).toBeVisible();

    const first = page.locator('.nav-item.has-sub').first();
    await first.locator('.nav-link').focus();
    await page.keyboard.press(TAB); // the (inactive) submenu button
    await page.keyboard.press(TAB); // first link of the panel, shown by :focus-within
    const firstLink = first.locator('.nav-panel a').first();
    await expect(firstLink, await focusedDescription(page)).toBeFocused();
    await expect(firstLink).toBeVisible();
  });
});
