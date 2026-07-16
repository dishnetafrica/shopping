const http=require('http'),fs=require('fs');
const {chromium}=require('/opt/node-tools/node_modules/playwright-core');
let html=fs.readFileSync('resources/storefront/shop.html','utf8');
// Catalogue = source of truth. Brown Chana id 274 -> $2.74 ; Toor Dal id 275 -> $3.50
const products=[
  {'Product Name':'Brown Chana 1kg','Price_UGX':2.74,'MOQ':1,'PackSize':1,'Popularity':90,'_row':274,'Image':'','Brand':'FS','Category':'DAL & PULSES','Keywords':'chana pulses'},
  {'Product Name':'Toor Dal 1kg','Price_UGX':3.50,'MOQ':1,'PackSize':1,'Popularity':80,'_row':275,'Image':'','Brand':'FS','Category':'DAL & PULSES','Keywords':'dal pulses'}
];
const settings={currency:'USD'};
// Product-detail endpoint returns WRONG prices (the bug): Brown Chana $2.00, Toor Dal $1.00
const detail={
  274:{product:{id:274,name:'Brown Chana 1kg',price:2.00,category:'DAL & PULSES',unit:'pc',description:'Whole brown chickpeas.'}, similar:[{id:275,name:'Toor Dal 1kg',price:1.00,image:''}]},
  275:{product:{id:275,name:'Toor Dal 1kg',price:1.00,category:'DAL & PULSES',unit:'pc'}, similar:[{id:274,name:'Brown Chana 1kg',price:2.00,image:''}]}
};
const server=http.createServer((req,res)=>{
  if(req.url.indexOf('/catalogue')>=0){res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({products,settings}));return;}
  var m=req.url.match(/\/product\/(\d+)/);
  if(m){ res.writeHead(200,{'Content-Type':'application/json'}); res.end(JSON.stringify(detail[m[1]]||{product:{id:m[1],name:'?',price:0}})); return; }
  res.writeHead(200,{'Content-Type':'text/html'});res.end(html.replace('__SHOP_CONFIG__',JSON.stringify({slug:'t',shopName:'FS',catalogueUrl:'/catalogue',currency:'USD',base:''})));
});
server.listen(0,async()=>{
  const port=server.address().port;
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const pg=await b.newPage(); await pg.setViewportSize({width:420,height:800});
  const errs=[]; pg.on('pageerror',e=>errs.push(e.message));
  await pg.goto('http://localhost:'+port+'/'); await pg.waitForTimeout(1200);
  let pass=0,fail=0; const check=(n,c,x)=>{(c?pass++:fail++);console.log((c?'PASS ':'FAIL ')+n+(x!=null?(' :: '+x):''));};

  // 1) listing card price
  await pg.evaluate(()=>setCat("DAL & PULSES")); await pg.waitForTimeout(300);
  const cardPrices=await pg.$$eval('.pc .pr',els=>els.map(e=>e.textContent));
  check('listing shows $2.74', cardPrices.some(t=>/2\.74/.test(t)), JSON.stringify(cardPrices));

  // 2) open detail -> must show 2.74, never 2.00
  await pg.evaluate(()=>goDetail(274));
  await pg.waitForTimeout(500);
  const dprice=await pg.$eval('.dprice',e=>e.textContent).catch(()=>'');
  const dbp=await pg.$eval('.dbp',e=>e.textContent).catch(()=>'');
  const detailText=await pg.$eval('#detail',e=>e.textContent).catch(()=>'');
  check('detail price shows 2.74', /2\.74/.test(dprice)&&/2\.74/.test(dbp), 'dprice="'+dprice.trim()+'" dbp="'+dbp.trim()+'"');
  check('detail never shows the wrong 2.00', !/\$2\.00\b/.test(detailText));

  // 3) reconciled reco rail (endpoint said 1.00, catalogue says 3.50)
  const recoPrices=await pg.$$eval('.rcard .rpr',els=>els.map(e=>e.textContent));
  check('related-product rail uses catalogue price (3.50 not 1.00)', recoPrices.some(t=>/3\.50/.test(t))&&!recoPrices.some(t=>/1\.00/.test(t)), JSON.stringify(recoPrices));

  // 4) add to cart FROM DETAIL -> cart stores 2.74 (not the endpoint's 2.00)
  await pg.evaluate(()=>{ var b=document.querySelector('.dbuybar .dadd')||document.querySelector('.dadd'); if(b)b.click(); });
  await pg.waitForTimeout(300);
  const cartFromDetail=await pg.evaluate(()=>CART['Brown Chana 1kg']?CART['Brown Chana 1kg'].price:null);
  check('add-to-cart from DETAIL stores $2.74', cartFromDetail===2.74, 'stored='+cartFromDetail);

  // 5) add SAME product from listing -> identical price (no cart poisoning)
  await pg.evaluate(()=>{ CART={}; saveCart(); });
  await pg.evaluate(()=>{ location.hash=''; });
  await pg.waitForTimeout(200);
  await pg.evaluate(()=>inc('Brown Chana 1kg'));
  const cartFromListing=await pg.evaluate(()=>CART['Brown Chana 1kg']?CART['Brown Chana 1kg'].price:null);
  check('add-to-cart from LISTING stores same $2.74', cartFromListing===2.74, 'stored='+cartFromListing);

  // 6) priceOf service returns truth by id and by name
  const svc=await pg.evaluate(()=>[priceOf(274),priceOf('Brown Chana 1kg'),priceOf({id:274,price:2.00})]);
  check('priceOf() = 2.74 by id, by name, and overriding an object', svc.every(v=>v===2.74), JSON.stringify(svc));

  check('no page errors', errs.length===0, JSON.stringify(errs));
  console.log('\nRESULT: '+pass+' passed, '+fail+' failed');
  await b.close(); server.close(); process.exit(fail?1:0);
});
