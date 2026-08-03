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

        $rows = '';
        foreach (Tender::where('tenant_id', $t->id)->orderByDesc('id')->limit(20)->get() as $td) {
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
            $rows .= '<div class="trow" onclick="tog(this)">'
                . '<div class="tmain"><b>' . e((string) ($td->title ?: 'Untitled')) . '</b>'
                . '<span class="tsub">' . e((string) ($td->organization ?: $td->source)) . ' · ' . e((string) ($td->division ?: '')) . '</span></div>'
                . '<div class="tmeta"><span class="chip green">' . e($score) . '</span>'
                . '<span class="chip">' . e((string) ($td->recommendation ?: '…')) . '</span>' . $dchip
                . '<span class="chip st">' . e((string) $td->status) . '</span></div>'
                . '<pre class="tsum">' . e((string) ($td->summary ?: 'No summary.')) . '</pre>'
                . '<div class="tact" onclick="event.stopPropagation()">'
                . '<select onchange="stSet(' . (int) $td->id . ',this.value)">' . $opts . '</select>'
                . '<button class="mini" onclick="rfqCopy(' . (int) $td->id . ',this)">📋 Copy supplier RFQ</button>'
                . '</div>'
                . '</div>';
        }
        if ($rows === '') {
            $rows = '<p style="color:#9CA3AF">No tenders analysed yet — drop the first one above, or press "Scan forum now".</p>';
        }
        $enabledNote = $enabled ? ''
            : '<p style="background:#FEF3C7;border-radius:8px;padding:8px 12px;font-size:12.5px">Automatic daily scanning is OFF — turn on <b>Settings → Tenders → Watch for tenders</b> to have TenderBot check the forum twice a day by itself.</p>';

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
  <p style="margin-top:10px"><a href="/v3#/settings">← Settings</a></p>
</div>
<div class="card"><h1 style="font-size:16px">Recent tenders</h1>{$rows}</div>
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
        if ($tender->summary && $tender->status !== 'failed' && (bool) $t->setting('tender_notify', true)) {
            try {
                \App\Jobs\NotifyOwner::dispatch($t->id, $tender->summary
                    . ($nTasks > 0 ? "\n\n📋 {$nTasks} task(s) created for your team — check the task inbox." : ''));
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['ok' => $tender->status !== 'failed', 'id' => $tender->id,
            'summary' => (string) $tender->summary . ($nTasks > 0 ? "\n\n📋 {$nTasks} task(s) created." : ''),
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
