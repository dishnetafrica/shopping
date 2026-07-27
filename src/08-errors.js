// EA Auto Part storefront — module: 08-errors.js
// client-side error capture + misc helpers
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- client-side error capture (surfaces JS crashes server-side/Sentry) ---------- */
function errorUrl(){ return CFG.errorUrl || (CFG.catalogueUrl? CFG.catalogueUrl.replace(/\/catalogue(\?.*)?$/,'/clienterror') : '/clienterror'); }
var _sentErrs=0;
function reportError(o){
  try{
    if(_sentErrs>=5) return;           // cap: never flood on a render loop
    _sentErrs++;
    var body=JSON.stringify(o||{});
    if(navigator.sendBeacon){ navigator.sendBeacon(errorUrl(), new Blob([body],{type:'application/json'})); }
    else { fetch(errorUrl(),{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true}).catch(function(){}); }
  }catch(e){}
}
window.addEventListener('error', function(e){
  reportError({message:(e.message||'error'), source:(e.filename||''), line:(e.lineno||0), col:(e.colno||0),
               stack:(e.error&&e.error.stack)||'', url:location.href});
});
window.addEventListener('unhandledrejection', function(e){
  var r=e.reason; reportError({message:'unhandledrejection: '+((r&&r.message)||r||'?'), stack:(r&&r.stack)||'', url:location.href});
});
function waCartOrder(){ var c=cartGet(); if(!c.length)return waLink(); var b=getBike();
  var lines=c.map(function(i){return '• '+i.qty+'x '+i.name+(i.pn?(' ('+i.pn+')'):'')+(i.price>0?(' — '+money(i.price*i.qty)):'');}).join('%0a');
  var t='Hello '+(CFG.name||'')+', I want to order these genuine parts:%0a'+lines+'%0a%0aTotal: '+money(cartTotal())+(b?('%0aMy motorcycle: '+b.name):'')+'%0a%0aPlease confirm stock, delivery time and payment.';
  return CFG.waNumber?('https://wa.me/'+CFG.waNumber+'?text='+t):'#'; }
/* ---------- which bike models to show ----------
   1) If the shop set an explicit list (Settings -> Storefront), show exactly those, in order.
   2) Otherwise show every model that has parts loaded in the catalogue (auto).
   3) If neither yields anything yet (e.g. catalogue still loading), fall back to all. */
function nk(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,''); }
function modelHasParts(m){
  var key=nk(m.catName||m.name);
  if(!key) return false;
  for(var i=0;i<STATE.products.length;i++){
    var seg=String(STATE.products[i].kw||'').split('|')[0];
    if(nk(seg)===key) return true;
  }
  return false;
}
function activeModels(){
  var all=(MODELS||[]);
  var want=(CFG.enabledModels||[]);
  if(want.length){
    var out=[];
    want.forEach(function(tok){
      var t=nk(tok);
      var m=all.filter(function(x){return nk(x.id)===t||nk(x.name)===t||nk(x.catName)===t;})[0]
          || all.filter(function(x){return nk(x.name).indexOf(t)===0||nk(x.catName).indexOf(t)===0;})[0];
      if(m&&out.indexOf(m)<0) out.push(m);
    });
    if(out.length) return out;
  }
  if(STATE.loaded && STATE.products.length){
    var withParts=all.filter(modelHasParts);
    if(withParts.length) return withParts;
  }
  return all.slice(0,5);
}

