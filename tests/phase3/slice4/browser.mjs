// Headless Chromium proof of slice 4 (docs/build-specs/views-board.md, "Proof", 7): at 375 px the column strip scrolls inside #board-columns (page scrollWidth = viewport), snap per column,
// buttons ≥ 44 px; at 1280 px five columns visible with the swimlane select in the header; a real drag between columns lands the card and the status; the expanded card on a drop into Solved.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s4';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const W = JSON.parse(fs.readFileSync(process.env.HD_DEV_STATE + '/world.json', 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.helpdesk.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)|sendAbort|^htmx:afterRequest$/.test(m.text())) errors.push(m.text()); });   // an aborted stale refresh (hx-sync) is by design
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn:not(.btn-sm), #page-content button.btn:not(.btn-sm), #page-content summary.btn:not(.btn-sm), #page-content select.form-select:not(.form-select-sm)')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and select is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
async function drag(page, cardSel, colSel) {
  const card = await page.locator(cardSel).boundingBox(), col = await page.locator(colSel).boundingBox();
  await page.mouse.move(card.x + card.width - 22, card.y + 20);                       // the priority badge: never the link, the select or the summary (Sortable's filter)
  await page.mouse.down();
  await page.mouse.move(card.x + card.width - 32, card.y + 28, { steps: 5 });
  await page.mouse.move(col.x + col.width / 2, col.y + 30, { steps: 20 });
  await page.mouse.move(col.x + col.width / 2, col.y + 36, { steps: 5 });
  await page.mouse.up();
}
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco (support)');
  await go(page, '/board');
  await widthOK(page, 'the board', 375);
  const strip = await page.evaluate(() => { const s = document.querySelector('#board-columns .board-strip'); return { sw: s.scrollWidth, cw: s.clientWidth, snap: getComputedStyle(s).scrollSnapType, cols: s.querySelectorAll('.board-column').length }; });
  ok(strip.sw > strip.cw && /x/.test(strip.snap) && strip.cols >= 5, `the column strip scrolls inside #board-columns (${strip.sw} > ${strip.cw}), snap ${strip.snap}, ${strip.cols} columns`);
  const colW = await page.locator('#board-column-new').boundingBox();
  ok(colW.width <= 375 && colW.width >= 300, 'one column per snap on a phone (' + Math.round(colW.width) + ' px)');
  await touch(page, 'the board');
  await page.screenshot({ path: `${SHOTS}/phone-board.png` });
  await go(page, '/views/');
  await widthOK(page, 'the views list', 375);
  await touch(page, 'the views list');
  await go(page, '/views/new?layout=board');
  await widthOK(page, 'the view form', 375);
  await page.screenshot({ path: `${SHOTS}/phone-view-form.png`, fullPage: false });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Lena (lead)');
  await go(page, '/board');
  await widthOK(page, 'the board', 1280);
  const vis = await page.evaluate(() => [...document.querySelectorAll('#board-columns .board-column')].filter((c) => { const r = c.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 1; }).length);
  ok(vis >= 5, `five columns visible (${vis})`);
  ok(await shown(page, '#board-filter-swimlane'), 'the swimlane select sits in the header');
  await page.screenshot({ path: `${SHOTS}/desktop-board.png` });
  // a real drag: the first New card into Open
  const firstNew = await page.locator('#board-column-new-cards .board-card').first();
  const id = await firstNew.getAttribute('data-ticket-id');
  const before = await page.locator('#board-column-open-cards .board-card').count();
  await drag(page, '#board-card-' + id, '#board-column-open-cards');
  await page.waitForFunction(([id]) => { const c = document.querySelector('#board-card-' + id); return c && c.dataset.column === 'open' && c.closest('#board-column-open-cards'); }, [id], { timeout: 10000 });
  ok(true, 'dragging a New card into Open re-renders it in Open (ticket ' + id + ')');
  await page.waitForFunction(([n]) => document.querySelectorAll('#board-column-open-cards .board-card').length >= n + 1, [before]);
  ok(true, 'and the region re-fetched with the card counted in Open');
  // a drag into Solved expands the card for a code
  await drag(page, '#board-card-' + id, '#board-column-solved-cards');
  await page.waitForSelector('#board-card-' + id + '-confirm', { timeout: 10000 });
  ok(await shown(page, '#board-card-' + id + '-field-resolution_code'), 'dropping into Solved expands the card with a resolution-code select');
  await page.screenshot({ path: `${SHOTS}/desktop-board-expanded.png` });
  await page.selectOption('#board-card-' + id + '-field-resolution_code', 'resolved');
  await page.click('#board-card-' + id + '-confirm-btn');
  await page.waitForFunction(([id]) => { const c = document.querySelector('#board-card-' + id); return c && c.dataset.column === 'solved' && !document.querySelector('#board-card-' + id + '-confirm'); }, [id], { timeout: 10000 });
  ok(true, 'Confirm solves it and the card settles in Solved');
  // the swimlane select re-fetches lanes (after the refresh the confirm triggered has settled)
  await page.waitForLoadState('networkidle');
  await page.selectOption('#board-filter-swimlane', 'priority');
  await page.waitForSelector('[id^="board-lane-"]', { timeout: 10000 });
  ok((await page.locator('[id^="board-lane-"]:not([id$="-header"])').count()) >= 1, 'choosing Priority swimlanes renders lanes without a page load');
  await page.screenshot({ path: `${SHOTS}/desktop-board-lanes.png` });
  await go(page, '/views/');
  ok(await shown(page, '#views-group-standard') && (await page.locator('[id^="view-card-"][id$="-open"]').count()) >= 8, 'the views list shows the standard views as cards');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/board');
  ok(await shown(page, '#board-columns') && (await page.locator('[id$="-move-select"]').count()) >= 1, 'the board renders its columns and the "Move to…" selects without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
