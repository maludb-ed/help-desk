// Headless Chromium proof of slice 1 (docs/build-specs/queues-people.md, "Proof", 8): every screen of the slice at 375 x 740 and 1280 x 800 with
// scrollWidth = viewport, every button at least 44 px tall on a phone, the flows through the real screens (add a queue, name a member, add an
// organization with a domain, add a requester, edit the hours and see the preview, save the settings), the field error shown in #flash, JavaScript off.
// Run through tests/phase3/slice1/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s1';
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
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403)/.test(m.text())) errors.push(m.text()); });   // a refused save answers 422, a refused screen 403 — both by design
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control:not(.form-control-sm)')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && e.type !== 'time' && e.type !== 'number'; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });

// ---- phone: the admin walks the slice ----
{
  const { ctx, page, errors } = await session(1, PHONE);
  console.log('375 x 740 — the admin');
  for (const [path, label, sel] of [['/settings/queues/', 'Queues', '#queues-list-cards'], ['/settings/queues/new', 'Add queue', '#queue-form'], ['/organizations/', 'Organizations', '#organizations-filters'], ['/organizations/new', 'Add organization', '#organization-form'],
      ['/requesters/', 'Requesters', '#requesters-filters'], ['/requesters/new', 'Add requester', '#requester-form'], ['/settings/sla/', 'SLA and hours', '#sla-list-policies'], ['/settings/sla/new', 'Add policy', '#policy-form'],
      ['/settings/sla/hours', 'New schedule', '#hours-form'], ['/settings/general', 'Help Desk settings', '#settings-form'], ['/settings/agents', 'Agents', '#agents-content']]) {
    await go(page, path);
    ok(await page.locator(sel).count() === 1, `${label} renders (${sel})`);
    await widthOK(page, label, 375);
    await touch(page, label);
  }
  await go(page, '/settings/queues/');
  await page.screenshot({ path: `${SHOTS}/phone-queues.png` });
  // add a queue through the form (HTMX): lands on the queue with the notice
  await go(page, '/settings/queues/new');
  await page.fill('#queue-form-field-name', 'SMOKE Browser queue');
  await page.selectOption('#queue-form-field-department', '5');
  await page.check('#queue-form-field-method-round_robin');
  await page.click('#queue-form-save-btn');
  await page.waitForSelector('#queue-view-content');
  ok(/\/settings\/queues\/\d+\?notice=saved$/.test(page.url()) && (await page.locator('#notice-banner').innerText()).includes('Saved the queue'), 'saving a queue lands on it with the notice (' + page.url() + ')');
  ok((await page.locator('#queue-view-member-list').innerText()).includes('Through IT'), 'IT\'s members are listed "Through IT"');
  await page.screenshot({ path: `${SHOTS}/phone-queue-view.png` });
  // name a member as lead
  // the record picker (docs/record-picker.md): open it, choose a row
  await page.click('#queue-member-form-field-member-open'); await page.waitForSelector('#record-picker .record-picker-row');
  await page.click('#record-picker .record-picker-row[data-label="SMOKE Priya"]'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  await page.check('#queue-member-form-field-lead');
  await page.click('#queue-member-form-save-btn');
  await page.waitForSelector('#queue-member-26-lead');
  ok(await page.locator('#queue-member-26-lead').count() === 1, 'Priya named as lead appears with the Lead badge');
  // a field error shows in #flash and the form stays
  await go(page, '/settings/queues/new');
  await page.fill('#queue-form-field-name', 'SMOKE No assignee');
  await page.check('#queue-form-field-method-default_assignee');
  await page.click('#queue-form-save-btn');
  await page.waitForSelector('#flash-message');
  ok((await page.locator('#flash-message').innerText()).includes('Name a default assignee') && await page.locator('#queue-form').count() === 1, 'a refused save shows the sentence in #flash and keeps the form');
  await page.screenshot({ path: `${SHOTS}/phone-queue-refused.png` });
  // an organization with a domain, then a requester for it
  await go(page, '/organizations/new');
  await page.fill('#organization-form-field-name', 'SMOKE Browser Co');
  await page.fill('#organization-form-field-domains', 'browserco.invalid');
  await page.click('#organization-form-save-btn');
  await page.waitForSelector('#organization-view-content');
  ok(await page.locator('#organization-view-domain-browserco-invalid').count() === 1, 'the organization lands on its page with the domain chip');
  await page.click('#organization-view-add-person');
  await page.waitForSelector('#requester-form');
  ok(await page.locator('#requester-form-field-organization').inputValue() !== '', 'Add from the organization preselects it');
  await page.fill('#requester-form-field-name', 'SMOKE Browser Person');
  await page.fill('#requester-form-field-email', 'bp@browserco.invalid');
  await page.click('#requester-form-save-btn');
  await page.waitForSelector('#requester-view-content');
  ok((await page.locator('#requester-view-organization').innerText()).includes('SMOKE Browser Co'), 'the requester lands on their page, in the company');
  await page.screenshot({ path: `${SHOTS}/phone-requester.png` });
  // the hours preview updates as you type
  const office = await page.evaluate(async () => { const r = await fetch('/settings/sla/', { headers: { Accept: 'application/json' } }); const d = await r.json(); return d.data.schedules.find((s) => s.is_default).schedule_id; });
  await go(page, '/settings/sla/hours?schedule=' + office);
  await page.fill('#hours-preview-field-from', '2026-10-09T16:30');
  await page.fill('#hours-preview-field-minutes', '60');
  await page.dispatchEvent('#hours-preview-field-minutes', 'change');
  await page.waitForFunction(() => (document.querySelector('#hours-preview')?.innerText || '').includes('lands'));
  ok((await page.locator('#hours-preview').innerText()).startsWith('60 minutes from Friday 16:30 lands'), 'the preview line answers: ' + (await page.locator('#hours-preview').innerText()));
  await page.screenshot({ path: `${SHOTS}/phone-hours.png` });
  // the settings save and land with the notice
  await go(page, '/settings/general');
  await page.fill('#settings-form-field-autoclose', '5');
  await page.click('#settings-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the settings') && (await page.locator('#settings-form-field-autoclose').inputValue()) === '5', 'the settings save and land back with the value');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: support staff on organizations and requesters, a lead on queues ----
{
  const { ctx, page, errors } = await session(27, DESK);
  console.log('1280 x 800 — support staff');
  for (const [path, label] of [['/organizations/', 'Organizations'], ['/requesters/', 'Requesters']]) { await go(page, path); await widthOK(page, label, 1280); }
  await go(page, '/organizations/');
  await page.screenshot({ path: `${SHOTS}/desktop-organizations.png` });
  await page.fill('#organizations-filter-q', 'browserco');
  await page.press('#organizations-filter-q', 'Enter');
  await page.waitForURL(/q=browserco/);
  ok((await page.locator('#organizations-list-cards .card').count()) === 1, 'the search narrows to one card (HTMX, URL pushed)');
  await go(page, '/requesters/');
  await page.selectOption('#requesters-filter-kind', 'external');
  await page.waitForURL(/kind=external/);
  ok((await page.locator('#requesters-list-cards .badge').allInnerTexts()).every((t) => t.trim() === 'External'), 'the kind filter shows externals only');
  await page.screenshot({ path: `${SHOTS}/desktop-requesters.png` });
  await go(page, '/settings/queues/');
  ok((await page.locator('#message-text').innerText()).includes("You may not change Help Desk's settings"), 'Marco (support staff, no Lead role) is refused the queues list in the right\'s words');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the forms post and land ----
{
  const { ctx, page } = await session(1, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/organizations/new');
  await page.fill('#organization-form-field-name', 'SMOKE No JS Co');
  await page.click('#organization-form-save-btn');
  await page.waitForLoadState('load');
  ok(/\/organizations\/\d+\?notice=saved$/.test(page.url()) && (await page.locator('#notice-banner').count()) === 1, 'a plain form POST lands on the record with the notice');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
