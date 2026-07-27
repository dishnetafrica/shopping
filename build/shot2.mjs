import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
await p.goto('file://' + process.cwd() + '/dist/boxer100.html', { waitUntil: 'networkidle' });
await p.waitForTimeout(500);
await p.screenshot({ path: 'dist/overview-full.png', fullPage: true });
await p.screenshot({ path: 'dist/overview-top.png', fullPage: false });
await b.close(); console.log('ov shots done');
