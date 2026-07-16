const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
const html=fs.readFileSync('/home/claude/out/transport-register.html','utf8');
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const cat=[{name:'Toilet Paper',cat:'Tissue',unit:'Carton'},{name:'Brown Chana 1kg',cat:'Dal & Pulses',unit:'Pc'},{name:'Disposable Glasses 300ml',cat:'Disposables',unit:'Pc'},{name:'Basmati Rice 5kg',cat:'Rice',unit:'Bag'}];
  const pg=await b.newPage(); await pg.setViewportSize({width:390,height:820});
  pg.on('dialog',d=>d.accept()); const errs=[]; pg.on('pageerror',e=>errs.push(e.message));
  await pg.addInitScript(c=>{ window.TR_PRODUCTS=c; }, cat);
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(300);
  await pg.evaluate(()=>{ REG={shipments:[],loads:[],seq:{},companies:[],tombstones:{}}; save(); reset('home'); });
  let pass=0,fail=0; const ck=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  ck('catalogue loaded (TRP=4)', await pg.evaluate(()=>TRP.length)===4);

  // Open RECEIVE
  await pg.evaluate(()=>go('receive')); await pg.waitForTimeout(200);
  // product field is a pick button (no free text)
  ck('product field is tap-to-pick', (await pg.$$('.pick')).length>=1);
  // quick-add chips present (seeded from catalogue since no history)
  ck('quick-add chips shown', (await pg.$$('#rcQuick .chip')).length>=1);

  // Quick-add a product by tapping a chip
  await pg.evaluate(()=>rcQuickAdd('Toilet Paper')); await pg.waitForTimeout(150);
  ck('quick-add filled first row', await pg.evaluate(()=>RC_ITEMS[0].name)==='Toilet Paper', await pg.evaluate(()=>JSON.stringify(RC_ITEMS)));

  // Open picker on a new row, search, and pick
  await pg.evaluate(()=>{ rcAdd(); }); await pg.waitForTimeout(150); // opens picker automatically
  ck('picker sheet opened on add', await pg.evaluate(()=>document.getElementById('sheet').classList.contains('show')));
  await pg.fill('#ppq','chana'); await pg.evaluate(()=>renderProdPicker()); await pg.waitForTimeout(120);
  const rows=await pg.$$eval('#ppList .row .l1',els=>els.map(e=>e.textContent));
  ck('search "chana" filters to Brown Chana', rows.some(r=>/Brown Chana/.test(r)) && !rows.some(r=>/Rice/.test(r)), JSON.stringify(rows));
  await pg.evaluate(()=>pickProd('Brown Chana 1kg')); await pg.waitForTimeout(150);
  ck('picked product set on row 2', await pg.evaluate(()=>RC_ITEMS[1].name)==='Brown Chana 1kg');

  // custom fallback: search something not in catalogue -> "Use ..." option
  await pg.evaluate(()=>{ rcAdd(); }); await pg.waitForTimeout(120);
  await pg.fill('#ppq','Sugar Special'); await pg.evaluate(()=>renderProdPicker()); await pg.waitForTimeout(120);
  const first=await pg.$eval('#ppList .row .l1',e=>e.textContent).catch(()=>'');
  ck('custom "Use ..." offered for non-catalogue', /Use/.test(first), first);
  await pg.evaluate(()=>pickProd('Sugar Special')); await pg.waitForTimeout(120);

  // fill cartons and save
  await pg.evaluate(()=>{ RC_ITEMS[0].qty=40; RC_ITEMS[1].qty=25; RC_ITEMS[2].qty=8; });
  await pg.evaluate(()=>saveReceive()); await pg.waitForTimeout(200);
  ck('receive saved with picked products (73 cartons)', await pg.evaluate(()=>waitingTotal())===73, 'wait='+await pg.evaluate(()=>waitingTotal()));
  const names=await pg.evaluate(()=>REG.shipments[0].products.map(p=>p.name));
  ck('shipment holds picked names', JSON.stringify(names)===JSON.stringify(['Toilet Paper','Brown Chana 1kg','Sugar Special']), JSON.stringify(names));

  ck('no page errors', errs.length===0, JSON.stringify(errs));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
