// EA Auto Part storefront — module: 02-catalogue.js
// normalize + loadCatalogue + indexes
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- data ---------- */
function normalize(json){
  var arr = Array.isArray(json)?json:(json.rows||json.products||json.items||json.data||[]);
  if(!Array.isArray(arr)){ for(var k in json){ if(Array.isArray(json[k])){arr=json[k];break;} } }
  return (arr||[]).map(function(p,i){
    return {
      id: p._row||p.id||p.ID||i,
      name: p['Product Name']||p.name||p.Name||'',
      cat: p.Category||p.category||'Other',
      price: p.Price_UGX!=null?p.Price_UGX:(p.price!=null?p.price:0),
      stock: (p.Stock!=null?p.Stock:(p.stock!=null?p.stock:null)),
      img: p.Image||p.image||p.image_url||'',
      kw: p.Keywords||p.keywords||'',
      desc: p.Description||p.description||''
    };
  }).filter(function(p){return p.name;});
}
function loadCatalogue(){
  if(!CFG.catalogueUrl){ STATE.error=true; render(); return; }
  fetch(CFG.catalogueUrl,{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(j){
    var all=normalize(j); STATE.allProducts=all; STATE.byCat={}; STATE.byPn={}; STATE.bySys={};
    // "Sell only what we have": hide parts with an explicit 0 stock (untracked/null still shows).
    STATE.products = CFG.hideOOS ? all.filter(function(p){ return !(p.stock!=null && p.stock<=0); }) : all;
    // byPn from ALL parts so exploded diagrams still resolve every callout
    all.forEach(function(p){ var pn=normPn(partNo(p.kw)); if(pn && !STATE.byPn[pn])STATE.byPn[pn]=p; });
    var sysNames=(CFG.systems||[]).map(function(s){return s.name;});
    STATE.products.forEach(function(p){
      (STATE.byCat[p.cat]=STATE.byCat[p.cat]||[]).push(p);
      var segs=String(p.cat||'').split('>').concat(String(p.kw||'').split('|')).map(nk);
      sysNames.forEach(function(sn){ var k=nk(sn); if(segs.indexOf(k)>=0){ (STATE.bySys[k]=STATE.bySys[k]||[]).push(p); } });
    });
    (DIAG&&DIAG.plates||[]).forEach(function(pl){ (pl.parts||[]).forEach(function(x){ STATE.pnPlate[normPn(x.pn)]=pl.plate; }); });
    STATE.loaded=true; render();
  }).catch(function(){ STATE.error=true; STATE.loaded=true; render(); });
}
function systemsWithCounts(){
  return (CFG.systems||[]).map(function(s){
    var list=(STATE.bySys&&STATE.bySys[nk(s.name)])||STATE.byCat[s.name]||[];
    var cov=(SYSCOVER&&(SYSCOVER[s.id]||SYSCOVER[s.name]))||'';
    return {id:s.id,name:s.name,count:list.length,cover:cov};
  });
}
