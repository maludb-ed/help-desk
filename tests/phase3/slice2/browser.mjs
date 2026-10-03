// Headless Chromium proof of slice 2 (docs/build-specs/vocabulary.md, "Proof", 8): every screen at 375 x 740 and 1280 x 800 with scrollWidth =
// viewport, the flows through the real screens (add a status, save a matrix cell, add a category under another, rename a tag, add a field), JavaScript off.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s2';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });

{
  const { ctx, page, errors } = await session(1, PHONE);
  console.log('375 x 740 — the admin');
  for (const [path, label, sel] of [['/settings/statuses/', 'Statuses', '#statuses-table'], ['/settings/statuses/new', 'Add status', '#status-form'], ['/settings/statuses/transitions', 'Transitions', '#transitions-grid'],
      ['/settings/vocabulary/', 'Types', '#types-table'], ['/settings/vocabulary/?tab=categories', 'Categories', '#categories-tree'], ['/settings/vocabulary/?tab=tags', 'Tags', '#tags-card'],
      ['/settings/vocabulary/categories/new', 'Add category', '#category-form'], ['/settings/fields/', 'Custom fields', '#fields-group-ticket'], ['/settings/fields/new', 'Add field', '#field-form']]) {
    await go(page, path);
    ok(await page.locator(sel).count() === 1, `${label} renders (${sel})`);
    await widthOK(page, label, 375);
  }
  // a status through the form
  await go(page, '/settings/statuses/new');
  await page.fill('#status-form-field-label', 'SMOKE Browser status');
  await page.check('#status-form-field-category-pending');
  await page.check('#status-form-field-color-warning');
  await page.click('#status-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok(page.url().includes('/settings/statuses/?notice=saved#status-row-') && (await page.locator('#statuses-table').innerText()).includes('SMOKE Browser status'), 'saving a status lands on the list with it there');
  await page.screenshot({ path: `${SHOTS}/phone-statuses.png` });
  // a matrix cell
  await go(page, '/settings/statuses/transitions');
  await page.click('#transition-new-pending-toggle');
  await page.fill('#transition-new-pending-label', 'SMOKE Park it');
  await page.click('#transition-new-pending-save-btn');
  await page.waitForFunction(() => (document.querySelector('#transition-new-pending-toggle')?.innerText || '').toLowerCase().includes('smoke park it'), null, { timeout: 10000 }).catch(() => {});
  ok((await page.locator('#transition-new-pending-toggle').innerText()).toLowerCase().includes('smoke park it'), 'saving a cell relabels the move in the grid (the theme upper-cases buttons by CSS)');
  await page.screenshot({ path: `${SHOTS}/phone-transitions.png` });
  // a category under another
  await go(page, '/settings/vocabulary/categories/new');
  await page.fill('#category-form-field-name', 'SMOKE Browser top');
  await page.click('#category-form-save-btn');
  await page.waitForSelector('#categories-tree');
  const topId = await page.evaluate(() => { const m = location.hash.match(/category-row-(\d+)/); return m ? m[1] : ''; });
  ok(topId !== '' && (await page.locator('#category-row-' + topId).count()) === 1, 'a top category lands in the tree (#category-row-' + topId + ')');
  await page.click('#category-row-' + topId + '-add-child-btn');
  await page.waitForSelector('#category-form');
  ok((await page.locator('#category-form-field-parent').inputValue()) === topId, 'Add child preselects the parent');
  await page.fill('#category-form-field-name', 'SMOKE Browser child');
  await page.click('#category-form-save-btn');
  await page.waitForSelector('#categories-tree');
  ok((await page.locator('#categories-tree').innerText()).includes('› SMOKE Browser child'), 'the child shows indented under its parent');
  await page.screenshot({ path: `${SHOTS}/phone-categories.png` });
  // a tag renamed inline
  await go(page, '/settings/vocabulary/?tab=tags');
  await page.fill('#tag-form-field-label', 'SMOKE Browser tag');
  await page.click('#tag-form-save-btn');
  await page.waitForSelector('#notice-banner');
  const tagId = await page.evaluate(() => { const m = location.hash.match(/tag-chip-(\d+)/); return m ? m[1] : ''; });
  await page.click('#tag-chip-' + tagId + '-toggle');
  await page.fill('#tag-chip-' + tagId + '-label', 'SMOKE Browser tag renamed');
  await page.click('#tag-chip-' + tagId + '-save-btn');
  await page.waitForFunction((id) => (document.querySelector('#tag-chip-' + id + '-toggle')?.innerText || '').toLowerCase().includes('renamed'), tagId, { timeout: 10000 }).catch(() => {});
  ok((await page.locator('#tag-chip-' + tagId + '-toggle').innerText()).toLowerCase().includes('renamed'), 'renaming a tag inline shows the new label on the chip');
  // a field
  await go(page, '/settings/fields/new?applies_to=ticket');
  await page.fill('#field-form-field-label', 'SMOKE Browser field');
  await page.selectOption('#field-form-field-type', 'select');
  await page.fill('#field-form-field-options', 'One\nTwo');
  await page.click('#field-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#fields-table-ticket').innerText()).includes('SMOKE Browser field') && (await page.locator('#fields-table-ticket').innerText()).includes('2 options'), 'the field lands in the tickets group with its option count');
  await page.screenshot({ path: `${SHOTS}/phone-fields.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the admin');
  for (const [path, label] of [['/settings/statuses/transitions', 'Transitions'], ['/settings/vocabulary/?tab=categories', 'Categories'], ['/settings/fields/', 'Custom fields']]) { await go(page, path); await widthOK(page, label, 1280); }
  await go(page, '/settings/statuses/transitions');
  await page.screenshot({ path: `${SHOTS}/desktop-transitions.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(1, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/settings/vocabulary/?tab=types');
  await page.fill('#type-form-field-label', 'SMOKE No JS type');
  await Promise.all([page.waitForURL(/notice=type_saved/, { timeout: 15000 }).catch(() => {}), page.click('#type-form-save-btn', { force: true })]);
  await page.waitForLoadState('load');
  const labels = await page.locator('#types-table input[name=label]').evaluateAll((els) => els.map((e) => e.value));   // a type's label is its inline form's value
  ok(page.url().includes('notice=type_saved') && labels.includes('SMOKE No JS type'), 'a plain form POST lands with the notice and the new type in the table');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
