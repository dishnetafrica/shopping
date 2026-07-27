// EA Auto Part storefront — module: 03-garage.js
// saved bike (My Garage) + fitment
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- My Garage (saved bike) + fitment ---------- */
function getBike(){ try{ var id=localStorage.getItem('ea_bike'); return (MODELS||[]).filter(function(m){return m.id===id;})[0]||null; }catch(e){ return null; } }
function setBike(id){ try{ localStorage.setItem('ea_bike',id); }catch(e){} }
function clearBike(){ try{ localStorage.removeItem('ea_bike'); }catch(e){} render(); }
function fitsBike(p){ var b=getBike(); if(!b) return null; var seg=nk(String(p.kw||'').split('|')[0]); return seg===nk(b.catName||b.name); }
