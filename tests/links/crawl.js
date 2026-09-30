#!/usr/bin/env node
'use strict';
/**
 * R-4.11-4 — zero broken links and no orphaned pages, by automated scan of all six websites in
 * both languages.
 *
 * 1. Crawl: starts at the English and Hindi home page of every site and follows every link on
 *    every page of the network (the six sites link to each other). Links, stylesheets, scripts,
 *    images and documents are all checked; HTML pages are parsed for further links.
 * 2. Broken links: internal targets answering 4xx/5xx or not answering fail the gate; links to a
 *    missing #fragment on a crawled page fail too. External links are checked (HEAD, then GET;
 *    LINKS_EXTERNAL_TIMEOUT ms) and reported as warnings only — other websites are outside TMC's
 *    control and may block automated checks.
 * 3. Orphans: every published URL of each site that the crawl never reached fails the gate.
 *    The list of published URLs comes from the database (scripts/published-urls.php, written to
 *    $LINKS_INVENTORY_DIR/published-<site>.json — what CI uses); without that file, from the public
 *    REST API, and failing that from the XML sitemap. With none of them the site fails the gate.
 *
 * Not followed (checked only): search results, filtered listings, calendar months, downloads and
 * other URLs with query strings except the listing views (?view=archive|past|calendar), so the
 * crawl is finite. Never requested: wp-admin, wp-login.php, xmlrpc.php, logout/feeds of comments.
 *
 * Environment: QUALITY_SITES (subset of site ids), LINKS_INVENTORY_DIR (default
 * $QUALITY_OUT/links/inventory), LINKS_EXTERNAL (0 = skip external checks), LINKS_CONCURRENCY (8),
 * LINKS_MAX_PAGES (5000), LINKS_EXTERNAL_TIMEOUT (10000).
 * Output: $QUALITY_OUT/links/{summary.json, broken.csv, orphans.csv, external.csv, report.html}.
 * Exit code 1 when there are internal broken links, missing fragments or orphans.
 */
const fs = require('fs');
const path = require('path');
const { SITES, host, isInternalHost, origin, siteForUrl } = require('../lib/sites');
const { mapLimit, request } = require('../lib/http');
const { outDir, readJson, writeJson } = require('../lib/paths');
const { csv, esc, page, status, table } = require('../lib/html');

const OUT = outDir('links');
const INVENTORY_DIR = path.resolve(process.env.LINKS_INVENTORY_DIR || path.join(OUT, 'inventory'));
const CHECK_EXTERNAL = process.env.LINKS_EXTERNAL !== '0';
const CONCURRENCY = Math.max(1, Number(process.env.LINKS_CONCURRENCY || 8));
const MAX_PAGES = Math.max(10, Number(process.env.LINKS_MAX_PAGES || 5000));
const EXTERNAL_TIMEOUT = Math.max(1000, Number(process.env.LINKS_EXTERNAL_TIMEOUT || 10000));
const wantedSites = (process.env.QUALITY_SITES || '').split(',').map((s) => s.trim()).filter(Boolean);
const sites = wantedSites.length ? SITES.filter((site) => wantedSites.includes(site.id)) : SITES;

const NEVER_REQUEST = [/^\/wp-admin(\/|$)/, /^\/wp-login\.php/, /^\/xmlrpc\.php/, /^\/wp-cron\.php/];
const PAGE_TAGS = new Set(['a', 'area', 'iframe']);
const SKIP_LINK_RELS = new Set(['dns-prefetch', 'preconnect', 'profile', 'pingback']);
const PAGE_LINK_RELS = new Set(['alternate', 'canonical', 'next', 'prev']);

/* ---------------------------------------------------------------- HTML parsing (no dependencies) */

const NAMED = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', '#038': '&' };
function decodeEntities(value) {
  return value.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (match, name) => {
    if (name[0] === '#') {
      const code = name[1] === 'x' || name[1] === 'X' ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);
      return Number.isFinite(code) ? String.fromCodePoint(code) : match;
    }
    return Object.prototype.hasOwnProperty.call(NAMED, name.toLowerCase()) ? NAMED[name.toLowerCase()] : match;
  });
}

function attributes(source) {
  const attrs = {};
  const pattern = /([^\s"'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
  let match;
  while ((match = pattern.exec(source))) {
    const name = match[1].toLowerCase();
    if (!(name in attrs)) {
      attrs[name] = decodeEntities(match[2] ?? match[3] ?? match[4] ?? '');
    }
  }
  return attrs;
}

/** Links and element ids of an HTML document. */
function parse(html, pageUrl) {
  // Comments and the bodies of scripts, styles and templates cannot contain links to check.
  const clean = html
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/(<script\b[^>]*>)[\s\S]*?<\/script>/gi, '$1')
    .replace(/(<style\b[^>]*>)[\s\S]*?<\/style>/gi, '$1')
    .replace(/(<template\b[^>]*>)[\s\S]*?<\/template>/gi, '$1');
  let base = pageUrl;
  const baseTag = clean.match(/<base\b([^>]*)>/i);
  if (baseTag) {
    const href = attributes(baseTag[1]).href;
    if (href) {
      try {
        base = new URL(href, pageUrl).href;
      } catch (e) {
        base = pageUrl;
      }
    }
  }
  const links = [];
  const ids = new Set();
  const tagPattern = /<([a-z][a-z0-9-]*)\b([^>]*)>/gi;
  let match;
  while ((match = tagPattern.exec(clean))) {
    const tag = match[1].toLowerCase();
    const attrs = attributes(match[2]);
    if (attrs.id) {
      ids.add(attrs.id);
    }
    if (tag === 'a' && attrs.name) {
      ids.add(attrs.name);
    }
    const add = (value, kind) => {
      if (value !== undefined && value.trim() !== '') {
        links.push({ raw: value.trim(), tag, kind });
      }
    };
    if (tag === 'a' || tag === 'area') {
      add(attrs.href, 'page');
    } else if (tag === 'link') {
      const rels = String(attrs.rel || '').toLowerCase().split(/\s+/).filter(Boolean);
      if (!rels.some((rel) => SKIP_LINK_RELS.has(rel)) && !rels.includes('https://api.w.org/')) {
        add(attrs.href, rels.some((rel) => PAGE_LINK_RELS.has(rel)) && !/xml|json/.test(String(attrs.type || '')) ? 'page' : 'resource');
      }
    } else if (tag === 'img' || tag === 'source' || tag === 'script' || tag === 'iframe' || tag === 'video' || tag === 'audio' || tag === 'embed') {
      add(attrs.src, PAGE_TAGS.has(tag) ? 'page' : 'resource');
      for (const candidate of String(attrs.srcset || '').split(',')) {
        add(candidate.trim().split(/\s+/)[0], 'resource');
      }
      if (tag === 'video') {
        add(attrs.poster, 'resource');
      }
    } else if (tag === 'object') {
      add(attrs.data, 'resource');
    }
  }
  const resolved = [];
  for (const link of links) {
    if (/^(mailto|tel|javascript|data|blob|about|sms):/i.test(link.raw)) {
      continue;
    }
    let target;
    try {
      target = new URL(link.raw, base);
    } catch (e) {
      resolved.push({ ...link, url: link.raw, invalid: true });
      continue;
    }
    if (target.protocol !== 'http:' && target.protocol !== 'https:') {
      continue;
    }
    const fragment = target.hash ? decodeURIComponent(target.hash.slice(1)) : '';
    target.hash = '';
    resolved.push({ ...link, url: target.href, fragment });
  }
  return { links: resolved, ids };
}

/* ---------------------------------------------------------------- crawl */

function normalise(value) {
  try {
    const parsed = new URL(value);
    parsed.hash = '';
    parsed.hostname = parsed.hostname.toLowerCase();
    return parsed.href;
  } catch (e) {
    return value;
  }
}

function neverRequest(value) {
  const parsed = new URL(value);
  return NEVER_REQUEST.some((pattern) => pattern.test(parsed.pathname)) || parsed.searchParams.has('replytocom');
}

/** Pages whose links are followed: no query string, or only the listing view selector. */
function expandable(value) {
  const parsed = new URL(value);
  if (!isInternalHost(parsed.hostname) || /\/(feed|wp-json)(\/|$)/.test(parsed.pathname)) {
    return false;
  }
  const keys = [...parsed.searchParams.keys()];
  return keys.length === 0 || (keys.length === 1 && keys[0] === 'view' && ['archive', 'past', 'calendar'].includes(parsed.searchParams.get('view')));
}

const targets = new Map(); // normalised URL -> record
const externals = new Map(); // URL -> { url, sources: Set }
const fragments = []; // { from, url, fragment }
const idsByUrl = new Map();
const invalid = []; // { from, raw }
const queue = [];
let parsedPages = 0;
let limitReached = false;

function addSource(record, from) {
  if (from && record.sources.size < 25) {
    record.sources.add(from);
  }
  if (from) {
    record.sourceCount += 1;
  }
}

function discover(url, kind, from) {
  const key = normalise(url);
  let record = targets.get(key);
  if (!record) {
    record = { url: key, kind, sources: new Set(), sourceCount: 0, status: null, finalUrl: '', redirects: [], error: '', contentType: '' };
    targets.set(key, record);
    if (neverRequest(key)) {
      record.status = 'skipped';
    } else {
      queue.push(record);
    }
  } else if (kind === 'page' && record.kind !== 'page') {
    record.kind = 'page'; // a resource URL also linked as a page gets parsed when it is HTML
    if (record.status !== null && record.status !== 'skipped' && !record.parsed) {
      queue.push(record);
      record.status = null;
    }
  }
  addSource(record, from);
  return record;
}

async function visit(record) {
  const isPage = record.kind === 'page';
  let response = await request(record.url, { method: isPage ? 'GET' : 'HEAD', readBody: isPage, timeout: 30000 });
  if (!isPage && (response.status === 405 || response.status === 501)) {
    response = await request(record.url, { method: 'GET', readBody: false, timeout: 30000 });
  }
  record.status = response.status;
  record.error = response.error || '';
  record.finalUrl = normalise(response.finalUrl || record.url);
  record.redirects = response.redirects || [];
  record.contentType = String((response.headers && response.headers['content-type']) || '');
  if (record.finalUrl !== record.url) {
    // The redirect target counts as reached (for orphan detection) and is checked like any link.
    const final = discover(record.finalUrl, record.kind, null);
    final.viaRedirect = true;
  }
  if (!isPage || response.status < 200 || response.status >= 300 || !record.contentType.includes('text/html')) {
    return;
  }
  record.parsed = true;
  const { links, ids } = parse(response.body, record.finalUrl);
  idsByUrl.set(record.finalUrl, ids);
  if (!expandable(record.finalUrl)) {
    return;
  }
  parsedPages += 1;
  if (parsedPages > MAX_PAGES) {
    limitReached = true;
    return;
  }
  for (const link of links) {
    if (link.invalid) {
      invalid.push({ from: record.finalUrl, raw: link.raw });
      continue;
    }
    const internal = isInternalHost(new URL(link.url).hostname);
    if (!internal) {
      const entry = externals.get(link.url) || { url: link.url, sources: new Set(), sourceCount: 0 };
      if (entry.sources.size < 25) {
        entry.sources.add(record.finalUrl);
      }
      entry.sourceCount += 1;
      externals.set(link.url, entry);
      continue;
    }
    discover(link.url, link.kind, record.finalUrl);
    if (link.fragment && link.kind === 'page') {
      fragments.push({ from: record.finalUrl, url: normalise(link.url), fragment: link.fragment });
    }
  }
}

async function crawl() {
  let active = 0;
  const worker = async () => {
    for (;;) {
      const next = queue.shift();
      if (!next) {
        if (active === 0) {
          return;
        }
        await new Promise((resolve) => setTimeout(resolve, 25));
        continue;
      }
      active += 1;
      try {
        await visit(next);
      } catch (error) {
        next.status = 0;
        next.error = error.message;
      } finally {
        active -= 1;
      }
    }
  };
  await Promise.all(Array.from({ length: CONCURRENCY }, worker));
}

/* ---------------------------------------------------------------- inventory */

async function sitemapUrls(site) {
  const seen = new Set();
  const urls = [];
  const fetchMap = async (mapUrl, depth) => {
    if (seen.has(mapUrl) || depth > 3) {
      return false;
    }
    seen.add(mapUrl);
    const response = await request(mapUrl, { timeout: 30000 });
    if (response.status !== 200 || !/<(urlset|sitemapindex)\b/.test(response.body)) {
      return false;
    }
    const locs = [...response.body.matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/g)].map((m) => decodeEntities(m[1]));
    if (/<sitemapindex\b/.test(response.body)) {
      for (const loc of locs) {
        await fetchMap(loc, depth + 1);
      }
    } else {
      urls.push(...locs);
    }
    return true;
  };
  const found = await fetchMap(`${origin(site)}/wp-sitemap.xml`, 0);
  return found ? urls : null;
}

/** Published content from the public REST API (all languages), when it is reachable. */
async function restUrls(site) {
  const typesResponse = await request(`${origin(site)}/wp-json/wp/v2/types`, { timeout: 30000 });
  if (typesResponse.status !== 200) {
    return null;
  }
  let types;
  try {
    types = JSON.parse(typesResponse.body);
  } catch (e) {
    return null;
  }
  const items = [];
  for (const [type, info] of Object.entries(types)) {
    if (type === 'attachment' || type === 'nav_menu_item' || type.startsWith('wp_') || !info.rest_base || /[()?]/.test(info.rest_base)) {
      continue;
    }
    for (let pageNo = 1, pages = 1; pageNo <= pages && pageNo <= 50; pageNo++) {
      const response = await request(`${origin(site)}/wp-json/wp/v2/${info.rest_base}?per_page=100&page=${pageNo}&_fields=id,link,title`, { timeout: 30000 });
      if (response.status !== 200) {
        return null;
      }
      pages = Number(response.headers['x-wp-totalpages'] || 1);
      for (const item of JSON.parse(response.body)) {
        const title = decodeEntities(String((item.title && item.title.rendered) || '').replace(/<[^>]*>/g, ''));
        items.push({ id: item.id, type, lang: '', title, url: item.link });
      }
    }
  }
  return items;
}

async function inventory(site) {
  const file = path.join(INVENTORY_DIR, `published-${site.id}.json`);
  const data = readJson(file);
  if (data && Array.isArray(data.urls)) {
    return { source: `database (${path.basename(file)})`, items: data.urls.map((item) => ({ ...item, url: normalise(item.url) })) };
  }
  const fromRest = await restUrls(site);
  if (fromRest) {
    return { source: 'REST API', items: fromRest.map((item) => ({ ...item, url: normalise(item.url) })) };
  }
  const fromSitemap = await sitemapUrls(site);
  if (fromSitemap) {
    return { source: 'XML sitemap', items: fromSitemap.map((url) => ({ url: normalise(url), type: '', lang: '', title: '' })) };
  }
  return { source: '', items: null };
}

/* ---------------------------------------------------------------- main */

async function main() {
  for (const site of sites) {
    discover(`${origin(site)}/`, 'page', null);
    discover(`${origin(site)}/hi/`, 'page', null);
  }
  const started = Date.now();
  await crawl();
  const crawlSeconds = Math.round((Date.now() - started) / 1000);

  // Fragments: only checkable when the target page was parsed.
  const missingFragments = [];
  for (const item of fragments) {
    const record = targets.get(item.url);
    const finalUrl = record ? record.finalUrl || record.url : item.url;
    const ids = idsByUrl.get(finalUrl);
    if (ids && !ids.has(item.fragment) && item.fragment !== 'top' && item.fragment !== '') {
      missingFragments.push(item);
    }
  }

  // External links.
  const externalList = [...externals.values()];
  if (CHECK_EXTERNAL) {
    await mapLimit(externalList, 4, async (entry) => {
      let response = await request(entry.url, { method: 'HEAD', readBody: false, timeout: EXTERNAL_TIMEOUT });
      if (response.status === 0 || response.status >= 400) {
        response = await request(entry.url, { method: 'GET', readBody: false, timeout: EXTERNAL_TIMEOUT });
      }
      entry.status = response.status;
      entry.error = response.error || '';
      entry.finalUrl = response.finalUrl;
    });
  }

  // Broken internal targets.
  const all = [...targets.values()];
  const broken = all.filter((record) => record.status !== 'skipped' && (record.status === 0 || record.status >= 400));
  const redirects = all.filter((record) => record.redirects.length && record.sourceCount > 0 && record.status >= 200 && record.status < 400);
  const externalProblems = externalList.filter((entry) => CHECK_EXTERNAL && (entry.status === 0 || entry.status >= 400));

  // Orphans.
  const reached = new Set();
  for (const record of all) {
    if (typeof record.status === 'number' && record.status >= 200 && record.status < 300) {
      reached.add(record.url);
      reached.add(record.finalUrl);
    }
  }
  const siteSummary = {};
  const orphans = [];
  for (const site of SITES) {
    const selected = sites.includes(site);
    if (!selected) {
      siteSummary[site.id] = { status: 'NOT-RUN' };
      continue;
    }
    const inv = await inventory(site);
    const siteOrphans = inv.items ? inv.items.filter((item) => !reached.has(item.url)) : [];
    orphans.push(...siteOrphans.map((item) => ({ site: site.id, ...item })));
    const onSite = (url) => {
      const owner = siteForUrl(url);
      return owner && owner.id === site.id;
    };
    const siteBroken = broken.filter((record) => [...record.sources].some(onSite) || (!record.sources.size && onSite(record.url)));
    const siteFragments = missingFragments.filter((item) => onSite(item.from));
    const siteExternal = externalProblems.filter((entry) => [...entry.sources].some(onSite));
    const pagesCrawled = all.filter((record) => record.parsed && onSite(record.url)).length;
    const failures = siteBroken.length + siteFragments.length + siteOrphans.length;
    siteSummary[site.id] = {
      status: failures ? 'FAIL' : inv.items ? 'PASS' : 'FAIL',
      pagesCrawled,
      checks: all.filter((record) => onSite(record.url)).length,
      failures,
      brokenInternal: siteBroken.length,
      missingFragments: siteFragments.length,
      externalWarnings: siteExternal.length,
      orphans: siteOrphans.length,
      published: inv.items ? inv.items.length : null,
      inventorySource: inv.source || 'none (no inventory file and no XML sitemap: orphan check not possible)',
    };
  }

  const failed = broken.length + missingFragments.length + orphans.length + invalid.length > 0 || limitReached || Object.values(siteSummary).some((s) => s.status === 'FAIL');
  const summary = {
    suite: 'links',
    title: 'Links and orphaned pages (crawler)',
    generatedAt: new Date().toISOString(),
    tool: 'tests/links/crawl.js',
    status: failed ? 'FAIL' : 'PASS',
    totals: {
      urlsChecked: all.filter((record) => record.status !== 'skipped').length,
      pagesParsed: parsedPages,
      brokenInternal: broken.length,
      missingFragments: missingFragments.length,
      invalidUrls: invalid.length,
      redirectedLinks: redirects.length,
      externalChecked: CHECK_EXTERNAL ? externalList.length : 0,
      externalWarnings: externalProblems.length,
      orphans: orphans.length,
      crawlSeconds,
      limitReached,
    },
    report: 'report.html',
    sites: siteSummary,
  };
  writeJson(path.join(OUT, 'summary.json'), summary);

  const sourcesOf = (record) => [...record.sources].slice(0, 5).join(' ');
  fs.writeFileSync(
    path.join(OUT, 'broken.csv'),
    csv(
      ['severity', 'type', 'url', 'status', 'error', 'linked_from_count', 'linked_from_examples'],
      [
        ...broken.map((r) => ['error', r.kind === 'page' ? 'internal link' : 'internal resource', r.url, r.status, r.error, r.sourceCount, sourcesOf(r)]),
        ...missingFragments.map((f) => ['error', 'missing fragment', `${f.url}#${f.fragment}`, '', 'no element with this id', 1, f.from]),
        ...invalid.map((i) => ['error', 'invalid URL', i.raw, '', 'cannot be parsed', 1, i.from]),
        ...externalProblems.map((e) => ['warning', 'external link', e.url, e.status, e.error, e.sourceCount, [...e.sources].slice(0, 5).join(' ')]),
        ...redirects.map((r) => ['notice', 'redirected link', r.url, r.redirects.map((x) => x.status).join('>'), `→ ${r.finalUrl}`, r.sourceCount, sourcesOf(r)]),
      ]
    )
  );
  fs.writeFileSync(path.join(OUT, 'orphans.csv'), csv(['site', 'url', 'type', 'language', 'title'], orphans.map((o) => [o.site, o.url, o.type, o.lang, o.title])));
  fs.writeFileSync(
    path.join(OUT, 'external.csv'),
    csv(['url', 'status', 'error', 'linked_from_count', 'linked_from_examples'], externalList.map((e) => [e.url, CHECK_EXTERNAL ? e.status : 'not checked', e.error || '', e.sourceCount, [...e.sources].slice(0, 5).join(' ')]))
  );

  const linkList = (record) => [...record.sources].slice(0, 5).map((s) => `<a href="${esc(s)}">${esc(s)}</a>`).join('<br>') + (record.sourceCount > 5 ? `<br>… ${record.sourceCount} links in total` : '');
  let body = `<h1>Links and orphaned pages</h1>
<p class="meta">Generated ${esc(summary.generatedAt)} · ${sites.length} websites, English and Hindi · crawl ${crawlSeconds} s</p>
<p>Overall result: ${status(summary.status)}</p>
<ul class="summary">
  <li><strong>${summary.totals.urlsChecked}</strong> internal URLs checked</li>
  <li><strong>${summary.totals.pagesParsed}</strong> pages crawled</li>
  <li><strong>${broken.length}</strong> broken internal</li>
  <li><strong>${missingFragments.length}</strong> missing #fragments</li>
  <li><strong>${orphans.length}</strong> orphaned pages</li>
  <li><strong>${externalProblems.length}</strong> external warnings (of ${summary.totals.externalChecked})</li>
</ul>
${limitReached ? `<p>${status('FAIL')} The crawl stopped at LINKS_MAX_PAGES = ${MAX_PAGES} pages; results are incomplete.</p>` : ''}
<h2>Result by website</h2>
${table(
  'Links and orphans by website',
  [
    { label: 'Website', cell: (r) => esc(r.name) },
    { label: 'Result', cell: (r) => status(r.status) },
    { label: 'Pages crawled', cell: (r) => esc(r.pagesCrawled ?? '–') },
    { label: 'Broken internal', cell: (r) => esc(r.brokenInternal ?? '–') },
    { label: 'Missing fragments', cell: (r) => esc(r.missingFragments ?? '–') },
    { label: 'Orphans', cell: (r) => esc(r.orphans ?? '–') },
    { label: 'Published URLs (source)', cell: (r) => esc(r.published === undefined ? '–' : `${r.published ?? '–'} (${r.inventorySource})`) },
    { label: 'External warnings', cell: (r) => esc(r.externalWarnings ?? '–') },
  ],
  SITES.map((site) => ({ name: site.name, ...siteSummary[site.id] }))
)}
<h2>Broken internal links and resources</h2>
${table(
  'Broken internal links',
  [
    { label: 'URL', cell: (r) => `<a href="${esc(r.url)}">${esc(r.url)}</a>` },
    { label: 'Status', cell: (r) => esc(r.status || r.error) },
    { label: 'Linked from', cell: linkList },
  ],
  broken,
  'No broken internal links.'
)}
<h2>Links to missing fragments</h2>
${table(
  'Links to a #fragment that does not exist on the target page',
  [
    { label: 'Link', cell: (f) => esc(`${f.url}#${f.fragment}`) },
    { label: 'On page', cell: (f) => `<a href="${esc(f.from)}">${esc(f.from)}</a>` },
  ],
  missingFragments,
  'None.'
)}
<h2>Orphaned pages</h2>
<p>Published pages that cannot be reached by following links from the home pages.</p>
${table(
  'Orphaned pages',
  [
    { label: 'Website', cell: (o) => esc(o.site) },
    { label: 'URL', cell: (o) => `<a href="${esc(o.url)}">${esc(o.url)}</a>` },
    { label: 'Type', cell: (o) => esc(o.type) },
    { label: 'Language', cell: (o) => esc(o.lang) },
    { label: 'Title', cell: (o) => esc(o.title) },
  ],
  orphans,
  'No orphaned pages.'
)}
<h2>External links (warnings)</h2>
${table(
  'External links that did not answer successfully',
  [
    { label: 'URL', cell: (e) => `<a href="${esc(e.url)}">${esc(e.url)}</a>` },
    { label: 'Status', cell: (e) => esc(e.status || e.error) },
    { label: 'Linked from', cell: linkList },
  ],
  externalProblems,
  CHECK_EXTERNAL ? 'All external links answered.' : 'External links were not checked (LINKS_EXTERNAL=0).'
)}
<h2>Redirected links (notice)</h2>
${table(
  'Links that go through a redirect',
  [
    { label: 'URL', cell: (r) => esc(r.url) },
    { label: 'Redirects', cell: (r) => esc(`${r.redirects.map((x) => x.status).join(' → ')} → ${r.finalUrl}`) },
    { label: 'Linked from', cell: linkList },
  ],
  redirects,
  'None.'
)}
<p>Full lists: <a href="broken.csv">broken.csv</a>, <a href="orphans.csv">orphans.csv</a>, <a href="external.csv">external.csv</a>.</p>`;
  fs.writeFileSync(path.join(OUT, 'report.html'), page('Links and orphaned pages', body));

  console.log(`links: ${summary.status} — ${summary.totals.urlsChecked} URLs, ${parsedPages} pages, ${broken.length} broken, ${missingFragments.length} missing fragments, ${orphans.length} orphans, ${externalProblems.length} external warnings (${crawlSeconds} s)`);
  for (const record of broken.slice(0, 20)) {
    console.log(`  BROKEN ${record.status || record.error} ${record.url} ← ${[...record.sources][0] || ''}`);
  }
  for (const item of missingFragments.slice(0, 20)) {
    console.log(`  FRAGMENT ${item.url}#${item.fragment} ← ${item.from}`);
  }
  for (const orphan of orphans.slice(0, 20)) {
    console.log(`  ORPHAN ${orphan.url}`);
  }
  for (const site of sites) {
    if (!siteSummary[site.id].published && siteSummary[site.id].published !== 0) {
      console.log(`  NO INVENTORY for ${host(site)}: ${siteSummary[site.id].inventorySource}`);
    }
  }
  process.exit(failed ? 1 : 0);
}

main().catch((error) => {
  console.error(error);
  process.exit(2);
});
