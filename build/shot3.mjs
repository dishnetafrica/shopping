import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
for (const [f,label] of [['boxer100-diagram','diagram'],['boxer100-part','part'],['boxer100-parts','parts']]){
  await p.goto('file://' + process.cwd() + '/dist/'+f+'.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(400);
  await p.screenshot({ path: 'dist/'+label+'-top.png', fullPage: false });
  await p.screenshot({ path: 'dist/'+label+'-full.png', fullPage: true });
}
await b.close(); console.log('done');
