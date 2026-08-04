<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Evidence\EvidenceService;
use App\Services\Evidence\MissingEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * v4.33 — the Evidence screen: every transaction and the documents behind it.
 *
 *   GET  /papi/evidence            the page (embeds in the v3 shell)
 *   GET  /papi/evidence-list       JSON: transactions + how many documents each has
 *   GET  /papi/evidence-docs       JSON: the documents on one transaction
 *   POST /papi/evidence-attach     upload one or more files against a transaction
 *   GET  /papi/evidence-file       stream one document (authenticated, tenant-scoped)
 *   POST /papi/evidence-detach     remove a document (records who and why; never deletes)
 *
 * Files are streamed through here rather than served from /storage/. That URL is raw
 * web-server file serving with NO tenant check — anyone holding a path could read another
 * business's receipts — and it depends on a storage symlink this host drops on redeploy.
 */
class EvidenceController extends Controller
{
    private function gate(Request $request)
    {
        $u = $request->user();
        if (! $u || ! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return null;
        }

        return $u->tenant;
    }

    public function page(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response('Owner access required — sign in to the panel first.', 403);
        }
        $notice = EvidenceService::available() ? ''
            : '<div class="empty"><h2>Not migrated yet</h2><p>Run <code>php artisan migrate</code> on the '
              . 'server, then reload.</p></div>';

        return response($this->shell($notice, csrf_token()))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function list(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $res = MissingEvidence::report((int) $t->id, [
            'only'  => (string) $request->input('only', 'missing'),
            'min'   => (float) $request->input('min', 0),
            'from'  => (string) $request->input('from', ''),
            'to'    => (string) $request->input('to', ''),
            'limit' => (int) $request->input('limit', 300),
        ]);

        return response()->json([
            'ok' => true, 'rows' => $res['rows'], 'summary' => $res['summary'],
            'kinds' => Attachment::KINDS, 'migrated' => EvidenceService::available(),
        ]);
    }

    public function docs(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $type = (string) $request->input('type', '');
        $id = (int) $request->input('id', 0);
        if (! Attachment::isSubject($type) || $id <= 0) {
            return response()->json(['ok' => false, 'error' => 'bad_subject'], 422);
        }

        $docs = EvidenceService::forSubject((int) $t->id, $type, $id)->map(fn ($a) => [
            'id'    => (int) $a->id,
            'name'  => (string) $a->original_name,
            'kind'  => (string) $a->kind,
            'label' => Attachment::kindLabel((string) $a->kind),
            'size'  => $a->sizeLabel(),
            'image' => $a->isImage(),
            'by'    => (string) ($a->uploaded_by_name ?? ''),
            'at'    => optional($a->created_at)->toDateTimeString(),
            'sha'   => substr((string) $a->sha256, 0, 12),
        ]);

        return response()->json(['ok' => true, 'docs' => $docs]);
    }

    public function attach(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $type = (string) $request->input('type', '');
        $id   = (int) $request->input('id', 0);
        $kind = (string) $request->input('kind', 'receipt');
        $note = trim((string) $request->input('note', ''));

        $files = $request->file('files', []);
        if (! is_array($files)) {
            $files = [$files];
        }
        if (! $files && $request->file('file')) {
            $files = [$request->file('file')];
        }
        if (! $files) {
            return response()->json(['ok' => false, 'error' => 'no_file', 'message' => 'Pick a photo or PDF first.'], 422);
        }

        $u = $request->user();
        $saved = []; $failed = [];
        foreach ($files as $f) {
            if (! $f || ! $f->isValid()) {
                $failed[] = ['name' => '(unreadable)', 'message' => 'The upload did not arrive intact.'];
                continue;
            }
            $bytes = @file_get_contents($f->getRealPath());
            if ($bytes === false) {
                $failed[] = ['name' => $f->getClientOriginalName(), 'message' => 'Could not read the file.'];
                continue;
            }
            $r = EvidenceService::attach(
                (int) $t->id, $type, $id, $bytes, (string) $f->getClientOriginalName(),
                $kind, (int) $u->id, (string) ($u->name ?? ''), $note ?: null
            );
            if ($r['ok']) {
                $saved[] = ['id' => (int) $r['attachment']->id, 'name' => (string) $r['attachment']->original_name];
            } else {
                $failed[] = ['name' => $f->getClientOriginalName(), 'message' => (string) ($r['message'] ?? 'Rejected.')];
            }
        }

        return response()->json([
            'ok' => $saved !== [], 'saved' => $saved, 'failed' => $failed,
            'message' => $saved === [] ? ((string) ($failed[0]['message'] ?? 'Nothing was attached.'))
                : (count($saved) . ' attached' . ($failed ? (', ' . count($failed) . ' rejected') : '')),
        ], $saved === [] ? 422 : 200);
    }

    /**
     * Stream one document. The id is resolved INSIDE the tenant's own rows, so no path ever
     * comes from the request and one business can never read another's evidence.
     */
    public function file(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response('Owner access required.', 403);
        }
        $a = Attachment::where('tenant_id', $t->id)->where('id', (int) $request->query('id'))->first();
        if (! $a) {
            return response('Not found.', 404);
        }
        if (! Storage::disk('public')->exists($a->path)) {
            return response('That document is no longer in storage.', 404);
        }

        return response(Storage::disk('public')->get($a->path), 200, [
            'Content-Type'        => (string) ($a->mime ?: 'application/octet-stream'),
            'Content-Disposition' => ((string) $request->query('dl') === '1' ? 'attachment' : 'inline')
                . '; filename="' . EvidenceService::safeName((string) $a->original_name) . '"',
            'Cache-Control'       => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function detach(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $a = Attachment::where('tenant_id', $t->id)->where('id', (int) $request->input('id'))->whereNull('detached_at')->first();
        if (! $a) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }
        $u = $request->user();
        EvidenceService::detach($a, (int) $u->id, (string) ($u->name ?? ''), (string) $request->input('reason', ''));

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ the page

    private function shell(string $notice, string $csrf): string
    {
        $body = $notice !== '' ? $notice : '<div id="app"><div class="empty">Loading…</div></div>';

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{$csrf}">
<title>Evidence</title>
<style>
 :root{--line:#E5E7EB;--muted:#6B7280;--ink:#111827;--ok:#14532D;--okbg:#ECFDF5;--warn:#B45309;--bad:#B91C1C}
 *{box-sizing:border-box}
 body{margin:0;background:#F7F8F7;color:var(--ink);font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif}
 .wrap{max-width:1060px;margin:0 auto;padding:16px}
 h1{font-size:20px;margin:0 0 2px} .lead{color:var(--muted);margin:0 0 14px}
 .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:10px}
 .kpis{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
 .kpi{background:#fff;border:1px solid var(--line);border-radius:12px;padding:11px 15px;min-width:150px}
 .kpi b{display:block;font-size:21px;line-height:1.2} .kpi span{color:var(--muted);font-size:12.5px}
 .chips{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}
 .chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;font-size:13px;cursor:pointer}
 .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}
 .btn{border:1px solid var(--line);background:#fff;border-radius:9px;padding:7px 12px;font-size:13px;cursor:pointer;font-weight:600}
 .btn.pri{background:var(--ok);color:#fff;border-color:var(--ok)} .btn.gh{color:var(--muted)}
 table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
 th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:9px 11px;border-bottom:1px solid var(--line)}
 td{padding:9px 11px;border-bottom:1px solid var(--line);vertical-align:top}
 tr:last-child td{border-bottom:0}
 .amt{text-align:right;font-weight:700;white-space:nowrap}
 .miss{color:var(--bad);font-weight:700} .has{color:var(--ok);font-weight:700}
 .sub{color:var(--muted);font-size:12px}
 .docs{margin-top:7px;display:flex;gap:6px;flex-wrap:wrap}
 .doc{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:8px;padding:3px 8px;font-size:12px;background:#FAFAFA}
 .doc a{color:#2563EB;text-decoration:none}
 .empty{text-align:center;color:var(--muted);padding:44px 10px}
 #sheet{display:none;position:fixed;inset:0;background:rgba(17,24,39,.45);align-items:center;justify-content:center;padding:14px;z-index:50}
 .sheetbox{background:#fff;border-radius:14px;padding:16px;max-width:430px;width:100%;box-shadow:0 10px 40px rgba(0,0,0,.2)}
 .sheetbox h3{margin:0 0 3px;font-size:16px}
 .sheetbox input[type=text]{width:100%;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;margin:6px 0 12px}
 .files{max-height:74px;overflow:auto;margin-bottom:4px}
 .msg{padding:9px 12px;border-radius:9px;margin-bottom:10px;display:none}
 .msg.ok{background:var(--okbg);color:var(--ok);display:block}
 .msg.err{background:#FEF2F2;color:var(--bad);display:block}
 input[type=date],select,input[type=number]{border:1px solid var(--line);border-radius:8px;padding:6px 9px;font-size:13px}
 code{background:#F3F4F6;padding:1px 5px;border-radius:4px}
</style></head><body><div class="wrap">
<h1>Evidence</h1>
<div class="lead">Every payment, and the receipt or invoice behind it. Attach a photo or PDF to any line — nothing is ever blocked for want of a document, but what is missing is visible here.</div>
<div class="msg" id="msg"></div>
{$body}
</div>
<div id="sheet"></div>
<input type="file" id="picker" multiple accept="image/*,application/pdf" style="display:none">
<script>
const CSRF=document.querySelector('meta[name=csrf-token]').content;
let F={only:'missing',min:0,from:'',to:''}, DATA=null, TARGET=null, OPEN={};
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const q=o=>Object.keys(o).filter(k=>o[k]!==''&&o[k]!=null).map(k=>k+'='+encodeURIComponent(o[k])).join('&');
const money=n=>'\$'+Number(n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
function msg(t,cls){const m=document.getElementById('msg');if(!m)return;m.className='msg '+(cls||'ok');m.textContent=t;
  if(cls!=='err')setTimeout(()=>{m.className='msg';},4500);}

async function load(){
  const app=document.getElementById('app'); if(!app)return;
  const r=await fetch('/papi/evidence-list?'+q(F),{headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>null);
  if(!r||!r.ok){app.innerHTML='<div class="empty">Could not load. Are you still signed in?</div>';return;}
  DATA=r; render();
}
function chip(label,on,onclick){return '<button class="chip'+(on?' on':'')+'" onclick="'+onclick+'">'+esc(label)+'</button>';}
function render(){
  const d=DATA,s=d.summary||{},app=document.getElementById('app');
  let h='<div class="kpis">'+
    '<div class="kpi"><b class="'+(s.missing?'miss':'has')+'">'+(s.missing||0)+'</b><span>without a document</span></div>'+
    '<div class="kpi"><b>'+money(s.missing_usd)+'</b><span>value with no evidence</span></div>'+
    '<div class="kpi"><b class="has">'+(s.with_evidence||0)+'</b><span>documented</span></div>'+
    '<div class="kpi"><b>'+(s.documents||0)+'</b><span>documents in the archive</span></div></div>';

  h+='<div class="card"><div class="chips">'+
      chip('Missing only',F.only==='missing',"setF('only','missing')")+
      chip('Everything',F.only==='all',"setF('only','all')")+
    '</div><div class="chips" style="align-items:center">'+
      '<span class="sub">Only above</span> <input type="number" min="0" step="10" value="'+(F.min||0)+'" onchange="setF(\\'min\\',this.value)" style="width:96px"> <span class="sub">USD</span>'+
      '<span class="sub" style="margin-left:10px">From</span> <input type="date" value="'+esc(F.from)+'" onchange="setF(\\'from\\',this.value)">'+
      '<span class="sub">to</span> <input type="date" value="'+esc(F.to)+'" onchange="setF(\\'to\\',this.value)">'+
    '</div></div>';

  if(!(d.rows||[]).length){
    h+='<div class="empty"><h2>'+(F.only==='missing'?'Nothing missing':'No transactions here')+'</h2><p>'+
       (F.only==='missing'?'Every payment in this range has a document attached.':'Try widening the dates.')+'</p></div>';
    app.innerHTML=h; return;
  }

  h+='<table><thead><tr><th>Date</th><th>What</th><th>Who</th><th class="amt">Amount</th><th>Evidence</th><th></th></tr></thead><tbody>';
  h+=(d.rows||[]).map(r=>{
    const key=r.type+':'+r.id;
    const docs=OPEN[key];
    return '<tr><td class="sub" style="white-space:nowrap">'+esc(r.date||'')+'</td>'+
      '<td><b>'+esc(r.what)+'</b>'+(r.note?'<div class="sub">'+esc(r.note)+'</div>':'')+'</td>'+
      '<td>'+esc(r.party||'—')+'</td>'+
      '<td class="amt">'+money(r.usd)+(r.currency&&r.currency!=='USD'?('<div class="sub">'+esc(r.currency)+' '+Number(r.native||0).toLocaleString()+'</div>'):'')+'</td>'+
      '<td>'+(r.docs>0?('<span class="has">'+r.docs+' document'+(r.docs>1?'s':'')+'</span>')
                     :('<span class="miss">none</span>'+(r.legacy_receipt?'<div class="sub">an old receipt file exists</div>':'')))+
        (docs?('<div class="docs">'+docs.map(x=>
            '<span class="doc"><a href="/papi/evidence-file?id='+x.id+'" target="_blank">'+esc(x.name)+'</a>'+
            '<span class="sub">'+esc(x.label)+(x.size?(' · '+esc(x.size)):'')+'</span>'+
            '<a href="#" onclick="detach('+x.id+',\\''+key+'\\');return false" title="Remove" style="color:#B91C1C">×</a></span>').join('')+'</div>'):'')+
      '</td>'+
      '<td style="white-space:nowrap;text-align:right">'+
        '<button class="btn pri" onclick="pick(\\''+r.type+'\\','+r.id+')">Attach</button> '+
        (r.docs>0?'<button class="btn gh" onclick="toggle(\\''+r.type+'\\','+r.id+')">'+(docs?'Hide':'View')+'</button>':'')+
      '</td></tr>';
  }).join('');
  h+='</tbody></table>';
  // Never let a page of results read as if it were the whole set.
  if(s.capped) h+='<div class="sub" style="padding:9px 2px">Showing the '+(s.shown||0)+' largest by value. '+
    'The counts above cover every transaction in this range — narrow the dates or raise the minimum to work through the rest.</div>';
  app.innerHTML=h;
}
function setF(k,v){F[k]=v;load();}
async function toggle(type,id){
  const key=type+':'+id;
  if(OPEN[key]){delete OPEN[key];render();return;}
  const r=await fetch('/papi/evidence-docs?type='+encodeURIComponent(type)+'&id='+id,{headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>null);
  if(r&&r.ok){OPEN[key]=r.docs||[];render();}
}
function pick(type,id){
  TARGET={type:type,id:id};
  const p=document.getElementById('picker'); p.value=''; p.click();
}
document.getElementById('picker').addEventListener('change',function(){
  if(!TARGET||!this.files.length)return;
  askKind(Array.prototype.slice.call(this.files));
});

// What kind of document this is decides how useful the archive is later, so it is chosen by
// tapping, not by typing a slug into a browser prompt. Anything unrecognised the server files
// under "Other" rather than refusing the upload.
const FALLBACK_KINDS={receipt:'Receipt',invoice:'Supplier invoice',delivery_note:'Delivery note',
  voucher:'Payment voucher',contract:'Contract / agreement',other:'Other'};
let CHOSEN='receipt';
function closeSheet(){ const d=document.getElementById('sheet'); d.style.display='none'; d.innerHTML=''; }
function askKind(files){
  CHOSEN='receipt';
  const kinds=(DATA&&DATA.kinds)?DATA.kinds:FALLBACK_KINDS;
  // Escape each name BEFORE joining, or the separator gets escaped along with them.
  const names=files.map(function(f){ return esc(f.name)+' <span class="sub">'+Math.round(f.size/1024)+' KB</span>'; }).join('<br>');
  const d=document.getElementById('sheet');
  d.innerHTML='<div class="sheetbox">'+
    '<h3>What is this document?</h3>'+
    '<div class="files">'+names+'</div>'+
    '<div class="chips" id="kindchips">'+Object.keys(kinds).map(function(k){
        return '<button type="button" class="chip'+(k==='receipt'?' on':'')+'" data-k="'+k+'">'+esc(kinds[k])+'</button>';
      }).join('')+'</div>'+
    '<input type="text" id="notebox" placeholder="Note (optional) — invoice number, who was paid…">'+
    '<div style="text-align:right"><button type="button" class="btn gh" id="cancelb">Cancel</button> '+
    '<button type="button" class="btn pri" id="okb">Attach '+files.length+' file'+(files.length>1?'s':'')+'</button></div></div>';
  d.style.display='flex';
  d.querySelectorAll('#kindchips .chip').forEach(function(b){
    b.onclick=function(){
      CHOSEN=b.getAttribute('data-k');
      d.querySelectorAll('#kindchips .chip').forEach(function(x){ x.className='chip'; });
      b.className='chip on';
    };
  });
  document.getElementById('cancelb').onclick=function(){ closeSheet(); TARGET=null; };
  document.getElementById('okb').onclick=function(){
    const note=(document.getElementById('notebox').value||'').trim();
    closeSheet(); send(files,CHOSEN,note);
  };
}
async function send(files,kind,note){
  if(!TARGET)return;
  const t=TARGET; TARGET=null;
  const fd=new FormData();
  fd.append('type',t.type); fd.append('id',t.id); fd.append('kind',kind||'receipt');
  if(note) fd.append('note',note);
  files.forEach(function(f){ fd.append('files[]',f); });
  msg('Uploading '+files.length+' file'+(files.length>1?'s':'')+'…');
  try{
    const r=await fetch('/papi/evidence-attach',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,Accept:'application/json'},body:fd}).then(r=>r.json());
    if(r&&r.ok){ if(r.failed&&r.failed.length) msg(r.message+' — '+r.failed[0].message,'err'); else msg(r.message||'Attached.'); }
    else { msg((r&&r.message)||'Could not attach.','err'); }
  }catch(e){ msg('Upload failed — is the file very large?','err'); }
  delete OPEN[t.type+':'+t.id];
  load();
}
async function detach(id,key){
  const reason=window.prompt('Remove this document?\\n\\nIt is kept in the audit trail with your name and reason — nothing is deleted.\\n\\nReason:','wrong document');
  if(reason===null)return;
  const fd=new FormData(); fd.append('id',id); fd.append('reason',reason);
  await fetch('/papi/evidence-detach',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,Accept:'application/json'},body:fd}).catch(()=>null);
  delete OPEN[key]; msg('Removed — kept in the audit trail.'); load();
}
load();
</script></body></html>
HTML;
    }
}
