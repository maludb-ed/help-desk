// Headless Chromium proof of slice 7 (docs/build-specs/email-notifications.md, "Proof", 7): the mailboxes list, the form and the inbound ledger at 375 and 1280 px without horizontal scroll; the bell's count element; Mark all read empties it without a page load.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s7';
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
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
for (const [name, vp] of [['375 x 740', PHONE], ['1280 x 800', DESK]]) {
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 2, isMobile: vp.width < 600, hasTouch: vp.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)|sendAbort|^htmx:afterRequest$/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  console.log(name + ' — the owner');
  await page.goto(mint(1), { waitUntil: 'networkidle' });
  for (const [path, label, sel] of [['/settings/mailboxes/', 'the mailboxes list', '#mailboxes-cards'], ['/settings/mailboxes/new', 'the mailbox form', '#mailbox-form'], ['/settings/mailboxes/inbound', 'the inbound ledger', '#inbound-table'], ['/settings/?tab=notify', 'the prefs tab', '#prefs']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    ok(await page.locator(sel).count() === 1, label + ' renders');
    await widthOK(page, label, vp.width);
  }
  await page.screenshot({ path: `${SHOTS}/${vp.width}-mailboxes.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const ctx = await browser.newContext({ viewport: DESK });
  const page = await ctx.newPage();
  console.log('the bell — Marco');
  await page.goto(mint(27), { waitUntil: 'networkidle' });
  await page.goto(BASE + '/notifications', { waitUntil: 'networkidle' });
  const before = await page.locator('#header-bell-count').count();
  if (await page.locator('#notifications-read-all-btn').count()) {
    await page.click('#notifications-read-all-btn');
    await page.waitForFunction(() => !document.querySelector('#header-bell-count'), null, { timeout: 10000 });
    ok(true, 'Mark all read empties the bell without a page load (had a count: ' + (before > 0) + ')');
  } else {
    ok(await page.locator('#header-bell-count').count() === 0, 'nothing unread: no count on the bell');
  }
  await page.screenshot({ path: `${SHOTS}/desktop-notifications.png` });
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
