'use strict';
/**
 * Developer convenience: a tiny forward HTTP proxy that resolves *.localhost to 127.0.0.1.
 *
 * Chromium and Firefox resolve *.localhost to the loopback address themselves (RFC 6761); WebKit
 * uses the operating system resolver, which on macOS (and in containers without systemd-resolved)
 * does not. CI adds /etc/hosts entries instead (see .github/workflows/quality.yml), so this proxy is
 * only needed to run the WebKit projects on a developer Mac:
 *
 *   QUALITY_LOCALHOST_PROXY=1 npm run e2e
 *
 * Plain HTTP only (the local and CI stacks serve HTTP); the Host header is passed through, so
 * WordPress Multisite serves the right site.
 */
const http = require('http');
const { lookup } = require('./http');

const PORT = Number(process.env.QUALITY_PROXY_PORT || 18080);
const HOP_BY_HOP = ['connection', 'proxy-connection', 'keep-alive', 'proxy-authorization', 'te', 'trailer', 'upgrade'];

function start(port = PORT) {
  const server = http.createServer((req, res) => {
    let target;
    try {
      target = new URL(req.url);
    } catch (e) {
      res.writeHead(400, { 'Content-Type': 'text/plain' });
      res.end('absolute URL required');
      return;
    }
    if (target.protocol !== 'http:') {
      res.writeHead(501, { 'Content-Type': 'text/plain' });
      res.end('only http is proxied');
      return;
    }
    const headers = { ...req.headers };
    for (const name of HOP_BY_HOP) {
      delete headers[name];
    }
    const upstream = http.request(
      { hostname: target.hostname, port: target.port || 80, path: target.pathname + target.search, method: req.method, headers, lookup },
      (response) => {
        res.writeHead(response.statusCode || 502, response.headers);
        response.pipe(res);
      }
    );
    upstream.on('error', (error) => {
      if (!res.headersSent) {
        res.writeHead(502, { 'Content-Type': 'text/plain' });
      }
      res.end(error.message);
    });
    req.pipe(upstream);
  });
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', () => resolve(server));
  });
}

module.exports = { PORT, start };
