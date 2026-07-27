// EA Auto Part storefront — module: 06-cart.js
// native cart (checkout via WhatsApp)
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- native cart (checkout via WhatsApp) ---------- */
function cartGet(){ try{return JSON.parse(localStorage.getItem('ea_cart')||'[]');}catch(e){return [];} }
function cartSave(c){ try{localStorage.setItem('ea_cart',JSON.stringify(c));}catch(e){} updateCartBadge(); }
function cartCount(){ return cartGet().reduce(function(n,i){return n+i.qty;},0); }
function cartTotal(){ return cartGet().reduce(function(s,i){return s+(i.price||0)*i.qty;},0); }
function cartAdd(id){ var p=STATE.products.filter(function(x){return String(x.id)===String(id);})[0]; if(!p)return;
  var c=cartGet(); var e=c.filter(function(i){return String(i.id)===String(p.id);})[0];
  if(e)e.qty++; else c.push({id:p.id,name:p.name,pn:partNo(p.kw),price:p.price||0,qty:1});
  cartSave(c); track('add',{product_id:p.id}); toast('“'+p.name+'” added to cart'); }
function cartSet(id,d){ var c=cartGet(); for(var i=0;i<c.length;i++){ if(String(c[i].id)===String(id)){ c[i].qty=Math.max(0,c[i].qty+d); if(!c[i].qty)c.splice(i,1); break; } } cartSave(c); render(); }
function updateCartBadge(){ var n=(function(){try{return cartCount();}catch(e){return 0;}})(); Array.prototype.forEach.call(document.querySelectorAll('.cartn'),function(el){ el.textContent=n; el.style.display=n?'inline-flex':'none'; }); }
function toast(msg){ var t=document.getElementById('toast'); if(!t){t=document.createElement('div');t.id='toast';t.setAttribute('role','status');t.setAttribute('aria-live','polite');document.body.appendChild(t);} t.textContent=msg; t.classList.add('on'); clearTimeout(window._tt); window._tt=setTimeout(function(){t.classList.remove('on');},1900); }
