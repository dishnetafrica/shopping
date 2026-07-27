// EA Auto Part storefront — module: 12-a11y-boot.js
// accessibility enhancement + boot
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- accessibility: make onclick elements keyboard-operable + labelled ---------- */
function enhanceA11y(){
  try{
    var els=document.querySelectorAll('[onclick]');
    Array.prototype.forEach.call(els,function(el){
      var tag=el.tagName.toLowerCase();
      var native=(tag==='button')||(tag==='a'&&el.hasAttribute('href'))||tag==='input'||tag==='select'||tag==='textarea';
      // Don't advertise a container as a button if it wraps its own interactive controls
      // (that would be a "nested interactive" a11y violation) — its inner controls stay focusable.
      var wrapsInteractive=!!el.querySelector('button,a[href],input,select,textarea,[role="button"],[tabindex],[onclick]');
      if(!native && !wrapsInteractive){
        if(!el.hasAttribute('tabindex')) el.setAttribute('tabindex','0');
        if(!el.hasAttribute('role')) el.setAttribute('role','button');
      }
      // icon-only control with no visible text → label it from its title/aria for screen readers.
      if(!el.getAttribute('aria-label') && !(el.textContent||'').trim()){
        var t=el.getAttribute('title'); if(t) el.setAttribute('aria-label',t);
      }
    });
  }catch(e){}
}
// One delegated key handler: Enter / Space activates any role=button-ish onclick element.
document.addEventListener('keydown',function(e){
  if(e.key!=='Enter'&&e.key!==' '&&e.key!=='Spacebar') return;
  var el=e.target;
  if(!el||!el.getAttribute) return;
  var tag=el.tagName.toLowerCase();
  var native=(tag==='button')||(tag==='a'&&el.hasAttribute('href'))||tag==='input'||tag==='select'||tag==='textarea';
  if(native) return;
  if(el.hasAttribute('onclick')||el.getAttribute('role')==='button'){ e.preventDefault(); el.click(); }
});
window.addEventListener('hashchange',render);
(function(){var r=document.documentElement.style;if(CFG.accent)r.setProperty('--accent',CFG.accent);if(CFG.accentDark)r.setProperty('--accent-d',CFG.accentDark);
 document.getElementById('waFloat').href=waLink(); render(); loadCatalogue();})();
