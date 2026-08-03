<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\Tender\TenderAnalyzer;
use App\Services\Tender\TenderScout;
use Illuminate\Http\Request;

/**
 * v4.19 TenderBot panel surface (self-contained page, same pattern as /papi/bike-photos):
 *   GET  /papi/tenders          owner page — drop tender files, see recent analyses, scan now
 *   POST /papi/tenders-analyze  multi-file upload → TenderAnalyzer → summary JSON
 *   POST /papi/tenders-scan     manual forum sweep (same code the daily schedule runs)
 *   POST /papi/tenders-status   update a tender's pipeline status
 */
class TenderController extends Controller
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
        $csrf = csrf_token();
        $enabled = (bool) $t->setting('tender_enabled', false);
        $watch = e((string) $t->setting('tender_watch_url', 'https://comms.southsudanngoforum.org/c/tenders/8'));

        $recent = Tender::where('tenant_id', $t->id)->orderByDesc('id')->limit(20)->get();

        // one grouped count instead of a query per row
        $itemCount = [];
        try {
            $itemCount = \App\Models\TenderItem::where('tenant_id', $t->id)
                ->whereIn('tender_id', $recent->pluck('id')->all())
                ->where('status', '!=', 'skip')
                ->selectRaw('tender_id, count(*) as n')->groupBy('tender_id')
                ->pluck('n', 'tender_id')->all();
        } catch (\Throwable $e) {
            $itemCount = [];
        }

        $rows = '';
        foreach ($recent as $td) {
            $days = $td->deadline ? now()->startOfDay()->diffInDays($td->deadline, false) : null;
            $dchip = $td->deadline
                ? ($days < 0 ? '<span class="chip bad">closed</span>'
                    : ($days <= 2 ? '<span class="chip crit">' . $days . 'd ⚠</span>'
                    : ($days <= 7 ? '<span class="chip warn">' . $days . 'd</span>' : '<span class="chip">' . $days . 'd</span>')))
                : '<span class="chip">no date</span>';
            $score = $td->opportunity_score !== null ? $td->opportunity_score . '%' : '—';
            $opts = '';
            foreach (self::STATUSES as $s) {
                $opts .= '<option value="' . $s . '"' . ($td->status === $s ? ' selected' : '') . '>' . $s . '</option>';
            }
            // v4.22 — the documents we downloaded and kept, with what could and could not be read.
            $docs = '';
            foreach ($this->fileList($td) as $f) {
                $meta = $f['size'] > 0 ? $this->human($f['size']) : '';
                if ($f['ocr_pages'] > 0) {
                    $meta .= ' · OCR ' . $f['ocr_pages'] . 'p';
                }
                $state = $f['parsed']
                    ? '<span class="chip green">read</span>'
                    : '<span class="chip warn">not read</span>';
                $link = $f['path'] !== ''
                    ? '<a href="/papi/tender-file?id=' . (int) $td->id . '&i=' . (int) $f['i'] . '" target="_blank" rel="noopener">' . e($f['name']) . '</a>'
                    : e($f['name']);
                $docs .= '<div class="drow">' . $state . $link
                    . '<span class="dmeta">' . e($meta . ($f['note'] !== '' ? ' · ' . $f['note'] : '')) . '</span></div>';
            }
            $docs = $docs === ''
                ? '<div class="dmeta">No documents stored for this tender.</div>'
                : $docs;

            $nItems = $itemCount[$td->id] ?? 0;
            $itemChip = $nItems > 0 ? '<span class="chip blue">' . $nItems . ' item(s) to price</span>' : '';

            $rows .= '<div class="trow" onclick="tog(this)">'
                . '<div class="tmain"><b>' . e((string) ($td->title ?: 'Untitled')) . '</b>'
                . '<span class="tsub">' . e((string) ($td->organization ?: $td->source)) . ' · ' . e((string) ($td->division ?: '')) . '</span></div>'
                . '<div class="tmeta"><span class="chip green">' . e($score) . '</span>'
                . '<span class="chip">' . e((string) ($td->recommendation ?: '—')) . '</span>' . $dchip
                . '<span class="chip st">' . e((string) $td->status) . '</span>' . $itemChip . '</div>'
                . '<pre class="tsum">' . e((string) ($td->summary ?: 'No summary.')) . '</pre>'
                . '<div class="tdocs"><h4>📎 Documents kept in the system</h4>' . $docs . '</div>'
                . '<div class="tact" onclick="event.stopPropagation()">'
                . '<select onchange="stSet(' . (int) $td->id . ',this.value)">' . $opts . '</select>'
                . '<button class="mini" onclick="rfqCopy(' . (int) $td->id . ',this)">📋 Copy supplier RFQ</button>'
                . ($nItems > 0 ? '<button class="mini" onclick="goItems(' . (int) $td->id . ')">🧾 Price its items</button>' : '')
                . '</div>'
                . '</div>';
        }
        if ($rows === '') {
            $rows = '<p style="color:#9CA3AF">No tenders analysed yet — drop the first one above, or press "Scan forum now".</p>';
        }
        // ── v4.22 the pricing board: every line still needing a price, newest deadline first ──
        $board = '';
        $boardCount = 0;
        try {
            $items = \App\Models\TenderItem::where('tenant_id', $t->id)
                ->whereIn('status', ['to_price', 'quoted', 'priced'])
                ->orderBy('tender_id')->orderBy('line')->limit(400)->get();
            $svc = app(\App\Services\Tender\TenderItems::class);
            $titles = $recent->pluck('title', 'id')->all();
            $deads = $recent->pluck('deadline', 'id')->all();
            $boardCount = $items->where('status', '!=', 'priced')->count();

            // group by tender so each one reads as its own bid sheet, with a running total
            $byTender = [];
            foreach ($items as $it) {
                $byTender[(int) $it->tender_id][] = $it;
            }

            foreach ($byTender as $gid => $group) {
                $gCost = 0.0;
                $gBid = 0.0;
                $gPriced = 0;
                $gCur = '';
                foreach ($group as $g) {
                    $lc = $g->lineCost();
                    $lb = $g->lineBid();
                    if ($lc !== null) {
                        $gCost += $lc;
                        $gPriced++;
                        $gCur = $gCur ?: (string) ($g->currency ?: '');
                    }
                    if ($lb !== null) {
                        $gBid += $lb;
                    }
                }
                $left = count($group) - $gPriced;
                $board .= '<h2 style="font-size:14px;margin:18px 0 6px">'
                    . e((string) ($titles[$gid] ?? ('Tender #' . $gid))) . '</h2>'
                    . '<div class="tot"><span>' . count($group) . ' line(s)</span>'
                    . '<span>' . ($left > 0 ? '<b>' . $left . '</b> still to price' : 'all priced ✓') . '</span>'
                    . ($gPriced > 0
                        ? '<span>cost <b>' . e(trim($gCur . ' ' . number_format($gCost, 2))) . '</b></span>'
                            . '<span>bid <b>' . e(trim($gCur . ' ' . number_format($gBid, 2))) . '</b></span>'
                            . '<span>profit <b>' . e(number_format($gBid - $gCost, 2)) . '</b>'
                            . ($gBid > 0 ? ' (' . round(($gBid - $gCost) / $gBid * 100, 1) . '%)' : '') . '</span>'
                            . ($left > 0 ? '<span style="color:#B45309">partial — ' . $left . ' line(s) not costed yet</span>' : '')
                        : '<span style="color:#9CA3AF">no prices captured yet</span>')
                    . '</div>';

            foreach ($group as $it) {
                $tid = (int) $it->tender_id;
                $ttl = (string) ($titles[$tid] ?? ('Tender #' . $tid));
                $dl = $deads[$tid] ?? null;
                $days = $dl ? now()->startOfDay()->diffInDays($dl, false) : null;
                $dchip2 = $days === null ? '' : ($days < 0
                    ? '<span class="chip bad">closed</span>'
                    : ($days <= 2 ? '<span class="chip crit">' . $days . 'd ⚠</span>'
                        : ($days <= 7 ? '<span class="chip warn">' . $days . 'd</span>' : '<span class="chip">' . $days . 'd</span>')));

                $mem = $it->unit_cost === null ? $svc->priceMemory($t, (string) $it->item, (int) $it->id) : null;
                $memHtml = $mem
                    ? '<div class="mem">💡 last paid <b>' . e(trim($mem['currency'] . ' ' . rtrim(rtrim(number_format($mem['unit_cost'], 2), '0'), '.'))) . '</b>'
                        . ($mem['supplier'] !== '' ? ' — ' . e($mem['supplier']) : '')
                        . ($mem['when'] !== '' ? ' (' . e($mem['when']) . ')' : '')
                        . ' <a href="#" onclick="useMem(' . (int) $it->id . ',' . (float) $mem['unit_cost'] . ',this);return false">use</a></div>'
                    : '';

                $lineBid = $it->lineBid();
                $bidHtml = $lineBid !== null
                    ? '<div class="bid">bid <b>' . e(number_format((float) $it->bid_unit, 2)) . '</b>/' . e((string) ($it->unit ?: 'unit'))
                        . ' → <b>' . e(number_format($lineBid, 2)) . '</b> total</div>'
                    : '';

                $sopts = '';
                foreach (\App\Services\Tender\TenderItems::STATUSES as $s) {
                    $sopts .= '<option value="' . $s . '"' . ($it->status === $s ? ' selected' : '') . '>' . str_replace('_', ' ', $s) . '</option>';
                }

                $board .= '<div class="irow' . ($it->status === 'priced' ? ' done' : '') . '" id="i' . (int) $it->id . '">'
                    . '<div class="ihead"><b>' . e((string) $it->item) . '</b>'
                    . '<span class="iqty">' . e((string) ($it->qty ?: '—')) . '</span></div>'
                    . '<div class="isub">' . $dchip2
                    . ($it->lot ? ' · <span class="chip">' . e((string) $it->lot) . '</span>' : '')
                    . ($it->spec ? ' · ' . e(mb_substr((string) $it->spec, 0, 120)) : '')
                    . ($it->brand ? ' · brand: ' . e((string) $it->brand) : '')
                    . '</div>'
                    . $memHtml
                    . '<div class="iform">'
                    . '<input class="pin" id="c' . (int) $it->id . '" placeholder="unit cost" value="' . ($it->unit_cost !== null ? e(rtrim(rtrim(number_format((float) $it->unit_cost, 4, '.', ''), '0'), '.')) : '') . '">'
                    . '<input class="pin cur" id="u' . (int) $it->id . '" placeholder="cur" value="' . e((string) ($it->currency ?: '')) . '">'
                    . '<input class="pin sup" id="s' . (int) $it->id . '" placeholder="supplier" value="' . e((string) ($it->supplier ?: '')) . '">'
                    . '<select class="pin st2" id="t' . (int) $it->id . '">' . $sopts . '</select>'
                    . '<button class="mini" onclick="savePrice(' . (int) $it->id . ',this)">Save</button>'
                    . '</div>' . $bidHtml
                    . '</div>';
            }
            }
        } catch (\Throwable $e) {
            \Log::warning('Tender pricing board failed: ' . $e->getMessage());
            $board = '<p style="color:#B45309">The pricing board could not be built. If this is the first run after an update, the new table is missing — run <code>php artisan migrate</code>.</p>';
        }
        if ($board === '') {
            $board = '<p style="color:#9CA3AF">Nothing to price yet. Analyse a tender that contains a product list (BOQ) and every line lands here.</p>';
        }

        $enabledNote = $enabled ? ''
            : '<p style="background:#FEF3C7;border-radius:8px;padding:8px 12px;font-size:12.5px">Automatic daily scanning is OFF — turn on <b>Settings → Storefront → Tenders (TenderBot) → Watch for tenders</b> to have TenderBot check the forum twice a day by itself (that also puts <b>Tenders</b> in your left menu).</p>';
        // v4.21 — when the v3 shell embeds this page in an iframe, the back-link must move the WHOLE
        // window (target=_top), or Settings would open inside the frame.
        $backLink = '<p style="margin-top:10px"><a href="/v3#/settings" target="_top">← Settings</a></p>';

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TenderBot — CloudBSS</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#F6F7F8;color:#111827;margin:0;padding:24px}
.card{max-width:860px;margin:0 auto 16px;background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px 24px}
h1{font-size:20px;margin:0 0 4px} p{font-size:13px;color:#6B7280;line-height:1.5;margin:6px 0}
.drop{margin:12px 0;border:2px dashed #CFE6D8;border-radius:12px;background:#F8FBF9;padding:22px;text-align:center;cursor:pointer;font-size:14px}
.drop.on{border-color:#14532D;background:#EAF5EE}
.btn{display:inline-block;background:#14532D;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer}
.btn.o{background:#fff;color:#14532D;border:1px solid #CFE6D8}
.btn[disabled]{opacity:.5;cursor:default}
#msg{margin-top:10px;font-size:13.5px;font-weight:600;white-space:pre-wrap}
.ok{color:#14532D}.bad{color:#B45309}
.trow{border:1px solid #E5E7EB;border-radius:10px;padding:10px 14px;margin:8px 0;cursor:pointer}
.tmain b{font-size:13.5px}.tsub{display:block;font-size:11.5px;color:#9CA3AF;margin-top:2px}
.tmeta{margin-top:6px}
.chip{display:inline-block;background:#F3F4F6;color:#374151;border-radius:8px;font-size:10.5px;font-weight:600;padding:2px 8px;margin-right:5px}
.chip.green{background:#EAF5EE;color:#14532D}.chip.warn{background:#FEF3C7;color:#92400E}
.chip.crit{background:#FEE2E2;color:#B91C1C}.chip.bad{background:#F3F4F6;color:#9CA3AF}.chip.st{background:#EFF6FF;color:#1D4ED8}
.tsum{display:none;background:#F9FAFB;border-radius:8px;padding:10px;font-size:12px;white-space:pre-wrap;font-family:inherit;margin:8px 0 0}
.trow.open .tsum{display:block}
.tact{display:none;margin-top:8px}
.trow.open .tact{display:flex;gap:8px;align-items:center}
.tact select{font-size:12px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:8px;background:#fff}
.mini{background:#fff;color:#14532D;border:1px solid #CFE6D8;border-radius:8px;padding:5px 10px;font-size:12px;font-weight:600;cursor:pointer}
a{color:#14532D}
.chip.blue{background:#EFF6FF;color:#1D4ED8}
/* tabs */
.tabs{display:flex;gap:8px;margin:0 0 14px}
.tab{background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:8px 14px;font-size:13.5px;font-weight:600;color:#374151;cursor:pointer}
.tab.on{background:#14532D;border-color:#14532D;color:#fff}
.pane{display:none}.pane.on{display:block}
/* documents */
.tdocs{display:none;margin-top:10px;border-top:1px solid #F3F4F6;padding-top:8px}
.trow.open .tdocs{display:block}
.tdocs h4{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:#9CA3AF;margin:0 0 6px}
.drow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12.5px;padding:4px 0}
.dmeta{color:#9CA3AF;font-size:11.5px}
/* pricing board */
.irow{border:1px solid #E5E7EB;border-radius:10px;padding:10px 14px;margin:8px 0}
.irow.done{background:#F8FBF9;border-color:#CFE6D8}
.ihead{display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.ihead b{font-size:13.5px}
.iqty{font-size:12.5px;font-weight:700;color:#14532D;white-space:nowrap}
.isub{font-size:11.5px;color:#9CA3AF;margin-top:2px}
.mem{font-size:12px;color:#92400E;background:#FEF9EC;border-radius:8px;padding:4px 8px;margin-top:6px;display:inline-block}
.iform{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.pin{font-size:12.5px;padding:6px 8px;border:1px solid #E5E7EB;border-radius:8px;width:110px}
.pin.cur{width:60px}.pin.sup{width:150px}.pin.st2{width:110px;background:#fff}
.bid{font-size:12.5px;color:#14532D;margin-top:6px}
.tot{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;background:#F8FBF9;border:1px solid #CFE6D8;border-radius:10px;padding:10px 14px;margin-bottom:10px}
.tot b{color:#14532D}
</style></head><body>
<div class="card">
  <h1>🏆 TenderBot — Senior Procurement &amp; Bid Manager</h1>
  <p>Drop every file of a tender (PDF, Word, Excel, ZIP — all at once). TenderBot reads them, scores the opportunity, checks compliance, lists the products and quotations needed, and sends the executive summary to your WhatsApp.</p>
  {$enabledNote}
  <div class="drop" id="drop">Drop tender files here, or click to choose<input type="file" id="file" multiple style="display:none"></div>
  <button class="btn" id="go" disabled>Analyse tender</button>
  <button class="btn o" id="scan">Scan forum now</button>
  <span style="font-size:11.5px;color:#9CA3AF"> watching: {$watch}</span>
  <div id="msg"></div>
  {$backLink}
</div>
<div class="card" style="padding-bottom:2px">
  <div class="tabs">
    <button class="tab on" id="tabT" onclick="showTab('T')">📋 Tenders</button>
    <button class="tab" id="tabI" onclick="showTab('I')">🧾 Items to price ({$boardCount})</button>
  </div>
</div>
<div class="card pane on" id="paneT"><h1 style="font-size:16px">Recent tenders</h1>{$rows}</div>
<div class="card pane" id="paneI">
  <h1 style="font-size:16px">Items to price</h1>
  <p>Every product line from every open tender, in one place. Type what your supplier charges you — the bid price is worked out from your margin, and the next tender that asks for the same item will remind you what you paid.
  <a href="/papi/tender-items.csv" target="_blank" rel="noopener">Download as CSV</a></p>
  {$board}
</div>
<script>
var F=[],drop=document.getElementById('drop'),fi=document.getElementById('file'),go=document.getElementById('go'),scan=document.getElementById('scan'),msg=document.getElementById('msg');
function pick(list){ if(!list||!list.length) return; F=Array.prototype.slice.call(list,0,15);
  drop.textContent='📎 '+F.length+' file(s): '+F.map(function(f){return f.name}).join(', ').slice(0,140); go.disabled=false; }
drop.onclick=function(){ fi.click(); };
fi.onchange=function(){ pick(fi.files); };
drop.ondragover=function(e){ e.preventDefault(); drop.classList.add('on'); };
drop.ondragleave=function(){ drop.classList.remove('on'); };
drop.ondrop=function(e){ e.preventDefault(); drop.classList.remove('on'); pick(e.dataTransfer.files); };
function tog(el){ el.classList.toggle('open'); }
function showTab(k){
  ['T','I'].forEach(function(x){
    document.getElementById('tab'+x).classList.toggle('on', x===k);
    document.getElementById('pane'+x).classList.toggle('on', x===k);
  });
  try{ location.hash = k==='I' ? '#items' : ''; }catch(e){}
}
if(location.hash==='#items') showTab('I');
function goItems(id){ showTab('I'); var el=document.querySelector('#paneI .irow'); if(el) el.scrollIntoView({block:'center'}); }
function useMem(id, cost, a){ var c=document.getElementById('c'+id); if(c){ c.value=cost; c.focus(); } var r=a.closest('.mem'); if(r) r.style.opacity=.5; }
function savePrice(id, btn){
  var body={ id:id,
    unit_cost:(document.getElementById('c'+id)||{}).value||'',
    currency:(document.getElementById('u'+id)||{}).value||'',
    supplier:(document.getElementById('s'+id)||{}).value||'',
    status:(document.getElementById('t'+id)||{}).value||'' };
  btn.disabled=true; var old=btn.textContent; btn.textContent='Saving…';
  fetch('/papi/tender-item-save',{method:'POST',credentials:'same-origin',
    headers:{'X-CSRF-TOKEN':'{$csrf}','Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(function(r){return r.json();})
    .then(function(j){
      btn.disabled=false; btn.textContent=old;
      if(!j.ok){ msg.className='bad'; msg.textContent=j.message||'Could not save.'; return; }
      var row=document.getElementById('i'+id);
      if(row){
        row.classList.toggle('done', j.status==='priced');
        var b=row.querySelector('.bid');
        if(j.bid_line){
          if(!b){ b=document.createElement('div'); b.className='bid'; row.appendChild(b); }
          b.innerHTML='bid <b>'+j.bid_unit+'</b>/'+(j.unit||'unit')+' → <b>'+j.bid_line+'</b> total';
        }
      }
      msg.className='ok'; msg.textContent='Saved'+(j.bid_line?(' — bid '+j.bid_line+' for this line'):'')+'.';
    })
    .catch(function(){ btn.disabled=false; btn.textContent=old; msg.className='bad'; msg.textContent='Could not save.'; });
}
function stSet(id,st){
  fetch('/papi/tenders-status',{method:'POST',credentials:'same-origin',
    headers:{'X-CSRF-TOKEN':'{$csrf}','Content-Type':'application/json'},body:JSON.stringify({id:id,status:st})})
    .then(function(r){return r.json();})
    .then(function(j){ msg.className=j.ok?'ok':'bad'; msg.textContent=j.ok?('Status → '+st):'Could not update status.'; });
}
function rfqCopy(id,btn){
  fetch('/papi/tenders-rfq',{method:'POST',credentials:'same-origin',
    headers:{'X-CSRF-TOKEN':'{$csrf}','Content-Type':'application/json'},body:JSON.stringify({id:id})})
    .then(function(r){return r.json();})
    .then(function(j){
      if(!j.ok){ msg.className='bad'; msg.textContent=j.message||'No RFQ available.'; return; }
      function done(){ btn.textContent='✓ Copied — paste to your supplier on WhatsApp'; setTimeout(function(){btn.textContent='📋 Copy supplier RFQ';},3500); }
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(j.text).then(done,function(){fallback(j.text);done();}); }
      else { fallback(j.text); done(); }
      function fallback(t){ var ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');}catch(e){} document.body.removeChild(ta); }
    });
}
go.onclick=function(){
  if(!F.length) return; go.disabled=true; go.textContent='Analysing… (30–90s)';
  var fd=new FormData(); F.forEach(function(f){ fd.append('files[]',f); });
  fetch('/papi/tenders-analyze',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-TOKEN':'{$csrf}'}})
    .then(function(r){return r.json();})
    .then(function(j){ go.textContent='Analyse tender'; go.disabled=false;
      msg.className=j.ok?'ok':'bad'; msg.textContent=j.ok?('✅ Analysed — summary sent to WhatsApp.\\n\\n'+(j.summary||'')):(j.message||'Failed');
      if(j.ok) setTimeout(function(){location.reload();},4000); })
    .catch(function(){ go.textContent='Analyse tender'; go.disabled=false; msg.className='bad'; msg.textContent='Upload failed — try again.'; });
};
scan.onclick=function(){
  scan.disabled=true; scan.textContent='Scanning forum…';
  fetch('/papi/tenders-scan',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-TOKEN':'{$csrf}'}})
    .then(function(r){return r.json();})
    .then(function(j){ scan.disabled=false; scan.textContent='Scan forum now';
      msg.className=j.ok?'ok':'bad'; msg.textContent=j.message||'';
      if(j.ok&&j.analysed) setTimeout(function(){location.reload();},2500); })
    .catch(function(){ scan.disabled=false; scan.textContent='Scan forum now'; msg.className='bad'; msg.textContent='Scan failed — try again.'; });
};
</script>
</body></html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
    }

    public function analyze(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'message' => 'Owner access required.'], 403);
        }
        @set_time_limit(280);
        $files = [];
        foreach ((array) $request->file('files', []) as $f) {
            if (! $f || ! $f->isValid()) {
                continue;
            }
            $files[] = ['name' => (string) $f->getClientOriginalName(), 'bytes' => (string) file_get_contents($f->getRealPath())];
        }
        if (! $files) {
            return response()->json(['ok' => false, 'message' => 'No readable files received.'], 422);
        }
        $tender = app(TenderAnalyzer::class)->analyze($t, $files, 'upload', null, 'Uploaded in panel (' . count($files) . ' files)');
        if (! $tender) {
            return response()->json(['ok' => false, 'message' => 'Analysis failed — check that the AI key is configured, then try again.'], 500);
        }
        // v4.20 — action items become real ops tasks (WhatsApp nudge + reminders for free)
        $nTasks = 0;
        try {
            $nTasks = app(\App\Services\Tender\TenderTasks::class)->forTender($t, $tender);
        } catch (\Throwable $e) {
        }
        // v4.22 — and every BOQ line lands on the pricing board
        $nItems = 0;
        try {
            $nItems = app(\App\Services\Tender\TenderItems::class)->sync($t, $tender);
        } catch (\Throwable $e) {
        }
        if ($tender->summary && $tender->status !== 'failed' && (bool) $t->setting('tender_notify', true)) {
            try {
                \App\Jobs\NotifyOwner::dispatch($t->id, $tender->summary
                    . ($nTasks > 0 ? "\n\n📋 {$nTasks} task(s) created for your team — check the task inbox." : '')
                    . ($nItems > 0 ? "\n🧾 {$nItems} item(s) added to the pricing board." : ''));
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => $tender->status !== 'failed', 'id' => $tender->id,
            'summary' => (string) $tender->summary
                . ($nTasks > 0 ? "\n\n📋 {$nTasks} task(s) created." : '')
                . ($nItems > 0 ? "\n🧾 {$nItems} item(s) on the pricing board — open the “Items to price” tab." : ''),
            'message' => $tender->status === 'failed' ? (string) $tender->summary : 'Analysed.']);
    }

    public function scanNow(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'message' => 'Owner access required.'], 403);
        }
        @set_time_limit(280);
        $res = (new TenderScout())->scan($t);
        $n = count($res['analysed']);

        return response()->json(['ok' => true, 'analysed' => $n,
            'message' => $n > 0
                ? '✅ ' . $n . ' new tender(s) analysed: ' . implode(' · ', $res['analysed']) . ' — summaries sent to WhatsApp.'
                : 'Scan done — ' . $res['scanned'] . ' recent forum posts checked, nothing new to analyse. ' . $res['note']]);
    }

    /** v4.20 — the tender lifecycle (reviewer's "first-class business object"). */
    public const STATUSES = ['new', 'reviewing', 'qualified', 'pricing', 'proposal', 'approval',
        'submitted', 'evaluation', 'won', 'lost', 'ignored', 'cancelled'];

    public function setStatus(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false], 403);
        }
        $data = $request->validate(['id' => 'required|integer', 'status' => 'required|string|in:' . implode(',', self::STATUSES)]);
        $n = Tender::where('tenant_id', $t->id)->where('id', (int) $data['id'])->update(['status' => $data['status']]);
        if ($n > 0) {
            try {
                \App\Support\BotTrace::log($t->id, 'tender_status', 'panel', (string) $data['id'], $data['status']);
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => $n > 0]);
    }

    /**
     * v4.22 — normalise a tender's stored attachments.
     *
     * v4.19–v4.21 stored a plain array of storage paths; v4.22 stores {path,name,size,parsed,note}.
     * Old rows must keep working, so both shapes are read here and presented identically.
     *
     * @return array<int,array{i:int,path:string,name:string,size:int,parsed:bool,ocr_pages:int,note:string}>
     */
    private function fileList(Tender $td): array
    {
        $out = [];
        foreach ((array) ($td->files ?? []) as $i => $f) {
            if (is_string($f)) {
                $out[] = ['i' => $i, 'path' => $f, 'name' => $this->prettyName($f), 'size' => 0,
                    'parsed' => true, 'ocr_pages' => 0, 'note' => ''];
                continue;
            }
            if (! is_array($f)) {
                continue;
            }
            $path = (string) ($f['path'] ?? '');
            $out[] = [
                'i' => $i,
                'path' => $path,
                'name' => (string) ($f['name'] ?? ($path !== '' ? $this->prettyName($path) : 'file')),
                'size' => (int) ($f['size'] ?? 0),
                'parsed' => (bool) ($f['parsed'] ?? false),
                'ocr_pages' => (int) ($f['ocr_pages'] ?? 0),
                'note' => (string) ($f['note'] ?? ''),
            ];
        }

        return $out;
    }

    /** "tenders/17/20260803-a1b2c3d4-Tender_Notice.pdf" → "Tender_Notice.pdf" */
    private function prettyName(string $path): string
    {
        $base = basename($path);

        return (string) preg_replace('/^\d{8}-[0-9a-f]{8}-/', '', $base);
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B';
    }

    /**
     * GET /papi/tender-file?id=&i= — stream one stored attachment back to the owner.
     *
     * Never takes a path from the request: the index is looked up inside THIS tenant's tender row, so
     * one tenant can never read another's documents (and no path traversal is possible).
     */
    public function file(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response('Owner access required.', 403);
        }
        $td = Tender::where('tenant_id', $t->id)->where('id', (int) $request->query('id'))->first();
        if (! $td) {
            return response('Not found.', 404);
        }
        $list = $this->fileList($td);
        $i = (int) $request->query('i');
        $f = $list[$i] ?? null;
        if (! $f || $f['path'] === '' || ! \Storage::disk('public')->exists($f['path'])) {
            return response('That document is no longer stored.', 404);
        }

        return response(\Storage::disk('public')->get($f['path']), 200, [
            'Content-Type' => $this->mimeFor($f['name']),
            'Content-Disposition' => 'inline; filename="' . preg_replace('/[^A-Za-z0-9._ -]+/', '_', $f['name']) . '"',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function mimeFor(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
            'csv' => 'text/csv',
            'txt', 'md' => 'text/plain; charset=UTF-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /** POST /papi/tender-item-save — capture a supplier cost on one line of the pricing board. */
    public function itemSave(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'message' => 'Owner access required.'], 403);
        }
        $data = $request->validate([
            'id' => 'required|integer',
            'unit_cost' => 'nullable|string|max:32',
            'currency' => 'nullable|string|max:8',
            'supplier' => 'nullable|string|max:160',
            'margin_pct' => 'nullable|string|max:12',
            'bid_unit' => 'nullable|string|max:32',
            'qty_num' => 'nullable|string|max:32',
            'status' => 'nullable|string|max:12',
            'note' => 'nullable|string|max:2000',
        ]);
        $row = \App\Models\TenderItem::where('tenant_id', $t->id)->where('id', (int) $data['id'])->first();
        if (! $row) {
            return response()->json(['ok' => false, 'message' => 'Item not found.'], 404);
        }
        // a blank status from the form means "leave it to the service to decide"
        if (($data['status'] ?? '') === '') {
            unset($data['status']);
        }
        $res = app(\App\Services\Tender\TenderItems::class)
            ->setPrice($t, $row, $data, (string) ($request->user()->name ?? ''));
        if (! $res['ok']) {
            return response()->json($res, 422);
        }
        $bidLine = $row->lineBid();

        return response()->json([
            'ok' => true, 'status' => $row->status, 'unit' => (string) ($row->unit ?: ''),
            'bid_unit' => $row->bid_unit !== null ? number_format((float) $row->bid_unit, 2) : null,
            'bid_line' => $bidLine !== null ? number_format($bidLine, 2) : null,
        ]);
    }

    /** GET /papi/tender-items.csv — the whole board as a spreadsheet (for suppliers or the bid file). */
    public function itemsCsv(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response('Owner access required.', 403);
        }
        $titles = Tender::where('tenant_id', $t->id)->pluck('title', 'id')->all();
        $rows = \App\Models\TenderItem::where('tenant_id', $t->id)
            ->orderBy('tender_id')->orderBy('line')->limit(2000)->get();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Tender', 'Lot', 'Item', 'Qty', 'Unit', 'Specification', 'Brand', 'Status',
            'Supplier', 'Unit cost', 'Currency', 'Bid unit', 'Line cost', 'Line bid', 'Priced on']);
        foreach ($rows as $r) {
            fputcsv($out, [
                (string) ($titles[$r->tender_id] ?? ('Tender #' . $r->tender_id)),
                (string) ($r->lot ?: ''), (string) $r->item, (string) ($r->qty ?: ''), (string) ($r->unit ?: ''),
                (string) ($r->spec ?: ''), (string) ($r->brand ?: ''), (string) $r->status,
                (string) ($r->supplier ?: ''),
                $r->unit_cost !== null ? (float) $r->unit_cost : '',
                (string) ($r->currency ?: ''),
                $r->bid_unit !== null ? (float) $r->bid_unit : '',
                $r->lineCost() ?? '', $r->lineBid() ?? '',
                optional($r->priced_at)->format('Y-m-d') ?: '',
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response("\xEF\xBB\xBF" . $csv, 200, [   // BOM so Excel opens UTF-8 correctly
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tender-items-' . date('Ymd') . '.csv"',
        ]);
    }

    /** v4.20 — ready-to-send supplier RFQ text built from the stored report (no AI cost). */
    public function rfq(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false], 403);
        }
        $data = $request->validate(['id' => 'required|integer']);
        $td = Tender::where('tenant_id', $t->id)->where('id', (int) $data['id'])->first();
        if (! $td) {
            return response()->json(['ok' => false, 'message' => 'Tender not found.'], 404);
        }
        $msg = app(\App\Services\Tender\TenderTasks::class)->rfqMessage($t, $td);

        return $msg === ''
            ? response()->json(['ok' => false, 'message' => 'No quotation-needed products in this tender\'s report.'])
            : response()->json(['ok' => true, 'text' => $msg]);
    }
}
