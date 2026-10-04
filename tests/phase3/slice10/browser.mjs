// Headless Chromium proof of slice 10 (docs/build-specs/reports-settings.md, "Proof", 4 and 6): the dashboard at 375 (cards stack, each table scrolls inside its card, never sideways) and 1280 (the three tables side by side); a count clicked lists exactly that count in the inbox; a report at both widths.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s10';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.helpdesk.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
for (const [name, vp] of [['375 x 740', { width: 375, height: 740 }], ['1280 x 800', { width: 1280, height: 800 }]]) {
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 2, isMobile: vp.width < 600, hasTouch: vp.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)|sendAbort|^htmx:afterRequest$/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  console.log(name + ' — Lena');
  await page.goto(mint(28), { waitUntil: 'networkidle' });
  await page.goto(BASE + '/reports/', { waitUntil: 'networkidle' });
  ok(await page.locator('.count-card').count() === 6 && await page.locator('#dash-workload-table').count() === 1, 'the dashboard: six count cards and the three tables');
  const a = await page.locator('#dash-open').boundingBox(), b = await page.locator('#dash-sla').boundingBox(), c = await page.locator('#dash-priority').boundingBox(), d = await page.locator('#dash-workload').boundingBox();
  if (vp.width < 600) {
    ok(b.y > a.y + a.height - 1, 'the cards stack on a phone (two a row)');
    ok(d.y > c.y + c.height - 1, 'the tables stack on a phone');
    const wl = await page.locator('#dash-workload-table-wrap').evaluate((el) => ({ sw: el.scrollWidth, cw: el.clientWidth, ov: getComputedStyle(el).overflowX }));
    ok(wl.ov === 'auto' && wl.sw >= wl.cw, `the workload table scrolls inside its card (${wl.sw} ≥ ${wl.cw}, overflow ${wl.ov})`);
  } else {
    ok(Math.abs(c.y - d.y) < 2, 'the three tables sit side by side on a desktop');
  }
  await widthOK(page, 'the dashboard', vp.width);
  await page.screenshot({ path: `${SHOTS}/${vp.width}-dashboard.png`, fullPage: false });
  for (const key of ['open', 'unassigned', 'needs-reply']) {
    await page.goto(BASE + '/reports/', { waitUntil: 'networkidle' });
    const n = parseInt(await page.locator(`#dash-${key}-n`).innerText(), 10);
    await page.click(`#dash-${key}-link`);
    await page.waitForURL(/\/inbox\?/, { timeout: 8000 });
    await page.waitForSelector('#ticket-list', { timeout: 8000, state: 'attached' });
    const rows = vp.width < 992 ? await page.locator('#ticket-list-cards [id^="ticket-card-"]:not([id$="-pick"]):not([id$="-link"])').count() : await page.locator('tr[id^="ticket-row-"]').count();
    ok(rows === n, `clicking ${key} (${n}) lists ${rows} tickets in the inbox`);
  }
  await page.goto(BASE + '/reports/volume?group_by=queue', { waitUntil: 'networkidle' });
  ok(await page.locator('#report-table').count() === 1 && await page.locator('#report-csv-link').count() === 1, 'the report page: the table and Download CSV');
  if (vp.width < 600) {
    const t = await page.locator('#report-table-wrap').evaluate((el) => ({ sw: el.scrollWidth, cw: el.clientWidth }));
    ok(t.sw > t.cw, `the wide table scrolls inside (${t.sw} > ${t.cw})`);
  }
  await widthOK(page, 'a report', vp.width);
  await page.selectOption('#report-filter-group', 'day');
  await page.waitForURL(/group_by=day/, { timeout: 8000 });
  ok((await page.locator('#report-table thead th').first().innerText()).toLowerCase() === 'day', 'changing the grouping re-renders the table (HTMX) and pushes the URL');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-report.png`, fullPage: false });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
