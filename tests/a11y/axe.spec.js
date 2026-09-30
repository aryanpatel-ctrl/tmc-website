'use strict';
/**
 * R-4.9-1 — automated WCAG 2.0 / 2.1 / 2.2 Level A + AA scan (axe-core) of every template type on
 * every website, at desktop (1280 px) and phone (360 px) width. Any violation fails the test.
 *
 * Each test also writes its raw axe result to $QUALITY_OUT/a11y/axe/<project>/<site>--<template>.json;
 * a11y/report.js turns those into the HTML/JSON report used by the Go-Live acceptance report.
 *
 * Automated tools find roughly a third to a half of WCAG failures; the remaining success criteria
 * are covered by the manual checklist in docs/testing/quality-gates.md.
 */
const path = require('path');
const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');
const { allPages } = require('../lib/sites');
const { outDir, writeJson } = require('../lib/paths');

// WCAG 2.0, 2.1 and 2.2, Levels A and AA (axe tags). Best-practice rules are not part of the gate.
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

/**
 * Pages to scan: every template on every site in the default view, plus every template on the TMC
 * site in the high-contrast view (GIGW: the contrast option must itself be accessible). The
 * high-contrast styles are the same on every site, so one site is enough.
 */
const TARGETS = [
  ...allPages().map((target) => ({ ...target, contrast: 'normal' })),
  ...allPages()
    .filter((target) => target.site.id === 'tmc')
    .map((target) => ({ ...target, contrast: 'high' })),
];

for (const target of TARGETS) {
  const { site, template, contrast } = target;
  const high = contrast === 'high';
  test(
    `${site.id} · ${template.label}${high ? ' · high contrast' : ''}`,
    { tag: ['@a11y', `@site-${site.id}`, `@template-${template.id}`, ...(high ? ['@high-contrast'] : [])] },
    async ({ page }, testInfo) => {
      if (high) {
        // The theme applies the saved preference before first paint (inc/assets.php).
        await page.addInitScript(() => {
          try {
            window.localStorage.setItem('tmc-contrast', 'high');
          } catch (e) {
            // storage unavailable: the test below then fails on the missing attribute
          }
        });
      }
      const response = await page.goto(target.url, { waitUntil: 'load' });
      expect(response, `no response for ${target.url}`).not.toBeNull();
      expect(response.status(), `HTTP status of ${target.url}`).toBe(template.status);
      if (high) {
        await expect(page.locator('html')).toHaveAttribute('data-contrast', 'high');
      }

      const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
      const violations = results.violations.map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        help: violation.help,
        helpUrl: violation.helpUrl,
        tags: violation.tags.filter((tag) => /^wcag/.test(tag)),
        nodes: violation.nodes.map((node) => ({
          target: node.target.join(' '),
          html: node.html.slice(0, 500),
          summary: node.failureSummary,
        })),
      }));

      writeJson(path.join(outDir('a11y'), 'axe', testInfo.project.name, `${site.id}--${template.id}${high ? '--high-contrast' : ''}.json`), {
        site: site.id,
        template: template.id,
        contrast,
        label: template.label,
        url: target.url,
        project: testInfo.project.name,
        axeVersion: results.testEngine.version,
        tags: WCAG_TAGS,
        passes: results.passes.length,
        incomplete: results.incomplete.map((item) => ({ id: item.id, help: item.help, nodes: item.nodes.length })),
        violations,
      });

      const readable = violations.map(
        (violation) => `${violation.id} (${violation.impact}): ${violation.help}\n    ${violation.nodes.map((node) => node.target).join('\n    ')}`
      );
      expect(readable, `WCAG violations on ${target.url}`).toEqual([]);
    }
  );
}
