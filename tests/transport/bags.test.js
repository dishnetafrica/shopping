const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
const html=fs.readFileSync('/home/claude/out/transport-register.html','utf8');
const server=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/html'});res.end(html);});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const pg=await b.newPage(); await pg.setViewportSize({width:390,height:820});
  pg.on('dialog',d=>d.accept()); const errs=[]; pg.on('pageerror',e=>errs.push(e.message));
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(300);
  await pg.evaluate(()=>{ REG={shipments:[],loads:[],seq:{},companies:[],tombstones:{}}; save(); reset('home'); });
  let pass=0,fail=0; const ck=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  // Receive: 20 loose Rice + 3 identical bags each {TP 5, Chana 3} = 3*(8)=24 bag cartons; total 44
  await pg.evaluate(()=>{ go('receive');
    RC_ITEMS=[{name:'Rice',qty:20}];
    RC_BAGS=[{label:'',count:3,contents:[{name:'Toilet Paper',qty:5},{name:'Brown Chana',qty:3}]}];
    saveReceive();
  });
  await pg.waitForTimeout(200);
  ck('shipTotal = 44 (20 loose + 24 bag)', await pg.evaluate(()=>shipTotal(REG.shipments[0]))===44, 'total='+await pg.evaluate(()=>shipTotal(REG.shipments[0])));
  ck('waitingTotal = 44', await pg.evaluate(()=>waitingTotal())===44);
  ck('waitingBags has 3 pending', await pg.evaluate(()=>{var w=waitingBags();return w.length===1&&w[0].pending===3;}));

  // Dispatch: 10 loose Rice + 2 bags on a bus
  await pg.evaluate(()=>go('dispatch')); await pg.waitForTimeout(200);
  await pg.fill('#dpCo','Gateway Bus'); await pg.fill('#dpBus','UBK 9');
  // loose Rice stepper dp_0 -> set to 10; bag stepper dpbag_0 -> set to 2
  await pg.evaluate(()=>{ document.getElementById('dp_0').value='10'; document.getElementById('dpbag_0').value='2'; });
  await pg.evaluate(()=>doDispatch()); await pg.waitForTimeout(200);
  const ld=await pg.evaluate(()=>REG.loads[0]);
  ck('load has loose line 10 Rice', ld.lines.length===1 && ld.lines[0].cartons===10);
  ck('load has bagline count 2', ld.baglines.length===1 && ld.baglines[0].count===2);
  ck('loadTotal = 10 + 2*8 = 26', await pg.evaluate(()=>loadTotal(REG.loads[0]))===26, 'lt='+await pg.evaluate(()=>loadTotal(REG.loads[0])));
  ck('waiting now = 44-26 = 18', await pg.evaluate(()=>waitingTotal())===18);
  ck('bag pending now 1', await pg.evaluate(()=>waitingBags()[0].pending)===1);

  // Reconciliation invariant
  const recon=await pg.evaluate(()=> totalIntake()===(waitingTotal()+cartonsByStatus('park')+cartonsByStatus('transit')+cartonsByStatus('arrived')+cartonsByStatus('received')+cartonsByStatus('lost')));
  ck('carton reconciliation holds with bags', recon===true);

  // Manifest text includes the bag
  const mt=await pg.evaluate(()=>manifestText(REG.loads[0]));
  ck('manifest text lists bag + total 26', /× 2/.test(mt)&&/Total: 26/.test(mt), mt.replace(/\n/g,' | '));

  // over-dispatch guard: try to load 5 bags when only 1 left -> capped
  await pg.evaluate(()=>go('dispatch')); await pg.waitForTimeout(150);
  await pg.fill('#dpCo','Nile'); await pg.fill('#dpBus','X1');
  await pg.evaluate(()=>{ var s=document.getElementById('dpbag_0'); if(s){s.value='5';} });
  await pg.evaluate(()=>doDispatch()); await pg.waitForTimeout(150);
  ck('over-dispatch capped to remaining 1 bag', await pg.evaluate(()=>{var l=REG.loads[REG.loads.length-1];return l.baglines[0].count===1;}));
  ck('all bags dispatched now (0 waiting bags)', await pg.evaluate(()=>waitingBags().length)===0);

  ck('no page errors', errs.length===0, JSON.stringify(errs));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
