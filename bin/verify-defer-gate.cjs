#!/usr/bin/env node
/**
 * Proves the tag-deferral gate in src/Google/Tags.php actually behaves.
 *
 *   node bin/verify-defer-gate.cjs
 *
 * It extracts the REAL gate JS out of Tags.php rather than keeping a copy, so this
 * test cannot quietly drift away from what ships.
 *
 * ⛔ CASE C IS THE ONE THAT MATTERS. Deferring tags until interaction has already
 * cost real money once, on a client site where a delayed
 * loader made every non-interacting visitor invisible to GA4 and paid clicks were
 * the expensive ones to lose. The campaign exemption is what stops that repeating.
 * Case E exists to prove the exemption is not so broad it defeats the feature.
 *
 * If C fails, DO NOT SHIP — paid attribution is silently broken and nothing on the
 * site will look wrong.
 */
const fs = require("fs");
const http = require("http");
const path = require("path");

/* playwright-core is the only dependency: `npm i --no-save playwright-core` and a
 * Chromium it can drive (`npx playwright-core install chromium`). */
let chromium;
try {
  ({ chromium } = require("playwright-core"));
} catch {
  console.error("playwright-core not found. Run: npm i --no-save playwright-core");
  process.exit(2);
}

const php = fs.readFileSync(path.join(__dirname, '..', 'src', 'Google', 'Tags.php'), 'utf8');
const m = php.match(/<script>\(function\(w,d\)\{[\s\S]*?\}\)\(window,document\);<\/script>/);
if (!m) throw new Error('gate script not found in Tags.php');
const gate = m[0].replace(/^<script>/, '').replace(/<\/script>$/, '');

const page = (extra) => `<!doctype html><html><body style="height:3000px">
<script>${gate}</script>
<script>
  window.__loaded = false;
  window.ajrLoadTags(function(){ window.__loaded = true; });
</script>${extra || ''}</body></html>`;

const srv = http.createServer((q, r) => { r.writeHead(200, {'Content-Type':'text/html'}); r.end(page()); }).listen(8741);

(async () => {
  const b = await chromium.launch({ channel: 'chrome' });
  const ctx = await b.newContext({ viewport: { width: 900, height: 700 } });
  const results = [];

  // A — plain URL, no interaction at all
  let p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8741/', { waitUntil: 'load' });
  await p.waitForTimeout(1200);
  results.push(['A no params, no interaction -> should NOT load', await p.evaluate(() => window.__loaded) === false]);

  // B — same page, then a scroll
  await p.mouse.wheel(0, 400);
  await p.waitForTimeout(600);
  results.push(['B after scroll             -> SHOULD load', await p.evaluate(() => window.__loaded) === true]);
  await p.close();

  // C — a paid click: gclid present, no interaction
  p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8741/?gclid=TESTCLICKID', { waitUntil: 'load' });
  await p.waitForTimeout(1200);
  results.push(['C ?gclid, no interaction   -> SHOULD load eagerly', await p.evaluate(() => window.__loaded) === true]);
  await p.close();

  // D — utm campaign, no interaction
  p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8741/?utm_source=newsletter&utm_medium=email', { waitUntil: 'load' });
  await p.waitForTimeout(1200);
  results.push(['D ?utm_source, no interaction -> SHOULD load eagerly', await p.evaluate(() => window.__loaded) === true]);
  await p.close();

  // E — an unrelated query string must NOT trigger the exemption
  p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8741/?s=search+term&page=2', { waitUntil: 'load' });
  await p.waitForTimeout(1200);
  results.push(['E ?s=, no interaction      -> should NOT load', await p.evaluate(() => window.__loaded) === false]);
  await p.close();

  let bad = 0;
  results.forEach(([n, ok]) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + n); if (!ok) bad++; });
  console.log(bad === 0 ? '\nALL PASS' : '\n' + bad + ' FAILED');
  await b.close(); srv.close();
})();
