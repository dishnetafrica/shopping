// EA Auto Part storefront — module: 04-vehicle.js
// vehicle taxonomy (scales to cars)
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- vehicle taxonomy (scales to cars) ---------- */
function vehTypes(){ var t={}; (MODELS||[]).forEach(function(m){ if(m.type)t[m.type]=1; }); return Object.keys(t); }
function vehWord(){ return vehTypes().length>1 ? 'vehicle' : 'motorcycle'; }
function isUniversal(p){ var h=(String(p.cat||'')+' '+String(p.kw||'')).toLowerCase(); return h.indexOf('universal')>=0||h.indexOf('fits all')>=0||h.indexOf('all models')>=0; }
/* When a vehicle is chosen, lists show only parts that fit it (+ universal). "Show all" clears it. */
function vehFilter(arr){ var b=getBike(); if(!b) return arr; var out=arr.filter(function(p){ return fitsBike(p)||isUniversal(p); }); return out.length?out:arr; }
function partsInSystem(name){ return vehFilter((STATE.bySys&&STATE.bySys[nk(name)])||STATE.byCat[name]||[]); }
/* fitting guide — difficulty + tools inferred from the part's system (a guide, not a rule) */
function fitGuide(p){
  var sys=nk(String(p.kw||'').split('|')[1]||'')||nk(String(p.cat||'').split('>').slice(-2)[0]||'');
  var map={brakes:['Easy','Spanner set, pliers'],electrical:['Easy','Screwdriver, spanner'],controls:['Easy','Spanner, pliers'],
    fuelsystem:['Easy','Screwdriver, spanner'],accessories:['Easy','Basic hand tools'],frameandbody:['Medium','Spanner set, screwdriver'],
    wheels:['Medium','Spanner, tyre levers, stand'],wheelsandtyres:['Medium','Spanner, tyre levers, stand'],suspension:['Medium','Spanner set, stand'],
    transmission:['Advanced','Clutch/chain tools — mechanic advised'],engine:['Advanced','Workshop — mechanic recommended']};
  var m=map[sys]||['Medium','Basic hand tools']; return {level:m[0],tools:m[1]};
}
