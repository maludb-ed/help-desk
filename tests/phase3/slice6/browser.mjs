// Headless Chromium proof of slice 6 (docs/build-specs/requesters-doors.md, "Proof", 8): the public pages fit at 375 px (no horizontal scroll), the rating buttons ≥ 44 px, the reply box full width;
// at 1280 px the same pages centred at a readable width; the form files a request and the thank-you page shows the number; the ticket page's rating block for a member.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s6';
const state = JSON.parse(fs.readFileSync(process.env.HD_DEV_STATE + '/browser6.json', 'utf8'));   // the PHP proofs leave a token of a solved ticket here
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn:not(.btn-sm), #page-content button.btn:not(.btn-sm), #page-content label.btn, #page-content select.form-select')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and select is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
for (const [name, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 2, isMobile: vp.width < 600, hasTouch: vp.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  console.log(name + ' — a visitor');
  await page.goto(BASE + '/request', { waitUntil: 'networkidle' });
  await widthOK(page, 'the request form', vp.width);
  await touch(page, 'the request form');
  ok(!(await page.locator('nav.nxl-navigation').count()) && !(await page.locator('#assistant-bar').count()), 'no shell: no sidebar, no command bar');
  if (vp.width > 600) { const m = await page.locator('#page-content').boundingBox(); ok(m.width <= 721 && m.x > 200, 'centred at a readable width (' + Math.round(m.width) + ' px)'); }
  await page.fill('#request-field-name', 'SMOKE Browser');
  await page.fill('#request-field-email', 'browser-' + vp.width + '@customer.invalid');
  await page.fill('#request-field-subject', 'SMOKE from the browser');
  await page.fill('#request-field-body', 'The screen is black.');
  await page.click('#request-submit-btn');
  await page.waitForSelector('#request-done-number');
  ok(/^HD-\d{4}$/.test((await page.locator('#request-done-number').innerText()).trim()), 'the thank-you page shows the number');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-request-done.png` });
  await page.goto(BASE + '/t/' + state.token, { waitUntil: 'networkidle' });
  await widthOK(page, 'the secure ticket page', vp.width);
  await touch(page, 'the secure ticket page');
  ok(await page.locator('#public-rate').count() === 1 && (await page.locator('#public-rate-good').count() === 1 || await page.locator('#public-rate-done').count() === 1), 'the solved ticket shows the rating block (answered already by the PHP proof)');
  const box = await page.locator('#public-reply-body').boundingBox(), card = await page.locator('#public-reply').boundingBox();
  ok(box.width >= card.width - 64, 'the reply box is full width');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-secure-ticket.png`, fullPage: true });
  await page.goto(BASE + '/t/' + 'deadbeef'.repeat(6), { waitUntil: 'networkidle' });
  ok(await page.locator('#dead-link').count() === 1, 'a dead link shows its one page');
  await widthOK(page, 'the dead-link page', vp.width);
  ok(errors.length === 0, 'no page errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
