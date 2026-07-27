// EA Auto Part storefront — module: 10-views.js
// all route views (v*)
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- views ---------- */
function vHome(){
  var models=activeModels().slice(0,6).map(function(m){
    return '<div class="card mc" onclick="go(\'model/'+m.id+'\')"><div class="im"><img src="'+(IMG[m.id]||'')+'" alt="" loading="lazy"></div><div class="bd"><b>'+esc(m.name)+'</b><p>'+esc(m.tagline)+'</p><span class="go">Explore parts ›</span></div></div>';
  }).join('');
  var sys=systemsWithCounts().map(function(s){
    var bg=s.cover?'<img src="'+esc(s.img||s.cover)+'" alt="" loading="lazy">':'<div class="ph">'+esc(s.name)+'</div>';
    return '<div class="sc" onclick="go(\'system/'+encodeURIComponent(s.name)+'\')">'+ (s.cover?'<img src="'+esc(s.cover)+'" loading="lazy">':'')+'<div class="ph" style="'+(s.cover?'display:none':'')+'"></div><div class="m"><b>'+esc(s.name)+'</b><span>'+s.count+' parts</span></div></div>';
  }).join('');
  var popSrc=vehFilter(STATE.products);
  var pop=popSrc.slice().sort(function(a,b){return (b.price)-(a.price);}).slice(0,5).map(prodCard).join('')
        || popSrc.slice(0,5).map(prodCard).join('');
  return nav()
   +'<section class="hero"><div class="g wrap"><div><h1>'+esc(CFG.heroTitle||'Find. Understand. Buy.')+'</h1><p>'+esc(CFG.heroText||'')+'</p>'
     +'<form class="hsearch" onsubmit="return doSearch(event)"><input id="q" placeholder="Search part, number, or describe the problem…"><button class="btn btn-p" type="submit">Search</button></form>'
     +'<a class="herobroke" onclick="go(\'ai\')"><i class="fa fa-wrench"></i> Bike won\'t start or making a noise? <b>Describe the problem →</b></a>'
     +'<div class="pop"><span>Popular:</span><a onclick="qsearch(\'Clutch\')">Clutch Plate</a><a onclick="qsearch(\'Filter\')">Air Filter</a><a onclick="qsearch(\'Spark\')">Spark Plug</a><a onclick="qsearch(\'Brake\')">Brake Shoe</a></div>'
   +'</div><div style="text-align:center"><img class="hph" src="'+(IMG.hero||'')+'" alt="" loading="lazy"></div></div></section>'
   +trust()
   +'<section class="blk wrap"><div class="hd"><div><h2>Which motorcycle do you own?</h2><div class="sub">Select your model to explore parts.</div></div></div><div class="g5">'+models+'</div></section>'
   +'<section class="blk wrap"><div class="hd"><div><h2>Browse by system</h2><div class="sub">Jump straight to the parts for each system.</div></div><span class="link" onclick="go(\'parts\')">View all parts ›</span></div><div class="g5">'+sys+'</div></section>'
   + (STATE.products.length? '<section class="blk wrap"><div class="hd"><div><h2>Popular parts</h2></div></div><div class="g5">'+pop+'</div></section>':'')
   + (STATE.error?'<div class="wrap"><div class="notice">Live catalogue is loading or unavailable right now — parts appear once the shop feed responds.</div></div>':'')
   +foot();
}
function fitBadge(p){
  var f=fitsBike(p); if(f===null) return '';
  var b=getBike();
  return f?'<span class="fitb ok">✓ Fits your '+esc(b.name)+'</span>':'<span class="fitb no">Not for your '+esc(b.name)+'</span>';
}
function prodCard(p){
  var pn=partNo(p.kw); var inS=p.stock===null||p.stock>0; var priced=p.price>0;
  var sys=String(p.cat||'').split('>').slice(-1)[0].trim()||'';
  return '<div class="card pc" onclick="go(\'part/'+sid(p.id)+'\')"><div class="im">'+(p.img?'<img src="'+esc(p.img)+'" alt="'+esc(p.name)+'" loading="lazy">':'<div class="imph"><i class="fa fa-cog"></i></div>')+'</div>'
    +'<div class="bd"><h4>'+esc(p.name)+'</h4>'+fitBadge(p)
    +'<div class="cat">'+esc(sys)+(pn?' · <span class="mono">'+esc(pn)+'</span>':'')+'</div>'
    +'<div class="ft"><b>'+(priced?money(p.price):'<span class="askp">Ask price</span>')+'</b><span class="stock '+(inS?'y':'n')+'">'+(inS?'In stock':'To order')+'</span></div>'
    +'<button class="btn btn-p btn-sm add" onclick="event.stopPropagation();cartAdd(\''+sid(p.id)+'\')"><i class="fa fa-plus"></i> Add to cart</button></div></div>';
}
function vParts(){
  var sys=systemsWithCounts();
  var cards=sys.map(function(s){
    return '<div class="card mc" onclick="go(\'system/'+encodeURIComponent(s.name)+'\')"><div class="im" style="background:var(--ink)">'+(s.cover?'<img src="'+esc(s.cover)+'" loading="lazy">':'')+'</div><div class="bd"><b>'+esc(s.name)+'</b><p>'+s.count+' parts</p><span class="go">View parts ›</span></div></div>';
  }).join('');
  return nav()+'<div class="wrap crumb"><a onclick="go(\'\')">Home</a> › Parts</div>'
    +'<section class="blk wrap"><div class="hd"><div><h2>Parts — browse by category</h2><div class="sub">'+STATE.products.length+' genuine parts across '+sys.length+' systems.</div></div><span class="link" onclick="go(\'diagrams\')">View exploded diagrams ›</span></div><div class="g4">'+cards+'</div></section>'+foot();
}
function vSystem(cat){
  var list=partsInSystem(cat);
  return nav()+'<div class="wrap crumb"><a onclick="go(\'\')">Home</a> › <a onclick="go(\'parts\')">Parts</a> › '+esc(cat)+'</div>'
    +'<section class="blk wrap"><div class="hd"><div><h2>'+esc(cat)+'</h2><div class="sub">'+list.length+' parts</div></div></div>'
    +(list.length?'<div class="g5">'+list.map(prodCard).join('')+'</div>':'<div class="empty">No parts in this system yet.</div>')+'</section>'+foot();
}
function vPart(id){
  var p=STATE.products.filter(function(x){return String(x.id)===String(id);})[0];
  if(!p) return nav()+'<div class="empty">Part not found.</div>'+foot();
  var pn=partNo(p.kw); var inS=p.stock===null||p.stock>0; var priced=p.price>0;
  var b=getBike(); var f=fitsBike(p);
  var fitLine = b ? (f?'<div class="fitbig ok">✓ Fits your '+esc(b.name)+' — guaranteed, or free return.</div>'
                        :'<div class="fitbig no">This part is listed for a different model, not your '+esc(b.name)+'. <a onclick="go(\'ai\')">Check what fits ›</a></div>')
                  : '<div class="fitbig ask" onclick="go(\'\')"><i class="fa fa-plus"></i> Add your motorcycle to confirm this fits</div>';
  var also=partsInSystem(String(p.cat||'').split('>').slice(-1)[0].trim()).filter(function(x){return x.id!==p.id;}).slice(0,4).map(prodCard).join('');
  if(!also) also=STATE.products.filter(function(x){return x.id!==p.id;}).slice(0,4).map(prodCard).join('');
  var pl=STATE.pnPlate[normPn(pn)];
  var diagBtn=pl?('<a class="btn btn-o" onclick="go(\'plate/'+sid(pl)+'\')"><i class="fa fa-wrench"></i> Show on diagram</a>'):'';
  var fg=fitGuide(p);
  track('view',{product_id:p.id});
  return nav()+'<div class="wrap crumb"><a onclick="go(\'\')">Home</a> › <a onclick="go(\'parts\')">Parts</a> › '+esc(p.name)+'</div>'
   +'<section class="page wrap"><div class="pd"><div class="big">'+(p.img?'<img src="'+esc(p.img)+'" alt="'+esc(p.name)+'" loading="lazy">':'<div class="imph"><i class="fa fa-cog"></i></div>')+'</div>'
   +'<div><span class="chip g">✓ Genuine Bajaj</span> <h1 style="margin-top:8px">'+esc(p.name)+'</h1>'+(pn?'<div class="mono" style="margin-top:4px">Part No: '+esc(pn)+'</div>':'')
   +'<div class="price">'+(priced?money(p.price):'<span class="askp">Ask for price</span>')+'</div><div class="stock '+(inS?'y':'n')+'" style="margin-bottom:12px">'+(inS?('✓ In stock'+(p.stock!=null&&p.stock>0?(' ('+p.stock+')'):'')):'Available to order')+'</div>'
   +fitLine
   +(p.desc?'<p style="color:var(--muted);margin:14px 0 16px">'+esc(p.desc)+'</p>':'<div style="height:6px"></div>')
   +'<div class="buyrow"><button class="btn btn-p" onclick="cartAdd(\''+sid(p.id)+'\')"><i class="fa fa-shopping-cart"></i> Add to cart</button><a class="btn btn-w" href="'+esc(waOrder(p))+'" target="_blank" rel="noopener" onclick="track(\'checkout\',{product_ids:[\''+sid(p.id)+'\']})">Order now</a>'+diagBtn+'</div>'
   +'<div class="qa-grid">'
     +'<div class="qa-b"><h4><i class="fa fa-check-circle"></i> Genuine guarantee</h4><p>'+esc(CFG.genuineLine||'100% genuine — or your money back.')+'</p></div>'
     +'<div class="qa-b"><h4><i class="fa fa-truck"></i> Delivery &amp; payment</h4><p>'+esc(CFG.deliveryEta||'Fast delivery')+'<br><b>'+esc(CFG.payMethods||'Mobile Money · Pay on Delivery')+'</b></p></div>'
     +'<div class="qa-b"><h4><i class="fa fa-wrench"></i> Fitting</h4><p>Difficulty: <b>'+esc(fg.level)+'</b><br>Tools: '+esc(fg.tools)+'<br><a href="'+esc(waLink())+'" target="_blank" rel="noopener">Ask for a fitting guide ›</a></p></div>'
     +'<div class="qa-b"><h4><i class="fa fa-undo"></i> Warranty &amp; returns</h4><p>'+esc(CFG.warranty||'1-year warranty')+'<br>'+esc(CFG.returns||'Returns on unfitted parts')+'</p></div>'
   +'</div>'
   +'</div></div>'
   +(also?'<div class="blk"><div class="hd"><div><h2>Commonly replaced together</h2></div></div><div class="g4">'+also+'</div></div>':'')
   +'</section>'
   +'<div class="buybar"><div class="bb-l"><b>'+(priced?money(p.price):'Ask price')+'</b><span>'+esc(p.name)+'</span></div><button class="btn btn-p" onclick="cartAdd(\''+sid(p.id)+'\')"><i class="fa fa-shopping-cart"></i> Add to cart</button></div>'
   +foot();
}
function galleryFor(id){ var g=(GAL&&GAL[id])||[]; if(!g.length&&IMG[id])g=[IMG[id]]; return g; }
function findParts(words,n){
  var out=[],seen={};
  words.forEach(function(w){ w=w.toLowerCase();
    STATE.products.forEach(function(p){ if(out.length<n&&!seen[p.id]&&(p.name+' '+p.kw).toLowerCase().indexOf(w)>=0){seen[p.id]=1;out.push(p);} });
  });
  return out;
}
function ovMain(el){ var m=document.getElementById('ovMain'); if(m&&el.dataset.src)m.src=el.dataset.src; document.querySelectorAll('.ovthumb').forEach(function(t){t.classList.remove('on');}); el.classList.add('on'); }
function zoom(src){ if(!src)return; var z=document.getElementById('zoomer'); if(!z){z=document.createElement('div');z.id='zoomer';z.onclick=function(){z.classList.remove('on');};document.body.appendChild(z);} z.innerHTML='<span class="zx">✕</span><img src="'+src+'" loading="lazy">'; z.classList.add('on'); }
function vModel(id){
  var m=(MODELS||[]).filter(function(x){return x.id===id;})[0]; if(!m) return go('');
  setBike(id);
  var g=galleryFor(id);
  var thumbs=g.slice(0,4).map(function(src,i){return '<div class="ovthumb'+(i===0?' on':'')+'" data-src="'+src+'" onclick="ovMain(this)"><img src="'+src+'" loading="lazy"></div>';}).join('')
    + (g.length>4?'<div class="ovthumb more">+'+(g.length-4)+'<span>View more</span></div>':'');
  var ks=(m.keySpecs||[]).slice(0,4).map(function(k){return '<div class="ks1"><b>'+esc(k.v)+'</b><span>'+esc(k.l)+'</span></div>';}).join('');
  var tabs=[['Overview','ov-top'],['Systems & Parts','ov-sys'],['Diagrams','diagrams'],['Specifications','ov-spec']];
  var tabH=tabs.map(function(t,i){
    var oc = t[1]==='diagrams'?'go(\'diagrams\')':'scrollTo2(\''+t[1]+'\')';
    return '<span class="tab'+(i===0?' on':'')+'" onclick="'+oc+'">'+esc(t[0])+'</span>';
  }).join('');
  var sys=systemsWithCounts().map(function(s){return '<div class="sc" onclick="go(\'system/'+encodeURIComponent(s.name)+'\')">'+(s.cover?'<img src="'+esc(s.cover)+'" loading="lazy">':'')+'<div class="m"><b>'+esc(s.name)+'</b></div></div>';}).join('');
  var common=findParts(['clutch','chain','brake shoe'],3).map(function(p){return '<a class="cmp" onclick="go(\'part/'+sid(p.id)+'\')">'+(p.img?'<img src="'+esc(p.img)+'" loading="lazy">':'')+'<span>'+esc(p.name)+'</span></a>';}).join('');
  var mineParts=STATE.products.filter(function(p){return nk(String(p.kw||'').split('|')[0])===nk(m.catName||m.name);});
  var popSrc=(mineParts.length?mineParts:STATE.products).slice();
  var pop=popSrc.sort(function(a,b){return (b.price||0)-(a.price||0);}).slice(0,6).map(prodCard).join('');
  var specRows=Object.keys(m.specs||{}).map(function(k){return '<div class="r"><span>'+esc(k)+'</span><b>'+esc(m.specs[k])+'</b></div>';}).join('');
  var main=g[0]||'';
  return nav()
   +'<span id="ov-top"></span><section class="ovhero"><div class="wrap ovh-g">'
     +'<div class="ovh-info"><div class="crumb" style="padding:0 0 10px"><a onclick="go(\'\')">‹ Back to all motorcycles</a></div>'
       +'<h1 style="font-size:36px">'+esc(m.name)+' <span class="chip" style="background:#E8F0FF;color:#0E3A94;vertical-align:middle">Popular</span></h1>'
       +'<p style="color:var(--muted);font-size:17px;margin-top:4px">'+esc(m.tagline)+'</p>'
       +'<div class="ovks">'+ks+'</div>'
       +'<p style="color:var(--muted);margin:6px 0 18px;max-width:52ch">'+esc(m.summary||'')+'</p>'
       +'<div class="mybike"><i class="fa fa-tachometer"></i> Saved as <b>your motorcycle</b> — parts are now checked to fit '+esc(m.name)+'.</div>'
       +'<a class="btn btn-p" onclick="go(\'parts\')">Explore Parts & Diagrams ›</a> <a class="btn btn-o" onclick="go(\'ai\')"><i class="fa fa-comments"></i> Describe a problem</a>'
     +'</div>'
     +'<div class="ovh-media"><div class="ovh-main">'+(main?'<img id="ovMain" class="zoomable" title="Click to zoom" onclick="zoom(this.src)" src="'+main+'" loading="lazy"><span class="zhint"><i class="fa fa-search-plus"></i> Zoom</span>':'')+'</div><div class="ovh-thumbs">'+thumbs+'</div></div>'
   +'</div></section>'
   +'<div class="wrap"><nav class="tabs">'+tabH+'</nav></div>'
   +'<section class="page wrap ov-body">'
     +'<aside class="ov-left" id="ov-intel"><div class="card intel"><div class="ih"><b>Genuine parts for '+esc(m.name)+'</b><span class="chip g">✓ Guaranteed fit</span></div>'
       +'<p style="color:var(--muted);font-size:14px;margin:2px 0 10px">Every part below is checked to fit your '+esc(m.name)+'. 100% genuine Bajaj or your money back.</p>'
       +'<div class="ct">Commonly replaced parts</div><div class="cmg">'+common+'</div>'
       +'<a class="link" onclick="go(\'parts\')">See all parts ›</a></div></aside>'
     +'<div class="ov-mid"><div class="hd"><div><h2 id="ov-sys">Browse by system</h2><div class="sub">Select a system to view parts, diagrams and components.</div></div><span class="link" onclick="go(\'parts\')">View all systems ›</span></div>'
       +'<div class="g4 sysg">'+sys+'</div>'
       +'<div class="hd" style="margin-top:30px"><div><h2 id="ov-spec">Specifications</h2></div></div><div class="spec">'+specRows+'</div>'
       +'<div class="hd" style="margin-top:30px"><div><h2 id="ov-man">Manuals & guides</h2><div class="sub">Owner manual and service guides — request on WhatsApp.</div></div></div>'
     +'</div>'
     +'<aside class="ov-right"><div class="card"><b>Quick Actions</b>'
       +'<a class="qa" onclick="go(\'parts\')"><span><i class="fa fa-search"></i></span><div><b>Search Parts</b><i>Find parts by number</i></div></a>'
       +'<a class="qa" onclick="go(\'ai\')"><span><i class="fa fa-comments"></i></span><div><b>Ask AI Assistant</b><i>Get help for your bike</i></div></a>'
       +'<a class="qa" href="'+esc(waLink())+'" target="_blank"><span><i class="fa fa-camera"></i></span><div><b>Upload Photo</b><i>Identify parts</i></div></a>'
       +'<a class="qa" onclick="go(\'diagrams\')"><span><i class="fa fa-sitemap"></i></span><div><b>Part Compatibility</b><i>Check fitment</i></div></a>'
       +'<a class="qa" onclick="go(\'ai\')"><span><i class="fa fa-calendar"></i></span><div><b>Service Schedule</b><i>View maintenance plan</i></div></a></div>'
       +'<div class="card need"><b>Need help?</b><p>Our experts are ready to help you find the right part.</p><a class="btn btn-w" href="'+esc(waLink())+'" target="_blank">Chat on WhatsApp</a></div></aside>'
   +'</section>'
   +'<section class="blk wrap"><div class="hd"><div><h2>Popular parts for '+esc(m.name)+'</h2></div><span class="link" onclick="go(\'parts\')">View all parts ›</span></div><div class="g5">'+pop+'</div></section>'
   +foot();
}
function scrollTo2(id){var e=document.getElementById(id); if(e)e.scrollIntoView({behavior:'smooth',block:'start'});}
function vSearch(q){
  q=decodeURIComponent(q||'').toLowerCase();
  var res=vehFilter(STATE.products).filter(function(p){return (p.name+' '+p.kw+' '+p.cat).toLowerCase().indexOf(q)>=0;});
  track('search',{q:q,results:res.length});
  return nav()+'<div class="wrap crumb"><a onclick="go(\'\')">Home</a> › Search</div>'
   +'<section class="blk wrap"><div class="hd"><div><h2>Results for "'+esc(q)+'"</h2><div class="sub">'+res.length+' parts found</div></div></div>'
   +(res.length?'<div class="g5">'+res.slice(0,60).map(prodCard).join('')+'</div>':
     '<div class="norec"><div class="nrh">No parts matched “'+esc(q)+'”.</div><p>Don’t worry — we’ll find it for you.</p>'
     +'<div class="nrb"><a class="btn btn-w" href="'+esc(waLink())+'" target="_blank" rel="noopener"><i class="fa fa-whatsapp"></i> Ask us on WhatsApp</a>'
     +'<a class="btn btn-o" onclick="go(\'ai\')"><i class="fa fa-comments"></i> Describe the problem</a>'
     +'<a class="btn btn-o" onclick="go(\'\')"><i class="fa fa-tachometer"></i> Browse by motorcycle</a></div></div>')+'</section>'+foot();
}
function plateImg(pl){ for(var i=0;i<(pl.parts||[]).length;i++){var pr=STATE.byPn[normPn(pl.parts[i].pn)]; if(pr&&pr.img)return pr.img;} return ''; }
function vDiagram(plateNo){
  var plates=(DIAG&&DIAG.plates)||[]; if(!plates.length) return nav()+'<div class="empty">Diagrams unavailable.</div>'+foot();
  plateNo=parseInt(plateNo)||plates[0].plate;
  var cur=plates.filter(function(p){return p.plate==plateNo;})[0]||plates[0];
  var groups={}; plates.forEach(function(p){(groups[p.system]=groups[p.system]||[]).push(p);});
  var navHtml=Object.keys(groups).map(function(sys){
    return '<div class="dnav-sys">'+esc(sys)+'</div>'+groups[sys].map(function(p){
      return '<div class="dnav-item'+(p.plate==cur.plate?' on':'')+'" onclick="go(\'plate/'+sid(p.plate)+'\')">'+esc(p.name)+'</div>';
    }).join('');
  }).join('');
  var rows=(cur.parts||[]).map(function(x){
    var pr=STATE.byPn[normPn(x.pn)];
    var nm=esc(x.d||(pr&&pr.name)||'Part');
    var img=pr&&pr.img;
    var inS=pr?(pr.stock===null||pr.stock>0):null;
    var open=pr?('onclick="go(\'part/'+sid(pr.id)+'\')"'):'';
    var thumb=img?'<img src="'+esc(img)+'" loading="lazy">':'<span class="pno-b">'+esc(x.no)+'</span>';
    var priceHtml=pr?('<b>'+money(pr.price)+'</b>'+(inS!=null?'<span class="stock '+(inS?'y':'n')+'">'+(inS?'In stock':'On order')+'</span>':'')):'<span class="tbd">Ask us</span>';
    var add=pr?('<button class="miniadd" onclick="event.stopPropagation();cartAdd(\''+String(pr.id).replace(/\x27/g,"")+'\')" title="Add to cart"><i class="fa fa-plus"></i> Add</button>'):'';
    return '<div class="prow" '+open+(pr?' role="button"':'')+'>'
      +'<span class="prn">'+esc(x.no)+'</span>'
      +'<span class="pthumb">'+thumb+'</span>'
      +'<span class="pinfo"><span class="pnm">'+nm+'</span><span class="mono">'+esc(x.pn)+'</span></span>'
      +'<span class="pprice">'+priceHtml+'</span>'
      +'<span class="pact">'+add+'</span></div>';
  }).join('');
  var img=plateImg(cur);
  return nav()+'<div class="wrap crumb"><a onclick="go(\'\')">Home</a> › <a onclick="go(\'parts\')">Parts</a> › Diagrams › '+esc(cur.system)+'</div>'
   +'<section class="page wrap"><div class="diag">'
   +'<aside class="dnav">'+navHtml+'</aside>'
   +'<div class="dframe">'+(img?'<img src="'+esc(img)+'" loading="lazy">':'<div class="empty">Diagram appears once parts are imported.</div>')+'<span class="dbadge">Plate '+cur.plate+'</span></div>'
   +'<div class="dlist"><div class="dtitle"><b>'+esc(cur.system)+' › '+esc(cur.name)+'</b><div class="mono">Plate '+cur.plate+' · '+(cur.parts||[]).length+' parts · tap any part to view or add it</div></div>'
   +'<div class="plist">'+rows+'</div></div>'
   +'</div></section>'+foot();
}
function aiPartLink(name){
  var b=getBike(); var words=String(name||'').toLowerCase().split(/[^a-z0-9]+/).filter(function(w){return w.length>2;});
  if(!words.length) return null;
  var any=null;
  for(var i=0;i<STATE.products.length;i++){ var p=STATE.products[i]; var hay=(p.name+' '+p.kw).toLowerCase();
    var hit=words.filter(function(w){return hay.indexOf(w)>=0;}).length;
    if(hit){ if(!any||hit>any._h){any=p;any._h=hit;} if(b&&fitsBike(p)&&hit>=Math.min(2,words.length)){ return p; } } }
  return any;
}
function vAI(){
  var b=getBike();
  var chips=(TB.problems||[]).map(function(p){return '<span class="dxchip" onclick="dx(\''+sid(p.id)+'\')">'+esc(p.label)+'</span>';}).join('');
  var bikebar=b?('<div class="aibike"><i class="fa fa-tachometer"></i> Helping with <b>'+esc(b.name)+'</b> · <a onclick="clearBike()">change</a></div>')
               :('<div class="aibike ask" onclick="go(\'\')"><i class="fa fa-tachometer"></i> Add your motorcycle for exact-fit answers ›</div>');
  return nav()+'<section class="page wrap"><div class="ai"><div class="hdr"><span class="av"><i class="fa fa-comments"></i></span><div><b>'+esc(CFG.name||'')+' — AI Mechanic</b><div style="font-size:12px;color:var(--muted)">Auto-diagnosis · a real person can confirm on WhatsApp</div></div></div>'
   +bikebar
   +'<div class="msg b">'+esc(TB.greeting||'Tell me what\'s wrong and I\'ll point you to the likely cause and the genuine part.')+'</div>'
   +'<div class="aiask"><input id="aiq" placeholder="Describe the problem… e.g. it won\'t start" autocomplete="off"><button id="aimic" onclick="micAI()" title="Speak"><i class="fa fa-microphone"></i></button><button class="btn btn-p" onclick="submitAI()">Ask</button></div>'
   +'<div style="margin:10px 0 2px"><span style="font-size:12px;color:var(--muted)">Common problems:</span> '+chips+'</div>'
   +'<div id="dxout"></div>'
   +'<div class="aihelp">Not sure? <a class="btn btn-w btn-sm" href="'+esc(waLink())+'" target="_blank" rel="noopener"><i class="fa fa-whatsapp"></i> Chat with a person on WhatsApp</a></div>'
   +'</div></section>'+foot();
}
function submitAI(){ var i=document.getElementById('aiq'); askAI((i&&i.value||'').trim()); }
function micAI(){ var SR=window.SpeechRecognition||window.webkitSpeechRecognition; if(!SR){ var i=document.getElementById('aiq'); if(i){i.focus();} return; }
  var r=new SR(); r.lang='en-US'; r.onresult=function(ev){ var t=ev.results[0][0].transcript; var i=document.getElementById('aiq'); if(i)i.value=t; askAI(t); }; try{r.start();}catch(e){} }
function askAI(text){
  if(!text) return; var t=text.toLowerCase();
  var best=null,bestScore=0;
  (TB.problems||[]).forEach(function(p){
    var hay=(p.label+' '+p.query+' '+(p.causes||[]).map(function(c){return c.name+' '+c.partName;}).join(' ')).toLowerCase();
    var toks=hay.split(/[^a-z0-9]+/).filter(function(w){return w.length>3;});
    var score=0, seen={}; toks.forEach(function(w){ if(!seen[w]&&t.indexOf(w)>=0){seen[w]=1;score++;} });
    // small synonym boost
    ['start','brake','noise','power','smoke','leak','clutch','chain','light','horn'].forEach(function(w){ if(t.indexOf(w)>=0&&hay.indexOf(w)>=0)score++; });
    if(score>bestScore){bestScore=score;best=p;}
  });
  if(best && bestScore>0){ dx(best.id, text); }
  else { go('search/'+encodeURIComponent(text)); }  // hands off to search (which has WhatsApp/AI recovery)
}
function dx(id, userText){
  var p=(TB.problems||[]).filter(function(x){return x.id===id;})[0]; if(!p)return;
  var cards=(p.causes||[]).map(function(c){
    var pr=aiPartLink(c.partName||c.name);
    var fitH=pr?fitBadge(pr):''; var priceH=pr&&pr.price>0?(' · '+money(pr.price)):'';
    var actH=pr?('<a class="btn btn-p btn-sm" onclick="go(\'part/'+sid(pr.id)+'\')">View part</a> <a class="btn btn-w btn-sm" href="'+esc(waOrder(pr))+'" target="_blank" rel="noopener">Order</a>')
               :('<a class="btn btn-o btn-sm" onclick="qsearch(\''+esc((c.partName||c.name).split(" ")[0])+'\')">Find parts</a>');
    return '<div class="dxcard"><div style="flex:1"><div style="display:flex;justify-content:space-between"><b>'+esc(c.name)+'</b><b style="color:var(--accent)">'+c.probability+'%</b></div><div class="bar"><i style="width:'+c.probability+'%"></i></div><div style="font-size:12px;color:var(--muted)">→ '+esc((pr&&pr.name)||c.partName||'')+priceH+' '+fitH+'</div><div style="margin-top:7px">'+actH+'</div></div></div>';
  }).join('');
  var steps=(p.steps||[]).map(function(s){return '<li>'+esc(s)+'</li>';}).join('');
  var out=document.getElementById('dxout'); if(!out)return;
  out.innerHTML='<div class="msg u">'+esc(userText||p.query)+'</div><div class="msg b"><p>'+esc(p.intro)+'</p>'+cards
    +(steps?'<div style="border:1px dashed var(--line);border-radius:10px;padding:10px 14px;margin-top:6px"><b style="font-size:13px">Check these yourself first</b><ol style="margin:6px 0 0 18px;font-size:13px;color:var(--muted)">'+steps+'</ol></div>':'')
    +'<div style="margin-top:10px;font-size:13px;color:var(--muted)">Not sure which one? <a href="'+esc(waLink())+'" target="_blank" rel="noopener" style="color:var(--accent);font-weight:600">Send us a photo on WhatsApp ›</a></div></div>';
  var q=document.getElementById('aiq'); if(q)q.value='';
  out.scrollIntoView({behavior:'smooth',block:'nearest'});
}

function vCart(){
  var c=cartGet();
  if(!c.length) return nav()+'<section class="page wrap"><div class="emptycart"><div class="ec"><i class="fa fa-shopping-cart"></i></div><h2>Your cart is empty</h2><p>Add the parts you need, then send the whole order on WhatsApp in one message.</p><a class="btn btn-p" onclick="go(\'\')">Find parts for my bike</a></div></section>'+foot();
  var b=getBike();
  var rows=c.map(function(i){ var id=String(i.id).replace(/'/g,'');
    return '<div class="crow"><div class="ci"><b>'+esc(i.name)+'</b>'+(i.pn?'<span class="mono">'+esc(i.pn)+'</span>':'')+'<span class="cp">'+(i.price>0?money(i.price):'Ask price')+' each</span></div>'
     +'<div class="cq"><button onclick="cartSet(\''+id+'\',-1)">−</button><span>'+i.qty+'</span><button onclick="cartSet(\''+id+'\',1)">+</button></div>'
     +'<div class="clt">'+(i.price>0?money(i.price*i.qty):'—')+'</div></div>'; }).join('');
  return nav()+'<div class="wrap crumb"><a onclick="go(\'\')">Home</a> › Cart</div>'
   +'<section class="page wrap"><h2 style="margin:0 0 4px">Your order</h2>'
   +(b?'<div class="mybike" style="max-width:520px"><i class="fa fa-tachometer"></i> For your <b>'+esc(b.name)+'</b> — parts checked to fit.</div>':'')
   +'<div class="cartwrap"><div class="citems">'+rows+'</div>'
   +'<div class="csum"><div class="csr"><span>Items</span><b>'+cartCount()+'</b></div><div class="csr total"><span>Total</span><b>'+money(cartTotal())+'</b></div>'
   +'<div class="cnote"><i class="fa fa-truck"></i> '+esc(CFG.deliveryEta||'Fast delivery')+'<br><i class="fa fa-credit-card"></i> '+esc(CFG.payMethods||'Mobile Money · Pay on Delivery')+'<br><i class="fa fa-check-circle"></i> '+esc(CFG.genuineLine||'100% genuine or money back')+'</div>'
   +'<a class="btn btn-w big" href="'+esc(waCartOrder())+'" target="_blank" rel="noopener" onclick="track(\'checkout\',{product_ids:cartGet().map(function(i){return i.id;})})"><i class="fa fa-whatsapp"></i> Place order on WhatsApp</a>'
   +'<div class="cfine">You’ll confirm stock, delivery and payment with our team on WhatsApp. No payment is taken online.</div>'
   +'<a class="link" onclick="go(\'\')" style="display:block;text-align:center;margin-top:10px"><i class="fa fa-plus"></i> Add more parts</a></div></div></section>'+foot();
}
