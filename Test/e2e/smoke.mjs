// Demo-1 smoke: real Chromium against kb-broadside (:8097). Usage: node smoke.mjs <outdir>
// PLAYWRIGHT_ROOT: directory whose node_modules holds playwright (default: ../../.., the folder above this repo).
import { createRequire } from 'node:module';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = resolve(process.env.PLAYWRIGHT_ROOT ?? fileURLToPath(new URL('../../..', import.meta.url)));
const require = createRequire(join(root, 'package.json'));
const { chromium } = require('playwright');
const out = process.argv[2];
const base = 'http://127.0.0.1:8097';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const log = (k, v) => console.log(`${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`);
await page.goto(`${base}/login`);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', 'admin');
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
await page.goto(`${base}/broadside/artifact/1`);
const frame = await (await page.waitForSelector('iframe')).contentFrame();
await frame.waitForSelector('h1');
log('frame h1', await frame.textContent('h1'));
log('frame html classes', await frame.evaluate(() => document.documentElement.className));
log('frame bg', await frame.evaluate(() => getComputedStyle(document.body).backgroundColor));
log('frame cookie access', await frame.evaluate(() => { try { return 'READ:' + document.cookie; } catch (e) { return 'blocked: ' + e.name; } }));
log('frame fetch', await frame.evaluate(async () => { try { await fetch('/dashboard'); return 'ALLOWED'; } catch (e) { return 'blocked: ' + e.name; } }));
await page.waitForTimeout(500);
log('tally before', await frame.textContent('#tally'));
await frame.click('button[data-choice=import]');
await frame.waitForFunction(() => /Import path: 1/.test(document.querySelector('#tally').textContent), null, { timeout: 5000 }).catch(() => {});
log('tally after vote', await frame.textContent('#tally'));
await frame.click('button[data-choice=comments]');
await page.waitForTimeout(800);
log('second vote (once)', await frame.textContent('#tally'));
await page.screenshot({ path: `${out}/viewer-light.png`, fullPage: true });
await page.fill('textarea[name=body], textarea', 'Looks good. Can the poll show percentages?');
await Promise.all([page.waitForNavigation(), page.click('button:has-text("Post comment")')]);
log('comment posted', (await page.textContent('body')).includes('Can the poll show percentages?'));
for (const theme of ['dark', 'light']) {
  await page.goto(`${base}/broadside/raw/1/1?theme=${theme}&preset=newburgh`);
  await page.screenshot({ path: `${out}/page-${theme}.png`, fullPage: true });
}
await page.goto(`${base}/broadside/artifact/2`);
const f2 = await (await page.waitForSelector('iframe')).contentFrame();
await f2.waitForSelector('h1');
log('markdown h1', await f2.textContent('h1'));
log('markdown table rows', await f2.evaluate(() => document.querySelectorAll('table tr').length));
await page.screenshot({ path: `${out}/markdown.png`, fullPage: true });
await browser.close();
