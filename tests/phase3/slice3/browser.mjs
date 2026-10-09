// Headless Chromium proof of slice 3 (docs/build-specs/tickets-core.md, "Proof", 11): the inbox as cards on the phone, the detail page's panels stacked with the composer
// reachable, scrollWidth = viewport on every screen, every button ≥ 44 px, no console errors, the flows (file, reply, move, assign), the dashboard whole with JavaScript off.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s3';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn:not(.btn-sm), #page-content button.btn:not(.btn-sm), #page-content summary.btn:not(.btn-sm), #page-content select.form-select:not(.form-select-sm)')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and select is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);

{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco (support)');
  await go(page, '/inbox');
  await widthOK(page, 'the inbox', 375);
  ok(await shown(page, '#ticket-list-cards') && !(await shown(page, '#ticket-list-table-card')), 'the inbox is cards on a phone');
  ok((await page.locator('#ticket-list-cards .ticket-card').count()) > 0, 'cards are listed');
  await touch(page, 'the inbox');
  await page.screenshot({ path: `${SHOTS}/phone-inbox.png` });
  await page.selectOption('#inbox-filter-status', 'new');
  await page.waitForURL(/status=new/);
  ok((await page.locator('#ticket-list-cards .badge').allInnerTexts()).some((t) => t.trim().toLowerCase() === 'new'), 'the status filter re-fetched #ticket-list (URL pushed)');
  // file a ticket (in IT help), then work it on its page
  await go(page, '/tickets/new');
  await widthOK(page, 'the new request form', 375);
  await page.fill('#ticket-form-field-subject', 'SMOKE browser request');
  await page.fill('#ticket-form-field-body', 'Filed from the phone.');
  await page.selectOption('#ticket-form-field-queue', String(W.it));
  await page.click('#ticket-form-save-btn');
  await page.waitForSelector('#ticket-view-content');
  ok(/\/tickets\/\d+$/.test(page.url()) && (await page.locator('#ticket-head-status').innerText()).toLowerCase().includes('new'), 'filing lands on the new ticket (' + page.url() + ')');
  await widthOK(page, 'the ticket page', 375);
  for (const s of ['#ticket-head', '#ticket-conversation', '#ticket-composer', '#ticket-fields', '#ticket-requester', '#ticket-links', '#ticket-timeline']) ok(await shown(page, s), `${s} is shown`);
  const comp = await page.locator('#ticket-composer').boundingBox();
  ok(comp.x >= 0 && comp.x + comp.width <= 375.5, 'the composer sits within the phone width');
  await touch(page, 'the ticket page');
  await page.screenshot({ path: `${SHOTS}/phone-ticket.png`, fullPage: false });
  // Snooze through the fields panel: new → pending needs the wake-up (a details block)
  await page.click('#ticket-move-pending-btn');
  await page.click('#ticket-move-pending-save-btn');
  await page.waitForFunction(() => (document.querySelector('#ticket-head-status')?.innerText || '').toLowerCase().includes('pending'));
  ok(true, 'the move Snooze sets Pending in the header');
  // reply through the composer (the requester is Marco himself here: a requester-side reply reopens it)
  await page.fill('#ticket-reply-form-field-body', 'SMOKE browser reply');
  await page.click('#ticket-reply-form-send-btn');
  await page.waitForFunction(() => (document.querySelector('#ticket-conversation')?.innerText || '').includes('SMOKE browser reply'));
  ok(true, 'a reply lands in the conversation without a page load');
  // assign to the agent
  // the record picker (docs/record-picker.md): open it, choose a row
  await page.click('#ticket-assign-form-field-assignee-open'); await page.waitForSelector('#record-picker .record-picker-row[data-value="40"]');
  await page.click('#record-picker .record-picker-row[data-value="40"]'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  await page.click('#ticket-assign-form-save-btn');
  await page.waitForFunction(() => (document.querySelector('#ticket-head-queue')?.innerText || '').includes('SMOKE Frontline'));
  ok(true, 'assigning to the agent shows it in the header');
  await page.screenshot({ path: `${SHOTS}/phone-ticket-after.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — Priya (a requester)');
  await go(page, '/my-tickets');
  await widthOK(page, 'my tickets', 375);
  ok(await shown(page, '#ticket-list-cards'), 'her tickets as cards');
  await go(page, '/tickets/' + W.t_p1);
  await widthOK(page, 'her ticket', 375);
  ok(!(await shown(page, '#ticket-fields')) && await shown(page, '#ticket-composer-reply') && !(await shown(page, '#ticket-composer-note')), 'no fields panel, the Reply composer, no Note');
  await page.screenshot({ path: `${SHOTS}/phone-requester-ticket.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Lena (lead)');
  await go(page, '/inbox');
  await widthOK(page, 'the inbox', 1280);
  ok(await shown(page, '#ticket-list-table-card') && !(await shown(page, '#ticket-list-cards')), 'the inbox is a table on a desktop');
  await page.screenshot({ path: `${SHOTS}/desktop-inbox.png` });
  await go(page, '/tickets/' + W.t_jo);
  await widthOK(page, 'the ticket page', 1280);
  const conv = await page.locator('#ticket-conversation').boundingBox(), fields = await page.locator('#ticket-fields').boundingBox();
  ok(fields.x > conv.x + conv.width - 1, 'the fields panel sits beside the conversation');
  await page.screenshot({ path: `${SHOTS}/desktop-ticket.png` });
  await go(page, '/tickets/' + W.t_jo + '/merge');
  await widthOK(page, 'the merge page', 1280);
  ok(await shown(page, '#ticket-merge-search'), 'the merge page renders for a lead');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(text.includes('Needs me') && text.includes('My tickets'), 'the dashboard is whole with JavaScript off');
  await go(page, '/tickets/' + W.t_jo);
  ok(await shown(page, '#ticket-conversation') && await shown(page, '#ticket-composer'), 'the ticket page renders its conversation and composer without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
