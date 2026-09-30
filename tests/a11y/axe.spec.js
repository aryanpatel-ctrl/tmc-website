'use strict';
/**
 * R-4.9-1 — automated WCAG 2.0 / 2.1 / 2.2 Level A + AA scan (axe-core) of every template type on
 * every website, at desktop (1280 px) and phone (360 px) width, plus the high-contrast view.
 * Any violation fails the test. What is scanned: a11y/targets.js.
 *
 * Each test writes its axe result to $QUALITY_OUT/a11y/axe/<project>/<site>--<template>.json;
 * a11y/report.js turns those into the HTML/JSON report used by the Go-Live acceptance report.
 *
 * Automated tools find only part of all WCAG failures; the remaining success criteria are covered
 * by the manual checklist in docs/testing/quality-gates.md.
 */
const path = require('path');
const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');
const { TARGETS, WCAG_TAGS, resultName } = require('./targets');
const { outDir, writeJson } = require('../lib/paths');

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
            // storage unavailable: the attribute check below then fails
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

      writeJson(path.join(outDir('a11y'), 'axe', testInfo.project.name, resultName(target)), {
        site: site.id,
        template: template.id,
        label: template.label,
        contrast,
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
