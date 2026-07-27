// EA Auto Part storefront — module: 11-router.js
// router: render/go/search helpers
// NOTE: these src/ files share one runtime scope; build_storefront.py concatenates them
// in filename order into a single inline bundle. Keep 12-a11y-boot.js last (it boots the app).
/* ---------- router ---------- */
function waLink(){return CFG.waNumber?('https://wa.me/'+CFG.waNumber):'#';}
function openSearch(){var s=document.getElementById('ssheet');if(s){s.classList.add('on');var i=document.getElementById('ssin');if(i){i.value='';setTimeout(function(){i.focus();},50);i.onkeydown=function(e){if(e.key==='Enter')doSheetSearch();if(e.key==='Escape')closeSearch();};}}}
function closeSearch(){var s=document.getElementById('ssheet');if(s)s.classList.remove('on');}
function doSheetSearch(){var i=document.getElementById('ssin');var q=(i&&i.value||'').trim();closeSearch();if(q)go('search/'+encodeURIComponent(q));}
function go(path){location.hash='#/'+path;}
function qsearch(q){go('search/'+encodeURIComponent(q));}
function doSearch(e){e.preventDefault();var v=$('#q').value.trim();if(v)qsearch(v);return false;}
function qd(d){var n=$('#qn');n.value=Math.max(1,(parseInt(n.value)||1)+d);}
function render(){
  var h=(location.hash||'').replace(/^#\//,''); var parts=h.split('/');
  var v;
  if(!STATE.loaded && !STATE.error){ v=nav()+'<div class="spin"></div>'; document.getElementById('app').innerHTML=v; return; }
  if(parts[0]==='part') v=vPart(parts[1]);
  else if(parts[0]==='system') v=vSystem(decodeURIComponent(parts.slice(1).join('/')));
  else if(parts[0]==='model') v=vModel(parts[1]);
  else if(parts[0]==='parts') v=vParts();
  else if(parts[0]==='plate'||parts[0]==='diagram'||parts[0]==='diagrams') v=vDiagram(parts[1]);
  else if(parts[0]==='search') v=vSearch(parts.slice(1).join('/'));
  else if(parts[0]==='ai') v=vAI();
  else if(parts[0]==='cart') v=vCart();
  else v=vHome();
  document.getElementById('app').innerHTML=v;
  window.scrollTo(0,0);
  updateCartBadge();
  var r=document.documentElement.style; if(CFG.accent)r.setProperty('--accent',CFG.accent); if(CFG.accentDark)r.setProperty('--accent-d',CFG.accentDark);
  var wf=document.getElementById('waFloat'); if(wf){wf.href=waLink(); wf.onclick=function(){track('whatsapp',{source:'float'});};}
  var bc=document.getElementById('botCart'); if(bc)bc.href=CFG.shopUrl||'#';
  enhanceA11y();
}
