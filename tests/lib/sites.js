'use strict';
/**
 * The six websites and the URL of one representative page per template type.
 *
 * Every quality tool (E2E, visual, axe, Lighthouse, HTML validity, links, acceptance report) reads
 * this file, so a new template is added in one place. Paths point at content that setup.sh seeds on
 * every environment (clearly labelled sample items). Before Go-Live, when the sample items are
 * removed, point the single-item templates at real content with QUALITY_URLS_FILE (see
 * docs/testing/quality-gates.md).
 *
 * Environment:
 *   TMC_BASE_DOMAIN   base domain of the network (default tmc.localhost; UAT: tmc.100-79-142-44.sslip.io)
 *   TMC_SCHEME        http (default) or https
 *   QUALITY_URLS_FILE optional JSON file { "<template id>": "/path/" } overriding paths below
 */
const fs = require('fs');

const BASE = (process.env.TMC_BASE_DOMAIN || 'tmc.localhost').trim();
const SCHEME = (process.env.TMC_SCHEME || 'http').trim();

/** Indicative subdomains from the tender (§4.1); '' is the TMC umbrella site. */
const SITES = [
  { id: 'tmc', sub: '', name: 'Tata Memorial Centre' },
  { id: 'tmh', sub: 'tmh', name: 'Tata Memorial Hospital, Mumbai' },
  { id: 'hbchrcv', sub: 'hbchrcv', name: 'Homi Bhabha Cancer Hospital & Research Centre, Visakhapatnam' },
  { id: 'mpmmcc', sub: 'mpmmcc', name: 'Mahamana Pandit Madan Mohan Malaviya Cancer Centre & HBCH, Varanasi' },
  { id: 'hbchrcmzp', sub: 'hbchrcmzp', name: 'Homi Bhabha Cancer Hospital & Research Centre, Muzaffarpur' },
  { id: 'hbchpunjab', sub: 'hbchpunjab', name: 'Homi Bhabha Cancer Hospital, New Chandigarh' },
];

/**
 * One page per template type. Flags choose which tools use it:
 *   lighthouse  scored by Lighthouse (desktop + mobile)
 *   visual      screenshot baseline (Chromium)
 *   status      expected HTTP status (default 200)
 */
const TEMPLATES = [
  { id: 'home', label: 'Home', path: '/', lang: 'en', lighthouse: true, visual: true },
  { id: 'home-hi', label: 'Home (Hindi)', path: '/hi/', lang: 'hi', lighthouse: true, visual: true },
  { id: 'page-section-nav', label: 'Page with section navigation', path: '/patient-care/', lang: 'en', lighthouse: true, visual: true },
  { id: 'page-child', label: 'Child page', path: '/patient-care/patient-guide/', lang: 'en' },
  { id: 'page-hi', label: 'Page (Hindi)', path: '/hi/rogi-dekhbhal/', lang: 'hi' },
  { id: 'policy', label: 'Policy page', path: '/accessibility-statement/', lang: 'en', lighthouse: true, visual: true },
  { id: 'sitemap', label: 'Sitemap', path: '/sitemap/', lang: 'en', lighthouse: true, visual: true },
  { id: 'sitemap-hi', label: 'Sitemap (Hindi)', path: '/hi/sitemap-hi/', lang: 'hi' },
  { id: 'news-list', label: 'News list (category)', path: '/category/news/', lang: 'en', lighthouse: true },
  { id: 'news-single', label: 'News item', path: '/six-websites-one-platform-tmcs-unified-website-ecosystem/', lang: 'en', lighthouse: true, visual: true },
  { id: 'tenders', label: 'Tender list (current)', path: '/tenders/', lang: 'en', lighthouse: true, visual: true },
  { id: 'tenders-archive', label: 'Tender list (archive)', path: '/tenders/?view=archive', lang: 'en' },
  { id: 'tenders-hi', label: 'Tender list (Hindi)', path: '/hi/tenders/', lang: 'hi' },
  { id: 'tender-single', label: 'Tender', path: '/tenders/sample-tender-laboratory-consumables/', lang: 'en', lighthouse: true, visual: true },
  { id: 'careers', label: 'Careers list', path: '/careers/', lang: 'en', lighthouse: true },
  { id: 'job-single', label: 'Job opening', path: '/careers/sample-senior-resident-medical-oncology/', lang: 'en' },
  { id: 'events', label: 'Event list', path: '/events/', lang: 'en', lighthouse: true },
  // A fixed month long before any seeded event keeps the calendar screenshot stable.
  { id: 'events-calendar', label: 'Event calendar', path: '/events/?view=calendar&month=2025-01', lang: 'en', lighthouse: true, visual: true },
  { id: 'event-single', label: 'Event', path: '/events/sample-cme-session/', lang: 'en', lighthouse: true, visual: true },
  { id: 'departments', label: 'Department list', path: '/departments/', lang: 'en', lighthouse: true, visual: true },
  { id: 'department-single', label: 'Department', path: '/departments/medical-oncology/', lang: 'en' },
  { id: 'doctors', label: 'Find a Doctor', path: '/doctors/', lang: 'en', lighthouse: true, visual: true },
  { id: 'doctor-single', label: 'Doctor profile', path: '/doctors/sample-profile-a/', lang: 'en', lighthouse: true, visual: true },
  { id: 'search', label: 'Search results', path: '/?s=cancer', lang: 'en', lighthouse: true, visual: true },
  { id: 'not-found', label: 'Page not found (404)', path: '/quality-gate-missing-page/', lang: 'en', status: 404, visual: true },
];

function loadOverrides() {
  const file = process.env.QUALITY_URLS_FILE;
  if (!file) {
    return {};
  }
  const data = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    throw new Error(`${file}: expected an object { "template id": "/path/" }`);
  }
  return data;
}

const overrides = loadOverrides();
for (const template of TEMPLATES) {
  if (typeof overrides[template.id] === 'string') {
    template.path = overrides[template.id];
  }
  template.status = template.status || 200;
}

function host(site) {
  return site.sub ? `${site.sub}.${BASE}` : BASE;
}

function origin(site) {
  return `${SCHEME}://${host(site)}`;
}

function url(site, path) {
  return origin(site) + path;
}

function siteById(id) {
  const site = SITES.find((candidate) => candidate.id === id);
  if (!site) {
    throw new Error(`unknown site "${id}"`);
  }
  return site;
}

/** Which site a URL belongs to (null for external URLs). */
function siteForUrl(value) {
  let hostname;
  try {
    hostname = new URL(value).hostname.toLowerCase();
  } catch (e) {
    return null;
  }
  return SITES.find((site) => host(site) === hostname) || null;
}

function isInternalHost(hostname) {
  const name = String(hostname || '').toLowerCase();
  return name === BASE || name.endsWith(`.${BASE}`);
}

/** Every (site, template) pair: the URL set for axe and the HTML validator. */
function allPages() {
  const pages = [];
  for (const site of SITES) {
    for (const template of TEMPLATES) {
      pages.push({ site, template, url: url(site, template.path) });
    }
  }
  return pages;
}

/** File-name-safe slug. */
function slug(value) {
  return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'root';
}

module.exports = {
  BASE,
  SCHEME,
  SITES,
  TEMPLATES,
  allPages,
  host,
  isInternalHost,
  origin,
  siteById,
  siteForUrl,
  slug,
  url,
};
