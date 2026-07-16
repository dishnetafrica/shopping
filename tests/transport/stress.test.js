const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
const html=fs.readFileSync('/home/claude/out/transport-register.html','utf8');
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const pg=await b.newPage(); await pg.setViewportSize({width:390,height:820});
  pg.on('dialog',d=>d.accept()); const errs=[]; pg.on('pageerror',e=>errs.push('PAGEERR '+e.message));
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(300);
  let pass=0,fail=0; const ck=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  // ---- generate high volume in-page ----
  const res = await pg.evaluate(() => {
    REG={shipments:[],loads:[],seq:{},companies:[],tombstones:{}};
    const PRODS=['Toilet Paper','Brown Chana','Disposable Glasses','Basmati Rice','Cooking Oil','Wheat Flour','Chilli Powder','Salt','Sugar','Tea'];
    const COS=['Gateway Bus','Nile Coach','Juba Express','Kampala Coaches','Star Bus'];
    let now=1700000000000; // fixed base (Date.now not used for determinism of ids handled by seq)
    const t0=performance.now();
    // 400 shipments, each with 2-4 loose products + 0-2 bags
    for(let s=0;s<400;s++){
      const date='2026-07-16';
      const id=seqId('TRN',date);
      const np=2+(s%3);
      const products=[]; for(let i=0;i<np;i++){ products.push({name:PRODS[(s+i)%PRODS.length]+' '+((i%3)+1)+'kg', cartons:5+((s*i)%20)}); }
      const bags=[]; const nb=s%3; // 0,1,2 bags
      for(let bi=0;bi<nb;bi++){ bags.push({uid:uid(),label:'',count:1+((s+bi)%4),contents:[{name:PRODS[(s+bi)%PRODS.length],qty:2+(bi%3)},{name:PRODS[(s+bi+1)%PRODS.length],qty:1+(bi%2)}]}); }
      REG.shipments.push(stamp({id:id,date:date,ts:now+ s*1000,source:'Kampala',dest:'Juba',ref:'R'+s,products:products,bags:bags}));
    }
    const genT=performance.now()-t0;
    const intakeAll = totalIntake();

    // dispatch ~70% of everything across many loads (loose + bags), advancing statuses
    const t1=performance.now();
    let loadsMade=0;
    for(let s=0;s<400;s++){
      const dm=dispatchedMap();
      const sh=REG.shipments[s];
      const lines=[]; (sh.products||[]).forEach(p=>{ const take=Math.floor((p.cartons)*0.7); if(take>0) lines.push(...allocate(p.name,take,dm)); });
      const baglines=[]; const dbm=dispatchedBagMap(); (sh.bags||[]).forEach(bag=>{ const pend=bag.count-(dbm[bag.uid]||0); const take=Math.max(0,pend-0); if(take>0) baglines.push({shipmentId:sh.id,bagUid:bag.uid,label:bagLabel(bag),count:take,contents:bag.contents}); });
      if(lines.length||baglines.length){
        const id=seqId('LD',sh.date);
        const st=['park','transit','arrived','received'][s%4];
        REG.loads.push(stamp({id:id,date:sh.date,ts:now+s*1000+500,company:COS[s%COS.length],bus:'BUS-'+s,status:st,lines:lines,baglines:baglines,events:[{status:st,ts:now,note:'x'}]}));
        loadsMade++;
      }
    }
    const dispT=performance.now()-t1;
    save();

    // measure heavy computations
    const t2=performance.now();
    const wt=waitingTotal(), park=cartonsByStatus('park'), tr=cartonsByStatus('transit'), ar=cartonsByStatus('arrived'), rc=cartonsByStatus('received'), lo=cartonsByStatus('lost');
    const wp=waitingProducts().length, wb=waitingBags().length;
    const compT=performance.now()-t2;

    // reconciliation
    const recon = intakeAll === (wt+park+tr+ar+rc+lo);

    // storage size
    const bytes = JSON.stringify(REG).length;

    return {genT:+genT.toFixed(0),dispT:+dispT.toFixed(0),compT:+compT.toFixed(1),
      shipments:REG.shipments.length, loads:REG.loads.length, loadsMade,
      intakeAll, wt, park, tr, ar, rc, lo, wp, wb, recon, kb:+(bytes/1024).toFixed(0)};
  });
  console.log('generated:', res.shipments, 'shipments,', res.loads, 'loads');
  console.log('total intake cartons:', res.intakeAll, '| storage:', res.kb, 'KB');
  console.log('timings(ms): generate', res.genT, '| dispatch-all', res.dispT, '| full recompute', res.compT);
  console.log('buckets: waiting', res.wt, 'park', res.park, 'transit', res.tr, 'arrived', res.ar, 'received', res.rc, 'lost', res.lo);
  ck('reconciliation holds at scale (intake == waiting + all stages)', res.recon===true, res.intakeAll+' vs '+(res.wt+res.park+res.tr+res.ar+res.rc+res.lo));
  ck('full recompute < 60ms', res.compT<60, res.compT+'ms');

  // render heavy screens & time them
  for(const [name,fn] of [['dashboard',()=>go('dash')],['buses',()=>go('buses')],['timeline',()=>go('timeline')]]){
    const t=await pg.evaluate((n)=>{ const a=performance.now(); window.__go(); return performance.now()-a; }, name).catch(()=>null);
  }
  const rt = await pg.evaluate(()=>{ const times={}; ['dash','buses','timeline'].forEach(v=>{ STACK=['home']; const a=performance.now(); go(v); times[v]=+(performance.now()-a).toFixed(0); }); return times; });
  console.log('render(ms):', JSON.stringify(rt));
  ck('dashboard renders < 400ms at 400 shipments', rt.dash<400, rt.dash+'ms');
  ck('buses list renders < 500ms', rt.buses<500, rt.buses+'ms');

  // search at scale
  const stime = await pg.evaluate(()=>{ go('search'); const a=performance.now(); document.getElementById('sq').value='BUS-123'; doSearch(); return +(performance.now()-a).toFixed(0); });
  console.log('search(ms):', stime);
  ck('search < 200ms', stime<200, stime+'ms');

  // reload persistence (localStorage survives)
  await pg.reload(); await pg.waitForTimeout(400);
  const after = await pg.evaluate(()=>({s:REG.shipments.length,l:REG.loads.length,recon: totalIntake()===(waitingTotal()+cartonsByStatus('park')+cartonsByStatus('transit')+cartonsByStatus('arrived')+cartonsByStatus('received')+cartonsByStatus('lost'))}));
  ck('data persisted across reload', after.s===res.shipments && after.l===res.loads, JSON.stringify(after));
  ck('reconciliation still holds after reload', after.recon===true);

  ck('no page errors under load', errs.length===0, JSON.stringify(errs.slice(0,3)));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
