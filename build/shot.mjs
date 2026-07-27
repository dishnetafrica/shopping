import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
await p.goto('file://' + process.cwd() + '/dist/index.html', { waitUntil: 'networkidle' });
await p.waitForTimeout(600);
await p.screenshot({ path: 'dist/preview-full.png', fullPage: true });
await p.screenshot({ path: 'dist/preview-top.png', fullPage: false });
await b.close();
console.log('shots done');
