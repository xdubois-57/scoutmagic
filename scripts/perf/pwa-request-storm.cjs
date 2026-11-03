// ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
// Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
//
// Compare what one navigation costs in a browser tab versus the installed
// app (display-mode: standalone), on a running instance:
//
//   PLAYWRIGHT_BROWSERS_PATH=... node scripts/perf/pwa-request-storm.cjs <base-url> <email> <password>
//
// Logs in, grants the functional-cookie consent the offline cache needs,
// installs the service worker on a first load, then for three pages counts
// every request the page triggers in the six seconds after load and times
// the next navigation started right away. The standalone run fakes
// matchMedia('(display-mode: standalone)'), which is the only thing
// public/assets/js/offline-prefetch.js and offline-cache.js look at.
// Development tooling only — needs the repo's Playwright dev dependency.
const { chromium } = require('playwright-core');
const [base = 'http://localhost:8080', email = 'admin@example.invalid', password = ''] = process.argv.slice(2);

const STANDALONE_STUB = () => {
  const original = window.matchMedia.bind(window);
  window.matchMedia = (query) => query.includes('display-mode: standalone')
    ? { matches: true, media: query, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent() { return false; } }
    : original(query);
};

// One page, measured on its own: the loop that used to walk the three paths
// awaited in every turn, and the measurements must stay sequential (they
// share one tab and one network), so each path is now an explicit call.
async function measurePath(page, path) {
  const requests = [];
  const record = (r) => requests.push({ url: new URL(r.url()).pathname, type: r.resourceType() });
  page.on('request', record);
  const t0 = Date.now();
  await page.goto(base + path, { waitUntil: 'load' });
  const loadMs = Date.now() - t0;
  const t1 = Date.now();
  await page.goto(base + '/notifications', { waitUntil: 'load' });
  const nextNavigationMs = Date.now() - t1;
  await page.waitForTimeout(6000);
  page.off('request', record);
  const rendered = requests.filter((r) => ['document', 'fetch', 'xhr'].includes(r.type));
  return { path, loadMs, nextNavigationMs, requests: requests.length, htmlOrApi: rendered.length, paths: [...new Set(rendered.map((r) => r.url))] };
}

async function measureMode(browser, standalone) {
  const ctx = await browser.newContext({ serviceWorkers: 'allow' });
  await ctx.addCookies([{ name: 'cookie_consent', value: encodeURIComponent(JSON.stringify({ functional: true, analytics: false })), url: base }]);
  if (standalone) {
    await ctx.addInitScript(STANDALONE_STUB);
  }
  const page = await ctx.newPage();
  await page.goto(base + '/login');
  const csrf = await page.getAttribute('#csrf-token', 'value');
  await page.evaluate(async ({ csrf, email, password }) => {
    await fetch('/login/password', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ _csrf_token: csrf, email, password, rgpd_consent: true }) });
  }, { csrf, email, password });
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.waitForTimeout(4000); // service worker install + app-shell precache, identical in both modes

  const results = [
    await measurePath(page, '/calendar'),
    await measurePath(page, '/account'),
    await measurePath(page, '/groups'),
  ];
  console.log(JSON.stringify({ mode: standalone ? 'installed' : 'browser', results }, null, 1));
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PERF_CHROMIUM || undefined });
  await measureMode(browser, false);
  await measureMode(browser, true);
  await browser.close();
})().catch((error) => { console.error(error); process.exit(1); });
