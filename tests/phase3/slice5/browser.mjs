// Headless Chromium proof of slice 5 (docs/build-specs/working-faster.md, "Proof", 7): the Canned button opens the picker under the composer (no modal), filters as you type, inserts at the caret,
// shows the chips; at 375 px the picker is full width and the buttons ≥ 44 px; no horizontal scroll at 375 and 1280 on the list, the form and the bulk page; the type select reloads the fields.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s5';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)|sendAbort|^htmx:afterRequest$/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn:not(.btn-sm), #page-content button.btn:not(.btn-sm), #page-content summary.btn:not(.btn-sm), #page-content select.form-select:not(.form-select-sm), #page-content .list-group-item-action')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and select is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — Marco (support)');
  await go(page, '/canned/');
  await widthOK(page, 'the canned list', 375);
  await touch(page, 'the canned list');
  await go(page, '/canned/new');
  await widthOK(page, 'the canned form', 375);
  await page.fill('#canned-form-field-body', 'Hi {{requester.first_name}}');
  await page.press('#canned-form-field-body', 'End');                                   // fill() fires no keyup; the preview listens for it
  await page.waitForFunction(() => (document.querySelector('#canned-preview-text') || {}).innerText === 'Hi Jo', null, { timeout: 10000 });
  ok(true, 'the preview renders as you type (Hi Jo)');
  await page.click('#canned-form-placeholder-ticket-number');
  ok((await page.inputValue('#canned-form-field-body')).endsWith('{{ticket.number}}'), 'a placeholder button inserts at the caret');
  await page.screenshot({ path: `${SHOTS}/phone-canned-form.png` });
  // the composer: Canned ▾ opens the picker under the composer
  await go(page, '/tickets/' + W.t_jo);
  await page.click('#ticket-reply-canned-btn');
  await page.waitForSelector('#canned-picker-card');
  ok(!(await page.locator('.modal.show').count()), 'the picker opens under the composer, no modal');
  const pick = await page.locator('#canned-picker-card').boundingBox(), comp = await page.locator('#ticket-composer').boundingBox();
  ok(pick.width >= comp.width - 64 && pick.x + pick.width <= 375.5, 'the picker is full width on a phone (' + Math.round(pick.width) + ' of the composer\'s ' + Math.round(comp.width) + ')');
  await touch(page, 'the picker');
  const n0 = await page.locator('#canned-picker-list button').count();
  await page.fill('#canned-picker-q', 'IT desk');
  await page.press('#canned-picker-q', 'End');
  await page.waitForFunction((n) => document.querySelectorAll('#canned-picker-list button').length < n, n0, { timeout: 10000 });
  ok(true, 'filters as you type (' + n0 + ' → ' + (await page.locator('#canned-picker-list button').count()) + ')');
  await page.fill('#ticket-reply-form-field-body', 'Dear Jo,\n\nRegards');
  await page.evaluate(() => { const ta = document.querySelector('#ticket-reply-form-field-body'); ta.focus(); ta.setSelectionRange(9, 9); ta.dispatchEvent(new Event('click')); });
  await page.click('#canned-picker-list button');
  await page.waitForFunction(() => /Dear Jo,\n.+\nRegards/s.test((document.querySelector('#ticket-reply-form-field-body') || {}).value || ''), null, { timeout: 10000 });
  ok(true, 'choosing one inserts the text at the caret: ' + JSON.stringify((await page.inputValue('#ticket-reply-form-field-body')).slice(0, 60)));
  ok(await shown(page, '#ticket-reply-form-field-apply-effects') && (await page.locator('#ticket-reply-canned-effects .badge').count()) >= 1, 'the side-effect chips and the checkbox travel with the reply');
  ok(!(await page.locator('#canned-picker-card').count()), 'the picker closes');
  await widthOK(page, 'the ticket page with the chips', 375);
  await page.screenshot({ path: `${SHOTS}/phone-composer-canned.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Lena (lead)');
  await go(page, '/canned/');
  await widthOK(page, 'the canned list', 1280);
  await go(page, '/inbox');
  await page.check('#ticket-row-' + W.t_jo + '-pick');
  await page.check('#ticket-row-' + W.t_jo2 + '-pick');
  await page.click('#ticket-list-bulk-btn');
  await page.waitForURL(/\/bulk\?/);
  await page.waitForSelector('#bulk-form');
  await widthOK(page, 'the bulk page', 1280);
  ok((await page.locator('[id^="bulk-target-"]').count()) === 2, 'the inbox selection opens /bulk with the two tickets');
  await page.selectOption('#bulk-form-field-priority', 'high');
  await page.check('#bulk-form-field-confirmed');
  await page.click('#bulk-form-apply-btn');
  await page.waitForSelector('#bulk-result-changed');
  ok((await page.locator('#bulk-result-changed').innerText()).includes('2 changed'), 'Apply → the result page: 2 changed');
  await page.screenshot({ path: `${SHOTS}/desktop-bulk-result.png` });
  // the ticket form's type select reloads the custom fields
  await go(page, '/tickets/' + W.t_jo + '/edit');
  const incident = await page.evaluate(() => { const o = [...document.querySelectorAll('#ticket-form-field-type option')].find((x) => x.textContent.trim() === 'Incident'); return o ? o.value : null; });
  await page.selectOption('#ticket-form-field-type', incident);
  await page.waitForFunction(() => (document.querySelector('#custom-fields') || {}).innerText && document.querySelector('#custom-fields').innerText.includes('SMOKE Root cause'), null, { timeout: 10000 });
  ok(true, 'choosing Incident reloads #custom-fields with Root cause');
  const question = await page.evaluate(() => { const o = [...document.querySelectorAll('#ticket-form-field-type option')].find((x) => x.textContent.trim() === 'Question'); return o ? o.value : null; });
  await page.selectOption('#ticket-form-field-type', question);
  await page.waitForFunction(() => !(document.querySelector('#custom-fields') || { innerText: 'SMOKE Root cause' }).innerText.includes('SMOKE Root cause'), null, { timeout: 10000 });
  ok(true, 'and Question drops it');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/canned/');
  ok(await shown(page, '#canned-list'), 'the canned list renders without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
