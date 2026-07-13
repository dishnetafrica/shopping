<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>CloudBSS</title>
<style>
:root{--bg:#f6f7f9;--surface:#fff;--line:#e7e9ed;--line2:#eef0f3;--ink:#151b23;--ink2:#3c4756;--muted:#6b7684;--faint:#98a1ad;
--brand:#16a34a;--brand-ink:#15803d;--wash:#eafaf0;--warn:#d97706;--warn-w:#fdf3e6;--danger:#dc2626;--danger-w:#fdecec;--info:#0284c7;--info-w:#e8f6fe;
--side:#0e1b16;--side-ink:#aeb9b3;--radius:10px;--mono:ui-monospace,Menlo,Consolas,monospace;
--font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:var(--font);background:var(--bg);color:var(--ink);font-size:14px;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer;border:none;background:none;color:inherit}
.app{display:grid;grid-template-columns:236px 1fr;height:100vh}
.side{background:var(--side);color:var(--side-ink);display:flex;flex-direction:column;overflow:hidden}
.brand{height:56px;display:flex;align-items:center;gap:10px;padding:0 18px;color:#fff;font-weight:700;letter-spacing:-.02em;border-bottom:1px solid rgba(255,255,255,.06)}
.brand .m{width:28px;height:28px;border-radius:8px;background:var(--brand);display:grid;place-items:center}
.nav{flex:1;overflow-y:auto;padding:12px 10px}
.ngrp{font-size:10.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#7f8d86;padding:14px 12px 6px}
.nitem{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;font-size:13.5px;font-weight:500;cursor:pointer;color:var(--side-ink);margin-bottom:1px}
.nitem:hover{background:rgba(255,255,255,.06);color:#eef5f0}
.nitem.on{background:rgba(22,163,74,.18);color:#fff}
.nfoot{padding:12px;border-top:1px solid rgba(255,255,255,.06);font-size:12px;color:#7f8d86}
.main{display:flex;flex-direction:column;overflow:hidden}
.top{height:56px;background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;padding:0 20px}
.top .sb{flex:1;max-width:420px;position:relative}
.top .sb input{width:100%;height:36px;border:1px solid var(--line);border-radius:8px;padding:0 12px;background:var(--bg)}
.top .sp{flex:1}
.view{flex:1;overflow-y:auto}.wrap{padding:22px 26px 60px;max-width:1200px}
.hdr{display:flex;align-items:flex-start;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.hdr .crumb{font-size:12px;color:var(--muted);margin-bottom:4px}
.hdr h1{font-size:21px;font-weight:700;letter-spacing:-.02em;display:flex;align-items:center;gap:10px}
.hdr .sub{color:var(--muted);font-size:13px;margin-top:2px}
.hdr .hl{margin-left:auto;text-align:right}.hdr .hl .v{font-size:21px;font-weight:750;letter-spacing:-.02em}
.hdr .hl .v.warn{color:var(--warn)}.hdr .hl .v.danger{color:var(--danger)}.hdr .hl .v.ok{color:var(--brand-ink)}
.hdr .hl .l{font-size:12px;color:var(--muted)}
.acts{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}
.btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:8px;font-weight:600;font-size:13px;border:1px solid var(--line);background:var(--surface);color:var(--ink2)}
.btn:hover{background:var(--bg)}.btn.pri{background:var(--brand);border-color:var(--brand);color:#fff}.btn.pri:hover{background:var(--brand-ink)}
.btn.ai{border-style:dashed;border-color:#c9d3f0;color:#4f46e5}
.grid{display:grid;gap:16px}.rec{grid-template-columns:280px 1fr}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 1px 2px rgba(16,24,40,.05)}
.card .h{padding:14px 16px;border-bottom:1px solid var(--line);font-weight:650;font-size:14px}
.card .b{padding:16px}
.fin{display:flex;flex-direction:column;gap:11px}.fin .r{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
.fin .r .l{font-size:12.5px;color:var(--muted)}.fin .r .v{font-weight:700;font-variant-numeric:tabular-nums}
.fin .v.warn{color:var(--warn)}.fin .v.danger{color:var(--danger)}.fin .v.ok{color:var(--brand-ink)}
.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:16px}
.metrics .m{background:var(--surface);padding:13px 15px}.metrics .m .l{font-size:11px;color:var(--muted);font-weight:600}.metrics .m .v{font-size:17px;font-weight:750;margin-top:3px}
.metrics .m .v.warn{color:var(--warn)}.metrics .m .v.danger{color:var(--danger)}.metrics .m .v.ok{color:var(--brand-ink)}
.assist{display:flex;gap:13px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--brand);border-radius:var(--radius);padding:15px 17px;margin-bottom:16px}
.assist.high{border-left-color:var(--danger)}.assist.med{border-left-color:var(--warn)}
.assist .ai{width:36px;height:36px;border-radius:9px;background:var(--wash);color:var(--brand-ink);display:grid;place-items:center;flex:none;font-weight:800}
.assist .sit{font-weight:650;font-size:14px;display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.assist .why{font-size:13px;color:var(--muted);margin-top:4px}
.assist .rec{font-size:12.5px;color:var(--ink2);background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:9px 11px;margin-top:9px}
.assist .aacts{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:2px 9px;border-radius:20px}
.badge .d{width:6px;height:6px;border-radius:50%}
.b-ok{background:var(--wash);color:var(--brand-ink)}.b-ok .d{background:var(--brand)}
.b-warn{background:var(--warn-w);color:var(--warn)}.b-warn .d{background:var(--warn)}
.b-danger{background:var(--danger-w);color:var(--danger)}.b-danger .d{background:var(--danger)}
.b-info{background:var(--info-w);color:var(--info)}.b-info .d{background:var(--info)}
.b-default{background:var(--line2);color:var(--muted)}.b-default .d{background:var(--faint)}
.qrow{display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid var(--line2);cursor:pointer}
.qrow:hover{background:var(--wash)}.qrow:last-child{border:none}.qrow .m{flex:1;min-width:0}.qrow .t{font-weight:600;font-size:13.5px}.qrow .s{font-size:12px;color:var(--muted)}
.tl .e{position:relative;padding:0 0 14px 26px;border-left:2px solid var(--line);margin-left:6px}
.tl .e:last-child{border-color:transparent}.tl .e .d{position:absolute;left:-5px;top:2px;width:8px;height:8px;border-radius:50%;background:var(--brand)}
.tl .e .tt{font-weight:600;font-size:13px}.tl .e .ts{font-size:12px;color:var(--muted)}
.doc{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:9px;margin-bottom:8px;cursor:pointer}.doc:hover{border-color:var(--brand)}
.link{color:#4f46e5;font-weight:600;cursor:pointer}.mut{color:var(--muted)}
.empty{padding:40px;text-align:center;color:var(--muted)}
.ov{position:fixed;inset:0;background:rgba(17,24,28,.45);display:none;align-items:flex-start;justify-content:center;padding-top:12vh;z-index:60}
.ov.on{display:flex}.modal{background:#fff;border-radius:12px;box-shadow:0 12px 32px rgba(16,24,40,.2);width:100%;max-width:440px}
.modal .h{padding:16px 18px;border-bottom:1px solid var(--line);font-weight:700}.modal .b{padding:18px}.modal .f{padding:14px 18px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:8px;background:var(--bg);border-radius:0 0 12px 12px}
.fld{margin-bottom:12px}.fld label{display:block;font-size:12.5px;font-weight:600;margin-bottom:5px}.fld input,.fld select{width:100%;height:38px;border:1px solid var(--line);border-radius:7px;padding:0 11px}
#toast{position:fixed;bottom:20px;right:20px;background:var(--ink);color:#fff;padding:11px 15px;border-radius:9px;font-size:13px;display:none;z-index:80}
.spin{padding:60px;text-align:center;color:var(--muted)}
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><span class="m">◆</span> CloudBSS</div>
    <div class="nav" id="nav"></div>
    <div class="nfoot" id="nfoot"></div>
  </aside>
  <div class="main">
    <header class="top">
      <div class="sb"><input id="search" placeholder="Open a record… (e.g. customer 5)"></div>
      <div class="sp"></div>
    </header>
    <main class="view"><div class="wrap" id="view"><div class="spin">Loading…</div></div></main>
  </div>
</div>
<div class="ov" id="ov"><div class="modal" id="modal"></div></div>
<div id="toast"></div>

<script>
const CFG = { base:'/api/v1/workspace', csrf: document.querySelector('meta[name=csrf-token]').content, user: @json(['name'=>'']) };
const LABELS = { briefing:'Morning Briefing', worklist:'My Work', collections:'Collections', finance:'Finance',
  'sales.process':'Sales', 'purchase.process':'Purchasing', warehouse:'Warehouse', dispatch:'Dispatch',
  customer:'Customer', invoice:'Invoice', payment:'Payment', supplier:'Supplier', product:'Product',
  salesOrder:'Sales Order', purchaseOrder:'Purchase Order', statement:'Statement' };
const GROUPS = [ ['Overview',['briefing','worklist']], ['Sales',['collections','sales.process']],
  ['Purchasing',['purchase.process']], ['Operations',['warehouse','dispatch']], ['Finance',['finance']] ];

let CUR = { key:null, id:null };

async function api(path){
  const r = await fetch(CFG.base+path, { credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
  if(!r.ok && r.status!==200){ return { _error:r.status }; }
  return r.json();
}
async function post(endpoint, body){
  const r = await fetch(endpoint, { method:'POST', credentials:'include',
    headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':CFG.csrf,'Content-Type':'application/json' },
    body: JSON.stringify(body||{}) });
  return r.json();
}
function toast(m){ const t=document.getElementById('toast'); t.textContent=m; t.style.display='block'; setTimeout(()=>t.style.display='none',2600); }
function money(v){ return v; }
function badge(status){ const map={overdue:'b-danger',out:'b-danger',blocked:'b-danger',partial:'b-warn',low:'b-warn',draft:'b-default',active:'b-ok',paid:'b-ok','in':'b-ok',received:'b-ok',inactive:'b-default'};
  return '<span class="badge '+(map[status]||'b-default')+'"><span class="d"></span>'+(status||'')+'</span>'; }
function toneBadge(o){ if(!o) return ''; const m={danger:'b-danger',warn:'b-warn',ok:'b-ok',info:'b-info'}; return '<span class="badge '+(m[o.tone]||'b-default')+'"><span class="d"></span>'+o.label+'</span>'; }
function apiToHash(p){ if(!p) return null; const i=p.indexOf('/workspace/'); if(i<0) return null; const parts=p.slice(i+11).split('/'); return { key:parts[0], id:parts[1]||null, action:parts[2]||null }; }

async function boot(){
  const cat = await api('/');
  CFG.user = cat.user || CFG.user;
  document.getElementById('nfoot').textContent = (CFG.user.name||'User')+' · '+(CFG.user.role||'');
  renderNav(cat.capabilities||[]);
  const h = parseHash();
  route(h.key||'briefing', h.id);
}
function parseHash(){ const p=location.hash.replace('#/','').split('/'); return { key:p[0]||'', id:p[1]||null }; }
function renderNav(caps){
  const keys = new Set(caps.map(c=>c.key));
  let html='';
  GROUPS.forEach(([g,items])=>{
    const avail=items.filter(k=>keys.has(k));
    if(!avail.length) return;
    html+='<div class="ngrp">'+g+'</div>';
    avail.forEach(k=>{ html+='<div class="nitem" data-key="'+k+'" onclick="route(\''+k+'\')">'+(LABELS[k]||k)+'</div>'; });
  });
  document.getElementById('nav').innerHTML=html;
}
function setActive(key){ document.querySelectorAll('.nitem').forEach(n=>n.classList.toggle('on', n.dataset.key===key)); }

async function route(key, id){
  CUR={key,id}; location.hash='#/'+key+(id?'/'+id:''); setActive(key);
  document.getElementById('view').innerHTML='<div class="spin">Loading '+(LABELS[key]||key)+'…</div>';
  const p = await api('/'+key+(id?'/'+id:''));
  if(p._error){ document.getElementById('view').innerHTML='<div class="empty">Could not load ('+p._error+'). You may not have access.</div>'; return; }
  renderView(p);
}
function refresh(){ route(CUR.key, CUR.id); }

function renderView(p){
  const o=p.object||{}; const isProcess=(o.type||'').startsWith('process:');
  let h='<div class="hdr"><div>'+(p.meta&&p.meta.capability?'<div class="crumb">'+(LABELS[p.meta.capability]||p.meta.capability)+'</div>':'')+
    '<h1>'+(o.title||'')+' '+(o.status?badge(o.status):'')+'</h1>'+(o.subtitle?'<div class="sub">'+o.subtitle+'</div>':'')+'</div>';
  if(p.headline){ h+='<div class="hl"><div class="v '+(p.headline.tone||'')+'">'+p.headline.value+'</div><div class="l">'+p.headline.label+'</div></div>'; }
  h+='<div class="acts">'+(p.actions||[]).map(actBtn).join('')+'</div></div>';

  if(isProcess){
    if(p.financials&&p.financials.length){ h+='<div class="metrics">'+p.financials.map(f=>'<div class="m"><div class="l">'+f.label+'</div><div class="v '+(f.tone||'')+'">'+fval(f)+'</div></div>').join('')+'</div>'; }
    h+=briefHtml(p.brief);
    h+='<div class="card"><div class="h">'+(o.title||'Items')+'</div>'+(p.related&&p.related.length? p.related.map(qrow).join('') : '<div class="empty">Nothing pending — all clear.</div>')+'</div>';
  } else {
    h+=briefHtml(p.brief);
    h+='<div class="grid rec">';
    h+='<div class="card"><div class="b"><div class="fin">'+(p.financials||[]).map(f=>'<div class="r"><span class="l">'+f.label+'</span><span class="v '+(f.tone||'')+'">'+fval(f)+'</span></div>').join('')+'</div></div></div>';
    h+='<div class="grid" style="gap:16px">';
    if(p.timeline&&p.timeline.length){ h+='<div class="card"><div class="h">Timeline</div><div class="b"><div class="tl">'+p.timeline.map(e=>'<div class="e"><div class="d"></div><div class="tt">'+e.title+'</div><div class="ts">'+(e.at||'')+(e.actor?' · '+e.actor:'')+'</div></div>').join('')+'</div></div></div>'; }
    if(p.related&&p.related.length){ h+='<div class="card"><div class="h">Related</div>'+p.related.map(qrow).join('')+'</div>'; }
    if(p.documents&&p.documents.length){ h+='<div class="card"><div class="h">Documents</div><div class="b">'+p.documents.map(d=>'<div class="doc" onclick="window.open(\''+(d.url||'#')+'\')">'+d.title+'</div>').join('')+'</div></div>'; }
    h+='</div></div>';
  }
  document.getElementById('view').innerHTML=h;
}
function fval(f){ let v=f.value; if(f.format==='pct') return v+'%'; if(f.currency&&typeof v==='number') return (f.currency==='USD'?'$':f.currency+' ')+Number(v).toLocaleString(); return v; }
function briefHtml(b){ if(!b) return ''; return '<div class="assist '+(b.level||'low')+'"><div class="ai">✦</div><div style="flex:1"><div class="sit">'+b.situation+' '+toneBadge(b.risk)+'</div>'+
  '<div class="why">'+(b.why||'')+'</div>'+(b.recommendation?'<div class="rec"><b>Recommendation:</b> '+b.recommendation+'</div>':'')+
  '<div class="aacts">'+(b.actions||[]).map(actBtn).join('')+'</div></div></div>'; }
function qrow(r){ const link=r.link?(' onclick="openLink(\''+r.link+'\')"'):''; return '<div class="qrow"'+link+'><div class="m"><div class="t">'+(r.label||r.type)+(r.count!=null?' <span class="mut">('+r.count+')</span>':'')+'</div>'+(r.sublabel?'<div class="s">'+r.sublabel+'</div>':'')+'</div><div class="mut">›</div></div>'; }
function openLink(apiPath){ const h=apiToHash(apiPath); if(h) route(h.key, h.id); }

function actBtn(a){ const cls=a.actor==='ai'?'btn ai':('btn'+(a.tone==='primary'?' pri':'')); return '<button class="'+cls+'" onclick=\'doAction('+JSON.stringify(a).replace(/'/g,"&#39;")+')\'>'+a.label+'</button>'; }
async function doAction(a){
  if((a.method||'POST')==='GET'){ const h=apiToHash(a.endpoint); if(h){ route(h.key,h.id); } return; }
  if(a.schema){ return formModal(a); }
  const res=await post(a.endpoint,{}); toast(res.ok?'Done':(res.error||'Error')); refresh();
}
function formModal(a){
  let fields=''; Object.entries(a.schema).forEach(([k,type])=>{
    if(String(type).startsWith('enum:')){ const opts=String(type).slice(5).split(','); fields+='<div class="fld"><label>'+k+'</label><select id="f_'+k+'">'+opts.map(o=>'<option>'+o+'</option>').join('')+'</select></div>'; }
    else fields+='<div class="fld"><label>'+k+'</label><input id="f_'+k+'" '+(type==='money'?'type="number" step="0.01"':'')+'></div>';
  });
  document.getElementById('modal').innerHTML='<div class="h">'+a.label+'</div><div class="b">'+fields+'</div><div class="f"><button class="btn" onclick="closeM()">Cancel</button><button class="btn pri" id="mok">Confirm</button></div>';
  document.getElementById('ov').classList.add('on');
  document.getElementById('mok').onclick=async()=>{ const body={}; Object.keys(a.schema).forEach(k=>{ const el=document.getElementById('f_'+k); if(el) body[k]=el.value; }); closeM(); const res=await post(a.endpoint,body); toast(res.ok?'Recorded':(res.error||'Error')); refresh(); };
}
function closeM(){ document.getElementById('ov').classList.remove('on'); }
document.getElementById('ov').addEventListener('click',e=>{ if(e.target.id==='ov') closeM(); });
document.getElementById('search').addEventListener('keydown',e=>{ if(e.key==='Enter'){ const p=e.target.value.trim().split(/\s+/); if(p[0]&&p[1]) route(p[0].toLowerCase(),p[1]); } });
window.addEventListener('hashchange',()=>{ const h=parseHash(); if(h.key && (h.key!==CUR.key||h.id!==CUR.id)) route(h.key,h.id); });

boot();
</script>
</body>
</html>
