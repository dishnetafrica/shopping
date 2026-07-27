// EA Auto Part storefront — module: 07-analytics.js
// analytics beacons to /event
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- analytics (fire-and-forget to the shop's /event log) ---------- */
function eventUrl(){ return CFG.eventUrl || (CFG.catalogueUrl? CFG.catalogueUrl.replace(/\/catalogue(\?.*)?$/,'/event') : '/event'); }
function track(ev, payload){
  try{
    var body=JSON.stringify(Object.assign({event:ev}, payload||{}));
    var url=eventUrl();
    if(navigator.sendBeacon){ navigator.sendBeacon(url, new Blob([body],{type:'application/json'})); }
    else { fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true,credentials:'same-origin'}).catch(function(){}); }
  }catch(e){}
}
