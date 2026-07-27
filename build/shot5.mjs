import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await b.newPage({ viewport: { width: 1280, height: 820 }, deviceScaleFactor: 1.5 });
await p.goto('file://' + process.cwd() + '/dist-demo/speedparts/index.html', { waitUntil: 'networkidle' });
await p.waitForTimeout(400);
await p.screenshot({ path: 'dist-demo/speedparts-top.png', fullPage: false });
await b.close(); console.log('done');
