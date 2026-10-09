// picker-browser.mjs — the headless-browser pass for the record picker in Help Desk (design-system references/record-picker.md), after
// tests/picker/proof.php has built its world (run through tests/picker/run.sh with BROWSER=1, or by hand against the scratch app on :8392).
// Playwright is the kernel's: `mkdir /tmp/helpdesk-picker && cd /tmp/helpdesk-picker && ln -s /var/www/web/node_modules node_modules`, copy this file
// there, then `S=$PWD BASE=http://127.0.0.1:8392 ROOT=/srv/apps/helpdesk-picker ENVF=/tmp/helpdesk-picker.env node picker-browser.mjs`.
// Signs in as Marco (27, support) through a hand-off URL signed like the kernel's (bin/dev_handoff.php). One ok/FAIL line per check; six screenshots.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
const S = process.env.S || '.', B = process.env.BASE || 'http://127.0.0.1:8392';
const ROOT = process.env.ROOT || process.cwd().replace(/\/tests\/picker$/, ''), ENVF = process.env.ENVF || '/tmp/helpdesk-picker.env';
const handoff = (m) => execSync(`set -a; . ${ENVF}; set +a; cd ${ROOT} && php bin/dev_handoff.php ${m}`).toString().trim();
let failed = 0; const ok = (n, c) => { if (!c) failed++; console.log((c ? '  ok   ' : '  FAIL ') + n); };
const browser = await chromium.launch();
const settled = (p) => p.waitForFunction(() => document.activeElement && document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {});
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage();
const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
await page.goto(handoff(27)); await page.waitForLoadState('networkidle');

// ---- the person picker on the new-request form
await page.goto(B + '/tickets/new'); await page.waitForSelector('#ticket-form-field-on-behalf-of-open');
await page.screenshot({ path: S + '/1-form-desktop.png' });
await page.click('#ticket-form-field-on-behalf-of-open');
await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker-list .record-picker-row');
ok('the modal opens with the first page (25 people)', (await page.$$('#record-picker .record-picker-row')).length === 25);
await page.waitForFunction(() => document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {});
ok('the search box has focus', await page.evaluate(() => document.activeElement.id === 'record-picker-search'));
ok('the title is the source\'s and no New button is offered', (await page.textContent('#record-picker-title')).trim() === 'People' && await page.evaluate(() => document.getElementById('record-picker-footer').classList.contains('d-none')));
await page.type('#record-picker-search', 'person 1'); await page.waitForTimeout(700);
const n = (await page.$$('#record-picker .record-picker-row')).length; ok('typing narrows the list (' + n + ' rows)', n === 10);
await page.screenshot({ path: S + '/2-picker-desktop.png' });
await page.keyboard.press('ArrowDown'); ok('ArrowDown moves to the first row', await page.evaluate(() => document.activeElement.classList.contains('record-picker-row')));
await page.keyboard.press('ArrowDown'); const label = await page.evaluate(() => document.activeElement.dataset.label);
await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
ok('Enter picks the focused row (' + label + ')', (await page.textContent('#ticket-form-field-on-behalf-of-open')).trim() === label && (await page.inputValue('#ticket-form-field-on-behalf-of')) !== '');
await page.waitForFunction(() => document.activeElement.id === 'ticket-form-field-on-behalf-of-open', null, { timeout: 3000 }).catch(() => {});
ok('focus returns to the opener', await page.evaluate(() => document.activeElement.id === 'ticket-form-field-on-behalf-of-open'));
ok('Clear appears once something is chosen', await page.isVisible('#ticket-form-field-on-behalf-of-clear'));
// Enter in the search box picks the first match
await page.click('#ticket-form-field-on-behalf-of-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row');
await page.type('#record-picker-search', 'person 07'); await page.waitForTimeout(600); await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
ok('Enter in the search box picks the first match', (await page.textContent('#ticket-form-field-on-behalf-of-open')).trim() === 'Picker Person 07');
// Show more, and the chosen row is marked
await page.click('#ticket-form-field-on-behalf-of-open'); await page.waitForSelector('#record-picker .record-picker-more');
await page.click('#record-picker-more'); await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length > 25);
ok('Show more appends page 2 in place', (await page.$$('#record-picker .record-picker-row')).length > 25 && !(await page.$('#record-picker-more')));
ok('the chosen row is marked active', await page.evaluate(() => document.querySelector('#record-picker .record-picker-row.active')?.dataset.label === 'Picker Person 07'));
await page.keyboard.press('Escape'); await page.waitForSelector('#record-picker', { state: 'hidden' });
await page.click('#ticket-form-field-on-behalf-of-clear');
ok('Clear empties the value and shows the placeholder', (await page.inputValue('#ticket-form-field-on-behalf-of')) === '' && (await page.textContent('#ticket-form-field-on-behalf-of-open')).includes('Choose a person'));

// ---- the dependent assignee picker on the ticket page: the queue select narrows it
await page.goto(B + '/tickets/1'); await page.waitForSelector('#ticket-assign-form-field-assignee-open');
await page.click('#ticket-assign-form-field-assignee-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row'); await settled(page);
const inQueue = await page.$$eval('#record-picker .record-picker-row', r => r.map(x => x.dataset.label));
ok('the assignee picker lists the ticket\'s queue only (' + inQueue.join(', ') + ')', inQueue.length === 3 && ['SMOKE Colin', 'SMOKE Lena', 'SMOKE Marco'].every((x) => inQueue.includes(x)));
await page.keyboard.press('Escape'); await page.waitForSelector('#record-picker', { state: 'hidden' });
const general = await page.$eval('#ticket-assign-form-field-queue', s => [...s.options].find(o => !o.selected)?.value);
await page.selectOption('#ticket-assign-form-field-queue', general);
await page.click('#ticket-assign-form-field-assignee-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row, #record-picker-empty'); await settled(page);
const other = await page.$$eval('#record-picker .record-picker-row', r => r.map(x => x.dataset.label));
ok('changing the queue field changes the list (' + other.length + ' rows now)', other.join() !== inQueue.join());
await page.keyboard.press('Escape'); await page.waitForSelector('#record-picker', { state: 'hidden' });
await page.selectOption('#ticket-assign-form-field-queue', await page.$eval('#ticket-assign-form-field-queue', s => [...s.options].find(o => o.defaultSelected)?.value));
await page.click('#ticket-assign-form-field-assignee-open'); await page.waitForSelector('#record-picker .record-picker-row'); await settled(page);
await page.click('#record-picker .record-picker-row[data-label="SMOKE Lena"]'); await page.waitForSelector('#record-picker', { state: 'hidden' });
await page.click('#ticket-assign-form-save-btn'); await page.waitForTimeout(1200);
await page.reload(); await page.waitForSelector('#ticket-assign-form-field-assignee-open');
ok('Save through the picker assigns the ticket (the page shows Lena)', (await page.textContent('#ticket-assign-form-field-assignee-open')).trim() === 'SMOKE Lena');

// ---- phone, light and dark
const phone = await browser.newContext({ viewport: { width: 375, height: 740 }, storageState: await ctx.storageState() });
const p2 = await phone.newPage(); p2.on('pageerror', e => errors.push(e.message));
await p2.goto(B + '/tickets/new'); await p2.waitForSelector('#ticket-form-field-on-behalf-of-open');
ok('no horizontal scroll at 375px', await p2.evaluate(() => document.documentElement.scrollWidth <= 375));
await p2.screenshot({ path: S + '/3-form-phone.png' });
await p2.click('#ticket-form-field-on-behalf-of-open'); await p2.waitForSelector('#record-picker.show'); await p2.waitForSelector('#record-picker .record-picker-row');
await p2.type('#record-picker-search', 'pick'); await p2.waitForTimeout(600);
const box = await p2.evaluate(() => { const r = document.querySelector('#record-picker .modal-content').getBoundingClientRect(); return [r.width, r.height]; });
ok('the modal fills the phone screen (' + box.join('x') + ')', box[0] >= 374 && box[1] >= 700);
const rowH = await p2.evaluate(() => document.querySelector('#record-picker .record-picker-row').getBoundingClientRect().height);
ok('rows are touch targets (' + rowH + 'px)', rowH >= 44);
await p2.screenshot({ path: S + '/4-picker-phone.png' });
await p2.evaluate(() => document.documentElement.classList.add('app-skin-dark')); await p2.waitForTimeout(200);
await p2.screenshot({ path: S + '/5-picker-phone-dark.png' });
await page.goto(B + '/tickets/new'); await page.evaluate(() => document.documentElement.classList.add('app-skin-dark'));
await page.click('#ticket-form-field-on-behalf-of-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row'); await page.waitForTimeout(300);
await page.screenshot({ path: S + '/6-picker-desktop-dark.png' });
ok('no page errors', errors.length === 0);
if (errors.length) console.log('errors: ' + errors.join(' | '));
await browser.close();
process.exit(failed ? 1 : 0);
