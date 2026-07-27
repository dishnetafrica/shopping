// EA Auto Part storefront — module: 05-whatsapp.js
// order on WhatsApp (single part)
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- order on WhatsApp (owns the checkout) ---------- */
function waOrder(p){
  var b=getBike(); var pn=partNo(p.kw);
  var t='Hello '+(CFG.name||'')+', I want to order this genuine part:%0a• '+p.name+(pn?(' (Part No '+pn+')'):'')
    +'%0a• Price: '+money(p.price)+'%0a• Qty: 1'+(b?('%0a• My motorcycle: '+b.name):'')
    +'%0a%0aIs it in stock and how soon can I get it?';
  return CFG.waNumber?('https://wa.me/'+CFG.waNumber+'?text='+t):'#';
}
