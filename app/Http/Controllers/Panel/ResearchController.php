<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ProductSuggestion;
use App\Services\Research\SuggestionReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * v4.31 — the review screen for researched product details.
 *
 * v4.29 shipped the queue and a one-at-a-time modal in the LEGACY panel, which was
 * the wrong place twice over: this shop uses v3, and a dry run over 367 products
 * produced 835 suggestions. Approving those individually is not review, it is data
 * entry. So this screen groups by product, filters by field and source, and can
 * approve a whole filtered set at once.
 *
 *   GET  /papi/research           the page (embeds in the v3 shell)
 *   GET  /papi/research-list      JSON: summary + a page of products with their rows
 *   POST /papi/research-bulk      apply/dismiss by explicit ids, or by the active filter
 *
 * Every write goes through SuggestionReview, which re-checks the field is still empty.
 */
class ResearchController extends Controller
{
    private const PER_PAGE = 40;          // products per page, not rows

    private function gate(Request $request)
    {
        $u = $request->user();
        if (! $u || ! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return null;
        }

        return $u->tenant;
    }

    /** @return \Illuminate\Database\Eloquent\Builder */
    private function filtered(Request $r, int $tenantId)
    {
        $q = ProductSuggestion::where('tenant_id', $tenantId)->pending();

        // input() not query(): the list is a GET with a query string, the bulk action is a
        // POST with a JSON body, and both must resolve the SAME filter — otherwise
        // "Use all 46 showing" would quietly apply all 835.
        $field = trim((string) $r->input('field', ''));
        if ($field !== '' && in_array($field, (array) config('research.fields', []), true)) {
            $q->where('field', $field);
        }
        $source = trim((string) $r->input('source', ''));
        if (in_array($source, ['barcode', 'ai', 'written'], true)) {
            $q->where('source', $source);
        }
        $min = (float) $r->input('min', 0);
        if ($min > 0) {
            $q->where('confidence', '>=', $min);
        }

        return $q;
    }

    public function page(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response('Owner access required — sign in to the panel first.', 403);
        }
        if (! Schema::hasTable('product_suggestions')) {
            return response($this->shell(
                '<div class="empty"><h2>Not migrated yet</h2><p>Run <code>php artisan migrate</code> on the server, '
                . 'then reload. The suggestions table has not been created.</p></div>', csrf_token()
            ))->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response($this->shell('', csrf_token()))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Summary + one page of products, each with its pending rows. */
    public function list(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if (! Schema::hasTable('product_suggestions')) {
            return response()->json(['ok' => true, 'summary' => ['total' => 0], 'products' => [], 'note' => 'migration_pending']);
        }

        $summary = SuggestionReview::summary((int) $t->id);
        $q = $this->filtered($request, (int) $t->id);

        $matching = (clone $q)->count();
        $page = max(1, (int) $request->input('page', 1));

        // Page by PRODUCT so a product's two or three fields are always decided together.
        $ids = (clone $q)->select('product_id')->distinct()->orderBy('product_id')
            ->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->pluck('product_id')->all();
        $totalProducts = (clone $q)->distinct('product_id')->count('product_id');

        $rows = $ids
            ? (clone $q)->whereIn('product_id', $ids)->with('product:id,name,category,image_url')
                ->orderBy('product_id')->orderByDesc('confidence')->get()
            : collect();

        $byProduct = [];
        foreach ($rows as $s) {
            $pid = (int) $s->product_id;
            if (! isset($byProduct[$pid])) {
                $byProduct[$pid] = [
                    'product_id' => $pid,
                    'name'       => (string) ($s->product->name ?? '(deleted product)'),
                    'category'   => (string) ($s->product->category ?? ''),
                    'image'      => (string) ($s->product->image_url ?? ''),
                    'rows'       => [],
                ];
            }
            $byProduct[$pid]['rows'][] = [
                'id'         => (int) $s->id,
                'field'      => (string) $s->field,
                'label'      => ProductSuggestion::label((string) $s->field),
                'suggested'  => (string) $s->suggested_value,
                'confidence' => round((float) $s->confidence, 2),
                'source'     => (string) $s->source,
                'note'       => (string) ($s->note ?? ''),
            ];
        }

        return response()->json([
            'ok' => true,
            'summary' => $summary,
            'matching' => $matching,
            'products' => array_values($byProduct),
            'page' => $page,
            'pages' => (int) ceil(max(1, $totalProducts) / self::PER_PAGE),
            'total_products' => $totalProducts,
            'fields' => (array) config('research.fields', []),
        ]);
    }

    /**
     * Bulk decide.
     *   ids=1,2,3        exactly those rows (what the reviewer can see)
     *   scope=filtered   everything currently matching the filters — the whole point of the screen
     *
     * Capped per request so one click cannot hold a web worker open indefinitely; the
     * response reports what is left so the page can loop and show progress honestly.
     */
    public function bulk(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if (! Schema::hasTable('product_suggestions')) {
            return response()->json(['ok' => false, 'error' => 'migration_pending'], 422);
        }

        $action = $request->input('action') === 'dismiss' ? 'dismiss' : 'apply';
        $userId = (int) $request->user()->id;
        $cap = 300;

        $idsRaw = (string) $request->input('ids', '');
        if (trim($idsRaw) !== '') {
            $ids = array_slice(array_filter(array_map('intval', explode(',', $idsRaw))), 0, $cap);
            $rows = ProductSuggestion::where('tenant_id', $t->id)->pending()->whereIn('id', $ids)->get();
            $left = 0;
        } else {
            $q = $this->filtered($request, (int) $t->id);
            $rows = (clone $q)->orderBy('id')->limit($cap)->get();
            $left = max(0, (clone $q)->count() - $rows->count());
        }

        if ($action === 'dismiss') {
            $n = SuggestionReview::dismissMany($rows, $userId);

            return response()->json(['ok' => true, 'action' => 'dismiss', 'dismissed' => $n, 'remaining' => $left]);
        }

        $res = SuggestionReview::applyMany($rows, $userId);
        \App\Support\BotTrace::log((int) $t->id, 'research-review', (string) $userId, 'research_bulk',
            'applied=' . $res['applied'] . ' skipped=' . $res['skipped'], 0);

        return response()->json(['ok' => true, 'action' => 'apply'] + $res + ['remaining' => $left]);
    }

    /** The page. Self-contained, same approach as /papi/tenders. */
    private function shell(string $notice, string $csrf): string
    {
        $n = $notice !== '' ? $notice : '<div id="app"><div class="empty">Loading…</div></div>';

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{$csrf}">
<title>Suggestions</title>
<style>
 :root{--line:#E5E7EB;--muted:#6B7280;--ink:#111827;--ok:#14532D;--okbg:#ECFDF5;--warn:#B45309}
 *{box-sizing:border-box}
 body{margin:0;background:#F7F8F7;color:var(--ink);font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif}
 .wrap{max-width:1000px;margin:0 auto;padding:16px}
 h1{font-size:20px;margin:0 0 2px} .lead{color:var(--muted);margin:0 0 14px}
 .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:10px}
 .chips{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}
 .chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;font-size:13px;cursor:pointer}
 .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}
 .btn{border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 13px;font-size:13.5px;cursor:pointer;font-weight:600}
 .btn.pri{background:var(--ok);color:#fff;border-color:var(--ok)}
 .btn.gh{color:var(--muted)} .btn:disabled{opacity:.5;cursor:default}
 .bar{position:sticky;top:0;z-index:5;background:#F7F8F7;padding:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--line)}
 .grow{flex:1}
 .prow{display:flex;gap:12px;align-items:flex-start;border-top:1px solid var(--line);padding:9px 0}
 .prow:first-child{border-top:0}
 .fl{width:150px;flex:none;color:var(--muted);font-size:12.5px}
 .fv{flex:1;font-weight:600;word-break:break-word}
 .meta{color:var(--muted);font-size:12px;font-weight:400;margin-top:2px}
 .src{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;margin-right:6px}
 .src.barcode{background:#ECFDF5;color:#14532D} .src.ai{background:#FEF3C7;color:#92400E}
 .src.written{background:#EEF2FF;color:#3730A3}
 .pname{font-weight:700;font-size:15px} .pcat{color:var(--muted);font-size:12px}
 .acts{display:flex;gap:6px;flex:none}
 .empty{text-align:center;color:var(--muted);padding:40px 10px}
 .msg{padding:9px 12px;border-radius:9px;margin-bottom:10px;display:none}
 .msg.ok{background:var(--okbg);color:var(--ok);display:block}
 .msg.err{background:#FEF2F2;color:#991B1B;display:block}
 .pg{display:flex;gap:8px;justify-content:center;padding:12px 0}
 code{background:#F3F4F6;padding:1px 5px;border-radius:4px}
</style></head><body><div class="wrap">
<h1>Suggestions</h1>
<div class="lead">Details the AI found but was not confident enough to fill in by itself. Every one of these is a field that was <b>empty</b> — approving can never overwrite something you typed.</div>
<div class="msg" id="msg"></div>
{$n}
</div>
<script>
const CSRF=document.querySelector('meta[name=csrf-token]').content;
let F={field:'',source:'',page:1}, DATA=null, BUSY=false;
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const q=o=>Object.keys(o).filter(k=>o[k]!=='' && o[k]!=null).map(k=>k+'='+encodeURIComponent(o[k])).join('&');
function msg(t,cls){const m=document.getElementById('msg');if(!m)return;m.className='msg '+(cls||'ok');m.textContent=t;
  if(cls!=='err')setTimeout(()=>{m.className='msg';},4000);}
async function load(){
  const app=document.getElementById('app'); if(!app)return;
  const r=await fetch('/papi/research-list?'+q(F),{headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>null);
  if(!r||!r.ok){app.innerHTML='<div class="empty">Could not load. Are you still signed in?</div>';return;}
  DATA=r; render();
}
function chip(label,on,onclick){return '<button class="chip'+(on?' on':'')+'" onclick="'+onclick+'">'+esc(label)+'</button>';}
function render(){
  const d=DATA, s=d.summary||{}, app=document.getElementById('app');
  if(!s.total){
    app.innerHTML='<div class="empty" style="text-align:left;max-width:660px;margin:0 auto">'+
      '<h2 style="text-align:center">Nothing waiting</h2>'+
      '<p>Either everything has been decided, or the catalogue has not been researched yet.</p>'+
      '<p><b>The most common reason:</b> the research command was run without <code>--apply</code>. '+
      'That is a dry run — it reports what it <i>would</i> do and saves nothing, so this screen stays empty.</p>'+
      '<p>To actually create the suggestions:</p>'+
      '<pre style="background:#111827;color:#fff;padding:11px 13px;border-radius:9px;overflow:auto">'+
      'php artisan products:research --tenant=17 --apply</pre>'+
      '<p>Add <code>--barcode-api</code> and the products that carry a real barcode fill themselves in '+
      'from fact instead of joining this queue. Add <code>--descriptions</code> to have descriptions written too.</p>'+
      '</div>';
    return;
  }
  let h='<div class="card"><b>'+s.total+'</b> suggestions across <b>'+(s.products||0)+'</b> products.';
  const bs=s.by_source||{};
  if(bs.barcode) h+=' <span class="src barcode">'+bs.barcode+' from barcodes</span>';
  if(bs.ai) h+=' <span class="src ai">'+bs.ai+' AI guesses</span>';
  if(bs.written) h+=' <span class="src written">'+bs.written+' written</span>';
  h+='<div class="chips">'+chip('All fields',!F.field,"setF('field','')")+
     (d.fields||[]).map(f=>chip(labelOf(f)+' ('+((s.by_field||{})[f]||0)+')',F.field===f,"setF('field','"+f+"')")).join('')+'</div>';
  h+='<div class="chips">'+chip('Any source',!F.source,"setF('source','')")+
     chip('Barcode only',F.source==='barcode',"setF('source','barcode')")+
     chip('AI guesses',F.source==='ai',"setF('source','ai')")+
     chip('Written',F.source==='written',"setF('source','written')")+'</div></div>';

  h+='<div class="bar"><div class="grow"><b>'+d.matching+'</b> showing'+
     (F.field||F.source?' (filtered)':'')+'</div>'+
     '<button class="btn pri" onclick="bulk(\\'apply\\')" '+(BUSY?'disabled':'')+'>Use all '+d.matching+'</button>'+
     '<button class="btn gh" onclick="bulk(\\'dismiss\\')" '+(BUSY?'disabled':'')+'>Skip all</button></div>';

  h+=(d.products||[]).map(p=>{
    const ids=p.rows.map(r=>r.id).join(',');
    return '<div class="card"><div style="display:flex;gap:10px;align-items:flex-start">'+
      '<div class="grow"><div class="pname">'+esc(p.name)+'</div>'+
      (p.category?'<div class="pcat">'+esc(p.category)+'</div>':'')+'</div>'+
      '<div class="acts"><button class="btn pri" onclick="act(\\''+ids+'\\',\\'apply\\')">Use all</button>'+
      '<button class="btn gh" onclick="act(\\''+ids+'\\',\\'dismiss\\')">Skip</button></div></div>'+
      p.rows.map(r=>'<div class="prow"><div class="fl">'+esc(r.label)+'</div>'+
        '<div class="fv">'+esc(r.suggested)+
        '<div class="meta"><span class="src '+esc(r.source)+'">'+
          (r.source==='barcode'?'from the barcode':(r.source==='written'?'written by AI':'AI guess'))+
        '</span>'+Math.round(r.confidence*100)+'% sure'+(r.note?' · '+esc(r.note):'')+'</div></div>'+
        '<div class="acts"><button class="btn" onclick="act(\\''+r.id+'\\',\\'apply\\')">Use</button>'+
        '<button class="btn gh" onclick="act(\\''+r.id+'\\',\\'dismiss\\')">Skip</button></div></div>').join('')+
    '</div>';
  }).join('');

  if(d.pages>1){
    h+='<div class="pg"><button class="btn" '+(d.page<=1?'disabled':'')+' onclick="go('+(d.page-1)+')">‹ Back</button>'+
       '<span style="padding:8px 4px;color:var(--muted)">Page '+d.page+' of '+d.pages+'</span>'+
       '<button class="btn" '+(d.page>=d.pages?'disabled':'')+' onclick="go('+(d.page+1)+')">Next ›</button></div>';
  }
  app.innerHTML=h;
}
function labelOf(f){return ({brand:'Brand',unit_size:'Size',origin_country:'Origin',category:'Category',description:'Description'})[f]||f;}
function setF(k,v){F[k]=v;F.page=1;load();}
function go(p){F.page=p;load();window.scrollTo(0,0);}
async function post(body){
  return fetch('/papi/research-bulk',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json',Accept:'application/json'},
    body:JSON.stringify(body)}).then(r=>r.json()).catch(()=>null);
}
async function act(ids,action){
  const r=await post({action:action,ids:String(ids)});
  if(!r||!r.ok){msg('Could not save that.','err');return;}
  msg(action==='apply'?('Applied '+(r.applied||0)+(r.skipped?(' · '+r.skipped+' already filled in'):'')):'Skipped.');
  load();
}
async function bulk(action){
  const n=DATA?DATA.matching:0;
  const what=(F.field||F.source)?'the '+n+' showing':'all '+n;
  if(!window.confirm(action==='apply'
      ? 'Fill in '+what+' suggestion'+(n===1?'':'s')+'?\\n\\nOnly fields that are still empty are written.'
      : 'Skip '+what+' suggestion'+(n===1?'':'s')+'? They will not come back until you research again.')) return;
  BUSY=true; render();
  let applied=0, skipped=0, guard=0;
  while(guard++ < 40){
    const r=await post({action:action,scope:'filtered',field:F.field,source:F.source});
    if(!r||!r.ok){msg('Stopped — could not save.','err');break;}
    applied+=(r.applied||r.dismissed||0); skipped+=(r.skipped||0);
    msg('Working… '+applied+' done'+(r.remaining?(' · '+r.remaining+' to go'):''));
    if(!r.remaining) break;
  }
  BUSY=false; F.page=1;
  msg(action==='apply'
    ? ('Filled in '+applied+(skipped?(' · '+skipped+' were already filled in'):''))
    : ('Skipped '+applied));
  load();
}
load();
</script></body></html>
HTML;
    }
}
