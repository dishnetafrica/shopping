const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
let html=fs.readFileSync('resources/storefront/shop.html','utf8');
const P=(n,price)=>({'Product Name':n,'Price_UGX':price,'MOQ':1,'PackSize':1,'Popularity':50,'_row':Math.floor(Math.random()*9000)+2,'Image':'','Brand':'FS','Category':'DAL & PULSES','Keywords':'dal'});
const products=[P('Urad White Dal 1kg',3.25),P('Big Bag Rice 25kg',30),P('Cooking Oil 20L',28)];
const branches=[{name:'Kampala',lat:0.3476,lng:32.5825},{name:'Juba',lat:4.85,lng:31.58}];
// Juba free over $50; Kampala free over $80 (configured)
const settings={currency:'USD', delivery:{ free_rules:[{city:'Juba',free_over:50},{city:'Kampala',free_over:80}] }};
const server=http.createServer((req,res)=>{
  if(req.url.indexOf('/catalogue')>=0){res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({products,settings,branches}));return;}
  res.writeHead(200,{'Content-Type':'text/html'});res.end(html.replace('__SHOP_CONFIG__',JSON.stringify({slug:'t',shopName:'FS',catalogueUrl:'/catalogue',currency:'USD',base:'',branches:branches})));
});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const pg=await b.newPage(); await pg.setViewportSize({width:420,height:820});
  const errs=[]; pg.on('pageerror',e=>errs.push(e.message));
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(1200);
  let pass=0,fail=0; const ck=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  // add items to exceed 50: 3.25*? use big items. Add 2x Rice(30)+ = 60
  await pg.evaluate(()=>{ inc('Big Bag Rice 25kg'); inc('Big Bag Rice 25kg'); }); // 60
  // No location set -> nudge shows the Juba offer
  await pg.evaluate(()=>openCart()); await pg.waitForTimeout(200);
  let barTxt=await pg.$eval('.freebar',e=>e.textContent).catch(()=>'');
  ck('no-location: shows Juba free-delivery nudge', /free delivery to Juba/i.test(barTxt)&&/50/.test(barTxt), barTxt.trim());
  let toPay=await pg.$eval('.billrow.tot',e=>e.textContent).catch(()=>'');
  ck('no-location: still shows + delivery', /delivery/i.test(toPay), toPay.trim());
  await pg.evaluate(()=>closeSheet());

  // Set city = Juba ; total 60 >= 50 -> FREE
  await pg.evaluate(()=>pickCity('Juba'));
  await pg.evaluate(()=>openCart()); await pg.waitForTimeout(200);
  barTxt=await pg.$eval('.freebar',e=>e.textContent).catch(()=>'');
  ck('Juba >=50: unlocked message', /unlocked|FREE delivery to Juba/i.test(barTxt), barTxt.trim());
  const delRow=await pg.$eval('.billbox .billrow:nth-child(2)',e=>e.textContent).catch(()=>'');
  const freeTag=await pg.$$eval('.freetag',els=>els.length);
  ck('Juba >=50: Delivery shows FREE tag', freeTag>0);
  toPay=await pg.$eval('.billrow.tot',e=>e.textContent).catch(()=>'');
  ck('Juba >=50: To pay has NO + delivery', !/\+ delivery/i.test(toPay), toPay.trim());
  ck('deliveryNote() = FREE for Juba>=50', /FREE/.test(await pg.evaluate(()=>deliveryNote())), await pg.evaluate(()=>deliveryNote()));
  await pg.evaluate(()=>closeSheet());

  // Juba but under 50: remove to ~ 3.25 only
  await pg.evaluate(()=>{ CART={}; saveCart(); inc('Urad White Dal 1kg'); }); // 3.25
  await pg.evaluate(()=>openCart()); await pg.waitForTimeout(200);
  barTxt=await pg.$eval('.freebar',e=>e.textContent).catch(()=>'');
  ck('Juba <50: progress "Add $X more ... to Juba"', /Add .* more for .*free delivery.* to Juba/i.test(barTxt.replace(/\s+/g,' ')), barTxt.trim());
  toPay=await pg.$eval('.billrow.tot',e=>e.textContent).catch(()=>'');
  ck('Juba <50: shows + delivery', /\+ delivery/i.test(toPay), toPay.trim());
  await pg.evaluate(()=>closeSheet());

  // Kampala under its 80 threshold: 60 total
  await pg.evaluate(()=>{ CART={}; saveCart(); inc('Big Bag Rice 25kg'); inc('Big Bag Rice 25kg'); pickCity('Kampala'); }); // 60 < 80
  await pg.evaluate(()=>openCart()); await pg.waitForTimeout(200);
  barTxt=await pg.$eval('.freebar',e=>e.textContent).catch(()=>'');
  ck('Kampala <80: progress to Kampala', /to Kampala/i.test(barTxt), barTxt.trim());
  ck('deliveryNote() mentions Kampala charged', /Kampala/.test(await pg.evaluate(()=>deliveryNote())), await pg.evaluate(()=>deliveryNote()));

  ck('no page errors', errs.length===0, JSON.stringify(errs));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
