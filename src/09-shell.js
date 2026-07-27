// EA Auto Part storefront — module: 09-shell.js
// nav / bike bar / product cards / shell
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- shell ---------- */
/* Optional, non-technical vehicle filter — never a gate. Customers can pick a bike,
   browse everything, or hide the bar entirely. */
function barDismissed(){ try{return localStorage.getItem('ea_bikebar')==='off';}catch(e){return false;} }
function dismissBikeBar(){ try{localStorage.setItem('ea_bikebar','off');}catch(e){} render(); }
function bikeBar(){
  var b=getBike();
  if(b) return '<div class="bikebar has"><span class="bl"><i class="fa fa-check-circle"></i> Showing parts for <b>'+esc(b.name)+'</b></span><span class="bacts"><a class="bchg" onclick="openBikePicker()">Change</a><a class="ball" onclick="clearBike()">Show all parts</a></span></div>';
  if(barDismissed()) return '<div class="bikebar slim"><span>You’re browsing all parts.</span><a onclick="openBikePicker()">Pick your '+vehWord()+' to filter ›</a></div>';
  return '<div class="bikebar"><span class="bl" onclick="openBikePicker()"><b>Which '+vehWord()+' do you have?</b> Pick it to see only parts that fit — or just browse everything below.</span><span class="bbtn" onclick="openBikePicker()">Choose</span><i class="fa fa-times bx" onclick="dismissBikeBar()" title="Hide"></i></div>';
}
function nav(){
  return '<header class="nav"><div class="wrap">'
   +'<div class="brand" onclick="go(\'\')"><span class="emb">'+esc(CFG.initials||'EA')+'</span><span><b>'+esc(CFG.name||'Auto Part')+'</b><i>'+esc((CFG.eyebrow||'GENUINE SPARES').toUpperCase())+'</i></span></div>'
   +'<span class="sp"></span>'
   +'<span class="lnk" onclick="go(\'\')">Home</span>'
   +'<span class="lnk" onclick="go(\'parts\')">Parts</span>'
   +'<span class="lnk" onclick="go(\'ai\')">AI Assistant</span>'
   +'<a class="cart" onclick="go(\'cart\')">Cart <span class="cartn"></span></a>'
   +'</div></header>'+bikeBar();
}
/* Left filter sidebar for listing pages: jump between systems + narrow by stock/fit. */
function filterSidebar(active){
  var sys=systemsWithCounts(); var b=getBike(); var f=STATE.filters||{};
  var rows=sys.map(function(s){
    var on=nk(s.name)===nk(active||'');
    return '<a class="srow'+(on?' on':'')+'" onclick="go(\'system/'+encodeURIComponent(s.name)+'\')">'+esc(s.name)+'<span>'+s.count+'</span></a>';
  }).join('');
  function chk(k,label){ return '<label class="fchk"><input type="checkbox" onchange="toggleFilter(\''+k+'\')"'+(f[k]?' checked':'')+'> '+label+'</label>'; }
  return '<aside class="side" aria-label="Filters">'
    +'<div class="sblock"><div class="slabel">Categories</div>'
      +'<a class="srow'+(!active?' on':'')+'" onclick="go(\'parts\')">All categories</a>'+rows+'</div>'
    +'<div class="sblock"><div class="slabel">Refine</div>'
      +chk('stock','In stock only')
      +(b? chk('fit','Fits my '+esc(b.name)) : '<div class="fhint">Pick your '+vehWord()+' to filter by fit</div>')
    +'</div></aside>';
}
var PICK_TYPE=null;
function openBikePicker(){
  var el=document.getElementById('bikepick'); if(!el)return;
  var first=(activeModels()[0]||{}).name||'';
  var t=document.getElementById('bptitle'); if(t)t.textContent='Choose your '+vehWord();
  var s=document.getElementById('bpsearch'); if(s){s.value='';s.placeholder='Type your '+vehWord()+'… e.g. '+first;s.oninput=function(){renderPickerList();};}
  var types=vehTypes(), tabs=document.getElementById('bptabs');
  if(tabs){ if(types.length>1){ if(types.indexOf(PICK_TYPE)<0)PICK_TYPE=types[0]; tabs.style.display='flex';
      tabs.innerHTML=types.map(function(ty){return '<span class="bptab'+(ty===PICK_TYPE?' on':'')+'" onclick="PICK_TYPE=\''+ty+'\';renderPickerList()">'+esc(ty)+'s</span>';}).join(''); }
    else { tabs.style.display='none'; PICK_TYPE=null; } }
  renderPickerList(); el.classList.add('on');
  if(s)setTimeout(function(){try{s.focus();}catch(e){}},60);
}
function renderPickerList(){
  var q=nk((document.getElementById('bpsearch')||{}).value||'');
  var list=activeModels().filter(function(m){
    if(PICK_TYPE && m.type!==PICK_TYPE) return false;
    if(!q) return true;
    return nk((m.make||'')+' '+(m.name||'')+' '+(m.type||'')+' '+(m.tagline||'')).indexOf(q)>=0;
  });
  var grid=document.getElementById('bikepick').querySelector('.bpgrid');
  if(!list.length){ grid.className='bpgrid'; grid.innerHTML='<div class="bpnone">No match for that. <a onclick="waPick()">Ask us on WhatsApp ›</a></div>'; return; }
  if(list.length<=12){ grid.className='bpgrid';
    grid.innerHTML=list.map(function(m){return '<div class="bpc" onclick="pickBike(\''+String(m.id).replace(/\x27/g,"")+'\')"><div class="bpi"><img src="'+(IMG[m.id]||'')+'" alt="'+esc(m.name)+'" loading="lazy"></div><b>'+esc(m.name)+'</b><span>'+esc(m.tagline||m.make||'')+'</span></div>';}).join('');
  } else { grid.className='bpgrid rows';
    grid.innerHTML=list.map(function(m){return '<div class="bprow" onclick="pickBike(\''+String(m.id).replace(/\x27/g,"")+'\')"><img src="'+(IMG[m.id]||'')+'" alt="" loading="lazy"><div><b>'+esc(m.name)+'</b><span>'+esc((m.make||'')+(m.type?(' · '+m.type):''))+'</span></div><i class="fa fa-chevron-right"></i></div>';}).join('');
  }
}
function waPick(){ closeBikePick(); window.open(waLink(),'_blank'); }
function pickBike(id){ setBike(id); closeBikePick(); toast('Showing parts for your '+vehWord()); render(); }
function closeBikePick(){ var el=document.getElementById('bikepick'); if(el)el.classList.remove('on'); }
function foot(){
  var cc=[];if(CFG.phone)cc.push(esc(CFG.phone));if(CFG.email)cc.push(esc(CFG.email));if(CFG.website)cc.push(esc(CFG.website));
  return '<footer class="foot"><div class="wrap"><div><b>'+esc(CFG.name||'')+'</b> · '+esc(CFG.eyebrow||'')+'<div style="font-size:12px;margin-top:4px">'+cc.join(' · ')+'</div></div><div style="font-size:12px">Genuine parts guarantee · 1 Year Warranty</div></div></footer>';
}
function trust(){
  var items=[
    ['<i class="fa fa-check-circle"></i>','Genuine or money back', CFG.genuineLine||'100% genuine Bajaj parts'],
    ['<i class="fa fa-truck"></i>','Fast delivery', CFG.deliveryEta||'Same-day in town'],
    ['<i class="fa fa-credit-card"></i>','Easy payment', CFG.payMethods||'Mobile Money · Pay on Delivery'],
    ['<i class="fa fa-shield"></i>','Warranty & returns', (CFG.warranty||'1-year warranty')+' · '+(CFG.returns||'easy returns')]
  ];
  return '<div class="wrap"><div class="trust">'+items.map(function(t){return '<div><span class="d">'+t[0]+'</span><div><b>'+esc(t[1])+'</b><i>'+esc(t[2])+'</i></div></div>';}).join('')+'</div></div>';
}

