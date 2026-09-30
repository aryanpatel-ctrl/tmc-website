'use strict';
/**
 * What the accessibility gate scans (shared by axe.spec.js and report.js).
 *
 * Every template on every site in the default view, plus every template on the TMC site in the
 * high-contrast view (GIGW: the contrast option must itself be accessible; its styles are the same
 * on every site, so one site is enough). Each target runs in the projects a11y-1280 and a11y-360.
 */
const { allPages } = require('../lib/sites');

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];
const PROJECTS = ['a11y-1280', 'a11y-360'];

const TARGETS = [
  ...allPages().map((target) => ({ ...target, contrast: 'normal' })),
  ...allPages()
    .filter((target) => target.site.id === 'tmc')
    .map((target) => ({ ...target, contrast: 'high' })),
];

function resultName(target) {
  return `${target.site.id}--${target.template.id}${target.contrast === 'high' ? '--high-contrast' : ''}.json`;
}

module.exports = { PROJECTS, TARGETS, WCAG_TAGS, resultName };
