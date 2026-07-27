import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
// 360 explorer
await p.goto('file://' + process.cwd() + '/dist/boxer100-360.html', { waitUntil: 'networkidle' });
await p.waitForTimeout(500);
await p.screenshot({ path: 'dist/ex-top.png', fullPage: false });
// simulate a rotate drag over the viewer
const v = await p.$('#exViewer'); const bx = await v.boundingBox();
await p.mouse.move(bx.x+bx.width/2, bx.y+bx.height/2); await p.mouse.down();
await p.mouse.move(bx.x+bx.width/2+120, bx.y+bx.height/2, {steps:6}); await p.mouse.up();
await p.waitForTimeout(300);
await p.screenshot({ path: 'dist/ex-rotated.png', clip:{x:0,y:150,width:1280,height:640} });
// AI page (first problem active by default)
await p.goto('file://' + process.cwd() + '/dist/boxer100-ai.html', { waitUntil: 'networkidle' });
await p.waitForTimeout(400);
await p.screenshot({ path: 'dist/ai-top.png', fullPage: false });
await p.screenshot({ path: 'dist/ai-full.png', fullPage: true });
await b.close(); console.log('done');
