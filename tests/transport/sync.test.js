const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
const html=fs.readFileSync('/home/claude/out/transport-register.html','utf8');

// Mock sync server: merges by uid/updatedAt into a shared store, honours tombstones.
let STORE={shipments:[],loads:[],tombstones:{},companies:[]};
function mergeList(cur,inc){ var by={}; cur.forEach(e=>by[e.uid]=e); (inc||[]).forEach(r=>{ if(!r||!r.uid)return; var l=by[r.uid]; if(!l||(r.updatedAt||0)>(l.updatedAt||0))by[r.uid]=r; }); return Object.keys(by).map(k=>by[k]); }
const server=http.createServer((req,res)=>{
  if(req.url.indexOf('/sync')>=0 && req.method==='POST'){
    let body=''; req.on('data',c=>body+=c); req.on('end',()=>{
      let inb={}; try{inb=JSON.parse(body);}catch(e){}
      STORE.shipments=mergeList(STORE.shipments,inb.shipments);
      STORE.loads=mergeList(STORE.loads,inb.loads);
      var tomb=inb.tombstones||{}; for(var k in tomb){ if(!STORE.tombstones[k]||tomb[k]>STORE.tombstones[k])STORE.tombstones[k]=tomb[k]; }
      ['shipments','loads'].forEach(t=>{ STORE[t]=STORE[t].filter(e=>{ var td=STORE.tombstones[e.uid]; return !(td&&td>=(e.updatedAt||0)); }); });
      (inb.companies||[]).forEach(c=>{ if(STORE.companies.indexOf(c)<0)STORE.companies.push(c); });
      res.writeHead(200,{'Content-Type':'application/json'});
      res.end(JSON.stringify({ok:true,shipments:STORE.shipments,loads:STORE.loads,tombstones:STORE.tombstones,companies:STORE.companies}));
    });
    return;
  }
  res.writeHead(200,{'Content-Type':'text/html'});res.end(html);
});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const cfg={syncUrl:'http://localhost:'+port+'/sync',csrf:'x'};
  async function client(){ const c=await b.newContext(); const pg=await c.newPage(); await pg.setViewportSize({width:390,height:800});
    pg.on('dialog',d=>d.accept());
    await pg.addInitScript(c=>{ window.TR_CONFIG=c; }, cfg);
    await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(300); return pg; }
  let pass=0,fail=0; const ck=(n,cc,x)=>{(cc?pass++:fail++);console.log((cc?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  const A=await client(); const B=await client();
  ck('sync enabled on client (button visible)', await A.evaluate(()=>document.getElementById('syncBtn').style.display!=='none'));

  // A receives a shipment
  await A.evaluate(()=>{ RC_ITEMS=[{name:'Toilet Paper',qty:40},{name:'Brown Chana',qty:25}]; go('receive'); saveReceive(); });
  await A.waitForTimeout(1200); // debounce + sync
  ck('server has A shipment', STORE.shipments.length===1, 'ships='+STORE.shipments.length);

  // B pulls (force sync) -> sees it
  await B.evaluate(()=>syncNow()); await B.waitForTimeout(600);
  ck('B received A shipment via sync', await B.evaluate(()=>REG.shipments.length)===1);
  ck('B waiting total = 65', await B.evaluate(()=>waitingTotal())===65);

  // B dispatches on a bus
  await B.evaluate(()=>{ go('dispatch'); }); await B.waitForTimeout(200);
  await B.evaluate(()=>{ document.getElementById('dpCo').value='Gateway Bus'; document.getElementById('dpBus').value='UBK 1'; doDispatch(); });
  await B.waitForTimeout(1200);
  ck('server has B load', STORE.loads.length===1);

  // A pulls -> sees the load; waiting reduces
  await A.evaluate(()=>syncNow()); await A.waitForTimeout(600);
  ck('A sees B dispatch (loads=1)', await A.evaluate(()=>REG.loads.length)===1);
  ck('A waiting now = 0 (all dispatched)', await A.evaluate(()=>waitingTotal())===0, 'wait='+await A.evaluate(()=>waitingTotal()));

  // A advances status; B sees it (last-write-wins)
  await A.evaluate(()=>{ setLoadStatus(REG.loads[0].id,'arrived'); }); await A.waitForTimeout(1200);
  await B.evaluate(()=>syncNow()); await B.waitForTimeout(600);
  ck('B sees status=arrived after A update', await B.evaluate(()=>REG.loads[0].status)==='arrived', await B.evaluate(()=>REG.loads[0].status));

  // Offline queue: A goes offline, receives, then online -> flushes
  await A.evaluate(()=>{ Object.defineProperty(navigator,'onLine',{get:()=>false,configurable:true}); });
  await A.evaluate(()=>{ RC_ITEMS=[{name:'Rice',qty:10}]; go('receive'); saveReceive(); }); await A.waitForTimeout(1000);
  const beforeFlush=STORE.shipments.length;
  ck('offline: server NOT yet updated', beforeFlush===1, 'ships='+beforeFlush);
  await A.evaluate(()=>{ Object.defineProperty(navigator,'onLine',{get:()=>true,configurable:true}); syncNow(); }); await A.waitForTimeout(800);
  ck('online: queued shipment flushed to server', STORE.shipments.length===2, 'ships='+STORE.shipments.length);

  // Delete propagation: A deletes the Rice shipment -> B loses it
  await A.evaluate(()=>{ var r=REG.shipments.filter(s=>s.products.some(p=>p.name==='Rice'))[0]; tombstone(r.uid); REG.shipments=REG.shipments.filter(s=>s.uid!==r.uid); markDirty(); }); await A.waitForTimeout(1200);
  await B.evaluate(()=>syncNow()); await B.waitForTimeout(600);
  ck('B: deleted shipment removed via tombstone', !(await B.evaluate(()=>REG.shipments.some(s=>s.products.some(p=>p.name==='Rice')))));

  ck('final convergence: A.ships==B.ships', await A.evaluate(()=>REG.shipments.length)===await B.evaluate(()=>REG.shipments.length));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
