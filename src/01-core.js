// EA Auto Part storefront — module: 01-core.js
// STATE, DOM helper, money/esc/sid, partNo, shop links
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
var STATE = { products:[], byCat:{}, byPn:{}, bySys:{}, pnPlate:{}, loaded:false, error:false };
function normPn(s){return String(s||'').replace(/\s+/g,'').toUpperCase();}
var $ = function(s,r){return (r||document).querySelector(s);};
function money(n){var c=(CFG.currency&&CFG.currency.symbol)||CFG.currency||'UGX';return c+' '+Number(n||0).toLocaleString('en-US');}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
// Safe identifier: ids/plate keys are identifiers, never markup. Whitelist so a hostile
// catalogue value can't break out of the single-quoted JS string inside an onclick handler.
function sid(x){return String(x==null?'':x).replace(/[^\w-]/g,'');}
function partNo(kw){
  var s=String(kw||''); var segs=s.split('|'); var re=/^[A-Z0-9]{2}\s?[A-Z0-9]{3,4}\s?[A-Z0-9]{2}$/;
  for(var i=1;i<segs.length;i++){ var t=segs[i].trim(); if(re.test(t)) return t; }  /* skip seg 0 (model name) */
  var m=s.match(/\b[A-Z0-9]{2}\s?[A-Z0-9]{3,4}\s?[A-Z0-9]{2}\b/); return m?m[0]:'';
}
function shopAdd(id){return (CFG.shopUrl||'#')+'?add='+encodeURIComponent(id);}
function shopCat(c){return (CFG.shopUrl||'#')+'?cat='+encodeURIComponent(c);}

