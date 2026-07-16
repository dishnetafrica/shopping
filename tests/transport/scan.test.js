const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
const html=fs.readFileSync('/home/claude/out/transport-register.html','utf8');
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const cat=[
    {name:'Aluminium Foil 30m',cat:'Foil',unit:'Carton',barcode:'8901234567890',sku:'ALF-30'},
    {name:'Brown Chana 1kg',cat:'Dal',unit:'Pc',barcode:'8907777777777',sku:'CHN-1'},
    {name:'Toilet Paper',cat:'Tissue',unit:'Carton',barcode:'',sku:'TP-2'}
  ];
  const pg=await b.newPage(); await pg.setViewportSize({width:390,height:820});
  pg.on('dialog',d=>d.accept()); const errs=[]; pg.on('pageerror',e=>errs.push(e.message));
  await pg.addInitScript(c=>{ window.TR_PRODUCTS=c; }, cat);
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(300);
  await pg.evaluate(()=>{ REG={shipments:[],loads:[],seq:{},companies:[],tombstones:{}}; save(); reset('home'); });
  let pass=0,fail=0; const ck=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  ck('catalogue carries barcodes + sku', await pg.evaluate(()=>TRP[0].barcode)==='8901234567890' && await pg.evaluate(()=>TRP[0].sku)==='ALF-30');

  // match by BARCODE (also navigates to receive from home)
  await pg.evaluate(()=>{ reset('home'); resolveScan('8901234567890'); });
  await pg.waitForTimeout(200);
  ck('scan barcode → navigates to receive + adds Aluminium Foil', await pg.evaluate(()=>cur())==='receive' && await pg.evaluate(()=>RC_ITEMS.some(x=>x.name==='Aluminium Foil 30m')), await pg.evaluate(()=>JSON.stringify(RC_ITEMS)));
  // second different barcode
  await pg.evaluate(()=>resolveScan('8907777777777')); await pg.waitForTimeout(120);
  ck('second barcode → adds Brown Chana', await pg.evaluate(()=>RC_ITEMS.some(x=>x.name==='Brown Chana 1kg')));
  // match by SKU
  await pg.evaluate(()=>resolveScan('TP-2')); await pg.waitForTimeout(120);
  ck('match by SKU → adds Toilet Paper', await pg.evaluate(()=>RC_ITEMS.some(x=>x.name==='Toilet Paper')));
  // unknown → no add
  const before=await pg.evaluate(()=>RC_ITEMS.filter(x=>x.name).length);
  await pg.evaluate(()=>resolveScan('0000000000000')); await pg.waitForTimeout(120);
  ck('unknown code → no add', await pg.evaluate(()=>RC_ITEMS.filter(x=>x.name).length)===before);

  // save the scanned shipment
  await pg.evaluate(()=>{ RC_ITEMS.forEach((it,i)=>{ if(it.name) it.qty=(i+1)*5; }); saveReceive(); });
  await pg.waitForTimeout(150);
  ck('scanned shipment saved with 3 products', await pg.evaluate(()=>REG.shipments.length)===1 && await pg.evaluate(()=>REG.shipments[0].products.length)===3, await pg.evaluate(()=>JSON.stringify(REG.shipments[0]?REG.shipments[0].products:[])));

  // fallback: unsupported browser → prompt path resolves
  await pg.evaluate(()=>{ try{delete window.BarcodeDetector;}catch(e){window.BarcodeDetector=undefined;} window.prompt=function(){return '8907777777777';}; EDIT_ID=null; RC_ITEMS=[]; RC_BAGS=[]; go('receive'); openScanner(); });
  await pg.waitForTimeout(200);
  ck('unsupported → prompt fallback adds Brown Chana', await pg.evaluate(()=>RC_ITEMS.some(x=>x.name==='Brown Chana 1kg')));
  ck('scan button shows on receive (catalogue present)', (await pg.$$('text=📷 Scan box')).length>=1);

  ck('no page errors', errs.length===0, JSON.stringify(errs.slice(0,2)));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
