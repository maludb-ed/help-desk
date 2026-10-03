// Headless Chromium proof of slice 8 (docs/build-specs/automation-worker.md, "Proof", 8): the rules list, the form (the condition rows stack at 375 px, nothing sideways) and the runs page at 375 and 1280.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8291';
const SHOTS = process.env.SHOTS || '/tmp/helpdesk-shots-p3s8';
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
  console.log(name + ' — the owner');
  await page.goto(mint(1), { waitUntil: 'networkidle' });
  await page.goto(BASE + '/settings/automation/', { waitUntil: 'networkidle' });
  ok(await page.locator('[id^="rule-card-"][id$="-name"]').count() >= 2, 'the rules list shows the seeded rules');
  await widthOK(page, 'the rules list', vp.width);
  await page.goto(BASE + '/settings/automation/new?kind=trigger', { waitUntil: 'networkidle' });
  ok(await page.locator('.rule-cond-row').count() >= 3 && await page.locator('.rule-action-row').count() >= 3, 'the form has condition and action rows');
  await widthOK(page, 'the rule form', vp.width);
  if (vp.width < 600) {
    const a = await page.locator('#rule-cond-0-field').boundingBox(), b = await page.locator('#rule-cond-0-op').boundingBox();
    ok(b.y > a.y + a.height - 1, 'the condition row stacks on a phone');
  }
  await page.screenshot({ path: `${SHOTS}/${vp.width}-rule-form.png`, fullPage: false });
  await page.goto(BASE + '/settings/automation/1/runs', { waitUntil: 'networkidle' });
  ok(await page.locator('#rule-runs-table').count() === 1, 'the runs page renders');
  await widthOK(page, 'the runs page', vp.width);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
