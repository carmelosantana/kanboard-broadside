// Demo-2 shots: the imported claude.ai brief, v1 (as imported) vs v3 (restyled onto tokens). Usage: node demo2.mjs <outdir>
// PLAYWRIGHT_ROOT: directory whose node_modules holds playwright (default: ../../.., the folder above this repo).
import { createRequire } from 'node:module';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = resolve(process.env.PLAYWRIGHT_ROOT ?? fileURLToPath(new URL('../../..', import.meta.url)));
const require = createRequire(join(root, 'package.json'));
const { chromium } = require('playwright');
const out = process.argv[2], base = 'http://127.0.0.1:8097';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
await page.goto(`${base}/login`);
await page.fill('input[name=username]', 'admin'); await page.fill('input[name=password]', 'admin');
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
for (const v of [1, 3]) for (const theme of ['dark', 'light']) {
  await page.goto(`${base}/broadside/raw/3/${v}?theme=${theme}&preset=newburgh`);
  const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  console.log(`v${v} ${theme}: body bg ${bg}`);
  await page.screenshot({ path: `${out}/brief-v${v}-${theme}.png`, fullPage: false });
}
await page.goto(`${base}/broadside/artifact/3`);
const frame = await (await page.waitForSelector('iframe')).contentFrame();
await frame.waitForSelector('h1');
console.log('viewer h1:', await frame.textContent('h1'));
console.log('iframe height:', await page.evaluate(() => document.querySelector('iframe').offsetHeight));
console.log('versions listed:', await page.evaluate(() => document.body.innerText.match(/Version \d/g)));
console.log('sidebar icon:', await page.evaluate(() => { const a=[...document.querySelectorAll('.sidebar a')].find(a=>/Artifacts/.test(a.textContent)); return a ? !!a.querySelector('i') : 'no link'; }));
await page.screenshot({ path: `${out}/brief-viewer.png`, fullPage: false });
await browser.close();
