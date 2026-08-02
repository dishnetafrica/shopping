<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Panel\BikePhotoPack;
use Illuminate\Http\Request;

/**
 * v4.16 — one-shot bike photo pack (Bhavin: "upload once and it remain there").
 *
 * GET  /papi/bike-photos     owner-only self-contained upload page (no shell dependency —
 *                            works even when panel assets are mid-deploy).
 * POST /papi/bike-photos-zip ingests the zip via BikePhotoPack and answers per-photo JSON.
 *
 * Same owner gate as BrandingUploadController; same absolute-URL rule (built from the host
 * the owner is browsing on, never APP_URL).
 */
class BikePhotoPackController extends Controller
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
        $ids = implode(' · ', BikePhotoPack::MODEL_IDS);

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bike photo pack — CloudBSS</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#F6F7F8;color:#111827;margin:0;padding:24px}
.card{max-width:640px;margin:0 auto;background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:22px 24px}
h1{font-size:20px;margin:0 0 6px}
p{font-size:13.5px;color:#6B7280;line-height:1.55;margin:6px 0}
code{background:#F3F4F6;border-radius:5px;padding:1px 6px;font-size:12px}
.drop{margin:16px 0;border:2px dashed #CFE6D8;border-radius:12px;background:#F8FBF9;padding:26px;text-align:center;cursor:pointer}
.drop.on{border-color:#14532D;background:#EAF5EE}
.btn{display:inline-block;background:#14532D;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer}
.btn[disabled]{opacity:.5;cursor:default}
.row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px}
.row img{height:34px;width:46px;object-fit:cover;border-radius:6px;border:1px solid #E5E7EB}
.ok{color:#14532D;font-weight:600}.bad{color:#B45309}
.note{color:#9CA3AF;font-size:12px}
#msg{margin-top:12px;font-size:14px;font-weight:600}
a{color:#14532D}
</style></head><body>
<div class="card">
  <h1>Bike photo pack</h1>
  <p>Upload <b>one zip</b> with your motorcycle photos and every photo field is filled in one go.
     Name each file after its bike: <code>{$ids}</code> (.jpg / .png / .webp).</p>
  <p>Photos are stored with your business data — they stay through every update and rebuild.</p>
  <div class="drop" id="drop">Drop the zip here, or click to choose<input type="file" id="file" accept=".zip" style="display:none"></div>
  <button class="btn" id="go" disabled>Upload pack</button>
  <div id="msg"></div><div id="out"></div>
  <p style="margin-top:16px"><a href="/v3#/settings">← Back to Settings</a></p>
</div>
<script>
var F=null,drop=document.getElementById('drop'),fi=document.getElementById('file'),go=document.getElementById('go');
function pick(f){ if(!f) return; F=f; drop.textContent='📦 '+f.name+' ('+Math.round(f.size/1024)+' KB) — ready'; go.disabled=false; }
drop.onclick=function(){ fi.click(); };
fi.onchange=function(){ pick(fi.files[0]); };
drop.ondragover=function(e){ e.preventDefault(); drop.classList.add('on'); };
drop.ondragleave=function(){ drop.classList.remove('on'); };
drop.ondrop=function(e){ e.preventDefault(); drop.classList.remove('on'); pick(e.dataTransfer.files[0]); };
go.onclick=function(){
  if(!F) return; go.disabled=true; go.textContent='Uploading…';
  var fd=new FormData(); fd.append('file',F);
  fetch('/papi/bike-photos-zip',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF-TOKEN':'{$csrf}'}})
    .then(function(r){ return r.json(); })
    .then(function(j){
      go.textContent='Upload pack'; go.disabled=false;
      document.getElementById('msg').textContent=j.message||''; document.getElementById('msg').className=j.ok?'ok':'bad';
      var out=document.getElementById('out'); out.innerHTML='';
      (j.results||[]).forEach(function(r){
        out.insertAdjacentHTML('beforeend','<div class="row">'+(r.url?'<img src="'+r.url+'">':'')
          +'<b>'+r.name+'</b><span class="'+(r.ok?'ok':'bad')+'">'+(r.ok?'✓ applied':'—')+'</span>'
          +'<span class="note">'+(r.note||'')+'</span></div>');
      });
    })
    .catch(function(){ go.textContent='Upload pack'; go.disabled=false;
      document.getElementById('msg').textContent='Upload failed — try again.'; document.getElementById('msg').className='bad'; });
};
</script>
</body></html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request)
    {
        $t = $this->gate($request);
        if (! $t) {
            return response()->json(['ok' => false, 'results' => [], 'message' => 'Owner access required.'], 403);
        }
        $data = $request->validate([
            'file' => 'required|file|mimes:zip|max:40960',
        ]);

        try {
            $res = app(BikePhotoPack::class)->ingest($t, $data['file']->getRealPath(), $request->getSchemeAndHttpHost());
        } catch (\Throwable $e) {
            \Log::warning('bike-photos-zip failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'results' => [], 'message' => 'Something went wrong reading the zip.'], 500);
        }

        return response()->json($res);
    }
}
