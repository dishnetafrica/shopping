const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
const html=fs.readFileSync('/home/claude/out/transport-register.html','utf8');
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const pg=await b.newPage(); await pg.setViewportSize({width:390,height:820});
  const errs=[]; pg.on('pageerror',e=>errs.push(e.message)); pg.on('dialog',d=>d.accept());
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(400);
  let pass=0,fail=0; const ck=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  // clean slate
  await pg.evaluate(()=>{ REG={shipments:[],loads:[],seq:{},companies:[]}; save(); reset('home'); });
  await pg.waitForTimeout(150);

  // RECEIVE a shipment: TP 40, Chana 25, Glasses 55 = 120
  await pg.evaluate(()=>{ go('receive'); RC_ITEMS=[{name:'Toilet Paper',qty:40},{name:'Brown Chana',qty:25},{name:'Disposable Glasses',qty:55}]; });
  await pg.waitForTimeout(100);
  await pg.evaluate(()=>saveReceive()); await pg.waitForTimeout(150);
  ck('shipment created with TRN id', await pg.evaluate(()=>/^TRN-\d{8}-\d{3}$/.test(REG.shipments[0].id)), await pg.evaluate(()=>REG.shipments[0].id));
  ck('waiting total = 120', await pg.evaluate(()=>waitingTotal())===120);
  ck('home hero shows 120', /120/.test(await pg.$eval('.hero .big',e=>e.textContent)));

  // Partial dispatch: TP 40 (all), Chana 10 (of 25), Glasses 55 (all) = 105 on a bus
  await pg.evaluate(()=>go('dispatch')); await pg.waitForTimeout(150);
  await pg.fill('#dpCo','Gateway Bus'); await pg.fill('#dpBus','UBK 456Y');
  // waitingProducts order: Toilet Paper(0), Brown Chana(1), Disposable Glasses(2)
  await pg.evaluate(()=>{ document.getElementById('dp_1').value='10'; }); // reduce Chana to 10
  await pg.evaluate(()=>doDispatch()); await pg.waitForTimeout(200);
  ck('load created (on load screen)', /Bus manifest/.test(await pg.$eval('#app',e=>e.textContent)));
  ck('dispatched total = 105', await pg.evaluate(()=>totalDispatched())===105, 'disp='+await pg.evaluate(()=>totalDispatched()));
  ck('waiting now = 15 (Chana remainder)', await pg.evaluate(()=>waitingTotal())===15);

  // Product-level pending: Brown Chana should show 15 waiting on shipment detail
  const chanaPending=await pg.evaluate(()=>{ var sh=REG.shipments[0]; return shipProductPending(sh,'Brown Chana'); });
  ck('Brown Chana pending = 15', chanaPending===15, 'pending='+chanaPending);
  const tpPending=await pg.evaluate(()=>{ var sh=REG.shipments[0]; return shipProductPending(sh,'Toilet Paper'); });
  ck('Toilet Paper pending = 0 (fully sent)', tpPending===0);

  // Load status lifecycle: park default? we chose transit default
  const ld0=await pg.evaluate(()=>REG.loads[0].status);
  ck('load default status = transit', ld0==='transit', ld0);
  ck('holder while transit = bus company', await pg.evaluate(()=>holderOf(REG.loads[0]))==='Gateway Bus');
  await pg.evaluate(()=>{ setLoadStatus(REG.loads[0].id,'arrived'); });
  ck('after arrived, holder = Juba staff', await pg.evaluate(()=>holderOf(REG.loads[0]))==='Juba staff');
  await pg.evaluate(()=>{ setLoadStatus(REG.loads[0].id,'received'); });
  ck('after received, in warehouse bucket = 105', await pg.evaluate(()=>cartonsByStatus('received'))===105);

  // Dashboard buckets
  await pg.evaluate(()=>go('dash')); await pg.waitForTimeout(150);
  const tiles=await pg.$$eval('.tile',els=>els.map(e=>({k:e.querySelector('.k').textContent,v:e.querySelector('.v').textContent})));
  const g=k=>((tiles.find(t=>t.k.toLowerCase().includes(k))||{}).v);
  ck('dash total received=120', g('total received')==='120', JSON.stringify(tiles.map(t=>t.k+':'+t.v)));
  ck('dash waiting=15', g('waiting to dispatch')==='15');
  ck('dash in warehouse=105', g('juba warehouse')==='105');

  // Reconciliation: intake == waiting + park + transit + arrived + received + lost
  const recon=await pg.evaluate(()=>{ return totalIntake()===(waitingTotal()+cartonsByStatus('park')+cartonsByStatus('transit')+cartonsByStatus('arrived')+cartonsByStatus('received')+cartonsByStatus('lost')); });
  ck('carton reconciliation holds', recon===true);

  // Manifest WhatsApp text
  const mt=await pg.evaluate(()=>manifestText(REG.loads[0]));
  ck('manifest text lists products + total', /Toilet Paper/.test(mt)&&/Total: 105/.test(mt));

  // Search by bus number
  await pg.evaluate(()=>go('search')); await pg.waitForTimeout(120);
  await pg.fill('#sq','UBK 456Y'); await pg.evaluate(()=>doSearch()); await pg.waitForTimeout(120);
  ck('search finds the bus', (await pg.$$('#sres .row')).length>=1);
  // Search by product
  await pg.fill('#sq','chana'); await pg.evaluate(()=>doSearch()); await pg.waitForTimeout(120);
  ck('search finds by product name', (await pg.$$('#sres .row')).length>=1);

  ck('no page errors', errs.length===0, JSON.stringify(errs));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
