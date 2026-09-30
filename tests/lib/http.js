'use strict';
/**
 * Minimal HTTP client for the quality tools (no dependencies).
 *
 * - *.localhost always resolves to the loopback address (RFC 6761), so the tools work on a developer
 *   machine or CI runner without /etc/hosts entries.
 * - Redirects are followed manually (up to 10) and recorded, so reports can show redirect chains.
 * - Every request has a timeout; network errors are returned as { status: 0, error }.
 */
const http = require('http');
const https = require('https');
const dns = require('dns');

const USER_AGENT = 'TMC-QualityGates/1.0 (+docs/testing/quality-gates.md)';

function lookup(hostname, options, callback) {
  const name = String(hostname).toLowerCase();
  if (name === 'localhost' || name.endsWith('.localhost')) {
    if (options && options.all) {
      callback(null, [{ address: '127.0.0.1', family: 4 }]);
    } else {
      callback(null, '127.0.0.1', 4);
    }
    return;
  }
  dns.lookup(hostname, options, callback);
}

const agents = {
  'http:': new http.Agent({ keepAlive: true, maxSockets: 16, lookup }),
  'https:': new https.Agent({ keepAlive: true, maxSockets: 16, lookup }),
};

/** One request, no redirect handling. Resolves { status, headers, body } (body only when wanted). */
function requestOnce(target, { method = 'GET', timeout = 20000, readBody = true, headers = {} } = {}) {
  return new Promise((resolve) => {
    let parsed;
    try {
      parsed = new URL(target);
    } catch (e) {
      resolve({ status: 0, headers: {}, body: '', error: `invalid URL: ${target}` });
      return;
    }
    const client = parsed.protocol === 'https:' ? https : http;
    const agent = agents[parsed.protocol];
    if (!agent) {
      resolve({ status: 0, headers: {}, body: '', error: `unsupported protocol ${parsed.protocol}` });
      return;
    }
    const request = client.request(
      parsed,
      {
        method,
        agent,
        headers: { 'User-Agent': USER_AGENT, Accept: 'text/html,application/xhtml+xml,*/*;q=0.8', ...headers },
      },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => {
          if (readBody) {
            chunks.push(chunk);
          }
        });
        response.on('end', () => {
          resolve({ status: response.statusCode || 0, headers: response.headers, body: Buffer.concat(chunks).toString('utf8') });
        });
        response.on('error', (error) => resolve({ status: 0, headers: {}, body: '', error: error.message }));
      }
    );
    request.setTimeout(timeout, () => {
      request.destroy(new Error(`timeout after ${timeout} ms`));
    });
    request.on('error', (error) => resolve({ status: 0, headers: {}, body: '', error: error.message }));
    request.end();
  });
}

/**
 * Request with redirects followed. Resolves
 *   { url, finalUrl, status, headers, body, redirects: [{ url, status }], error? }
 */
async function request(target, options = {}) {
  const redirects = [];
  let current = target;
  for (let hop = 0; hop <= 10; hop++) {
    const response = await requestOnce(current, options);
    const location = response.headers && response.headers.location;
    if (response.status >= 300 && response.status < 400 && location) {
      redirects.push({ url: current, status: response.status });
      current = new URL(location, current).href;
      continue;
    }
    return { url: target, finalUrl: current, redirects, ...response };
  }
  return { url: target, finalUrl: current, redirects, status: 0, headers: {}, body: '', error: 'too many redirects' };
}

/** Run async work over items with a concurrency limit, preserving order of results. */
async function mapLimit(items, limit, worker) {
  const results = new Array(items.length);
  let next = 0;
  async function run() {
    while (next < items.length) {
      const index = next++;
      results[index] = await worker(items[index], index);
    }
  }
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, run));
  return results;
}

module.exports = { USER_AGENT, lookup, mapLimit, request, requestOnce };
