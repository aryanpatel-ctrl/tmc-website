'use strict';
/**
 * Playwright global setup: starts the *.localhost proxy for WebKit when QUALITY_LOCALHOST_PROXY=1
 * (developer Macs; see lib/localhost-proxy.js). Returns the teardown that stops it.
 */
const { start } = require('./localhost-proxy');

module.exports = async function globalSetup() {
  if (process.env.QUALITY_LOCALHOST_PROXY !== '1') {
    return undefined;
  }
  const server = await start();
  return async () => {
    await new Promise((resolve) => server.close(resolve));
  };
};
