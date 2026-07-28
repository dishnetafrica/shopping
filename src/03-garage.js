// EA Auto Part storefront — module: 03-garage.js
// saved bike (My Garage) + fitment
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- My Garage (saved bike) + fitment ---------- */
function getBike(){ try{ var id=localStorage.getItem('ea_bike'); return (MODELS||[]).filter(function(m){return m.id===id;})[0]||null; }catch(e){ return null; } }
function setBike(id){ try{ localStorage.setItem('ea_bike',id); }catch(e){} }
function clearBike(){ try{ localStorage.removeItem('ea_bike'); }catch(e){} render(); }
// Family/series of a model name: the leading word(s) before the numbers.
// "Boxer 100 KS" -> "boxer" ; "CT 125 HD" -> "ct" ; "Pulsar 150" -> "pulsar".
function bikeSeries(b){ var m=String((b&&(b.catName||b.name))||'').toLowerCase().match(/^[a-z]+/); return m?m[0]:''; }
/*
 * Smart, three-state fitment (never scares the customer with a false "not for"):
 *   true  -> confidently fits: the model or its family is named in the part, OR it's a
 *            universal / generic component (bearing, bolt, seal, cable…) that fits broadly.
 *   false -> confidently does NOT fit: the part names a DIFFERENT specific model and nothing
 *            ties it to this bike.
 *   null  -> unknown: don't hide it, don't badge it negatively.
 * This copes with real supplier data where stock is tagged by series ("BOXER") or left generic.
 */
// Compatible model ids for a part, from the official catalogue cross-reference (or null if the
// part number isn't in it). This is authoritative multi-model fitment.
function compatIds(p){ var pn=normPn(partNo(p.kw||'')); return (pn && PNFITS && PNFITS[pn])||null; }
var _pnfitsModels=null;
function pnfitsCovers(id){ if(!_pnfitsModels){ _pnfitsModels={}; for(var k in (PNFITS||{})){ (PNFITS[k]||[]).forEach(function(m){_pnfitsModels[m]=1;}); } } return !!_pnfitsModels[id]; }
function compatibleModels(p){ var ids=compatIds(p); if(!ids) return []; return (MODELS||[]).filter(function(m){return ids.indexOf(m.id)>=0;}); }
function fitsBike(p){
  var b=getBike(); if(!b) return null;
  // Authoritative: if the part is in the catalogue cross-reference AND this model has a catalogue,
  // trust the cross-reference exactly (fits iff the model is listed).
  var ids=compatIds(p);
  if(ids && pnfitsCovers(b.id)) return ids.indexOf(b.id)>=0;
  var hay=nk(String(p.kw||'')+' '+String(p.cat||''));
  var model=nk(b.catName||b.name);
  if(model && hay.indexOf(model)>=0) return true;      // explicit model match anywhere
  var series=bikeSeries(b);
  if(series && series.length>=2 && hay.indexOf(series)>=0) return true;  // same family (e.g. BOXER)
  if(isUniversal(p)||isGenericPart(p)) return true;    // generic hardware fits across models
  // Only say "no" when the part clearly names another specific model we know about.
  var namesOther=(MODELS||[]).some(function(m){ if(!m||m.id===b.id) return false; var mn=nk(m.catName||m.name); return mn && mn.length>=4 && hay.indexOf(mn)>=0; });
  return namesOther ? false : null;
}
