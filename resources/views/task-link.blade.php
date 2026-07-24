<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Your Task</title>
<style>
  :root { --green:#16a34a; --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --bg:#f9fafb; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink); }
  .wrap { max-width:480px; margin:0 auto; padding:24px 18px 40px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  .badge { display:inline-block; font-size:12px; font-weight:600; padding:4px 10px; border-radius:999px; }
  .b-open { background:#fef9c3; color:#854d0e; }
  .b-prog { background:#dbeafe; color:#1e40af; }
  .b-done { background:#dcfce7; color:#166534; }
  h1 { font-size:20px; margin:14px 0 6px; line-height:1.3; }
  .due { color:var(--muted); font-size:14px; margin-bottom:14px; }
  .desc { font-size:14px; color:#374151; white-space:pre-wrap; line-height:1.5; background:#f9fafb; border:1px solid var(--line); border-radius:10px; padding:12px 14px; margin-bottom:16px; }
  .btn { display:block; width:100%; padding:14px; border:0; border-radius:12px; font-size:16px; font-weight:600; cursor:pointer; margin-top:10px; }
  .btn-primary { background:var(--green); color:#fff; }
  .btn-ghost { background:#f3f4f6; color:var(--ink); }
  .btn:disabled { opacity:.5; cursor:not-allowed; }
  textarea { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font-family:inherit; font-size:14px; margin-top:12px; resize:vertical; }
  .msg { text-align:center; margin-top:14px; font-size:14px; }
  .ok { color:var(--green); }
  .err { color:#dc2626; }
  .foot { text-align:center; color:var(--muted); font-size:12px; margin-top:20px; }
  /* Photo */
  .photo-h { font-size:13px; font-weight:700; color:var(--muted); margin:16px 0 8px; text-transform:uppercase; letter-spacing:.04em; }
  .pick { display:flex; align-items:center; gap:10px; border:1px dashed #cbd5e1; border-radius:12px; padding:14px; background:#fbfcfe; cursor:pointer; font-size:14px; color:#475569; font-weight:600; }
  .pick input { display:none; }
  .thumb { display:block; width:100%; max-height:230px; object-fit:cover; border-radius:12px; border:1px solid var(--line); margin-top:10px; }
  .filelink { display:inline-block; margin-top:10px; color:var(--green); font-weight:600; font-size:14px; text-decoration:none; }
  .hint { font-size:12px; color:var(--muted); margin-top:8px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    @php
      $badgeClass = $done ? 'b-done' : ($status === 'in_progress' ? 'b-prog' : 'b-open');
      $badgeText  = $done ? 'Done' : ($status === 'in_progress' ? 'In progress' : 'To do');
    @endphp
    <span class="badge {{ $badgeClass }}" id="badge">{{ $badgeText }}</span>
    <h1>{{ $title }}</h1>
    @if($due)<div class="due">Due: {{ $due }}</div>@endif
    @if(!empty($note))<div class="desc">{{ $note }}</div>@endif

    {{-- Existing photo (if one was already attached) --}}
    <div id="current" @if(empty($attachment)) style="display:none" @endif>
      <div class="photo-h">Attached</div>
      @php $isImg = $attachment && preg_match('/\.(jpg|jpeg|png|webp)$/i', $attachment); @endphp
      @if($isImg)
        <img class="thumb" id="currentImg" src="{{ $attachment }}" alt="Attached photo">
      @else
        <a class="filelink" id="currentLink" href="{{ $attachment }}" target="_blank">📎 View attached file</a>
      @endif
    </div>

    <div id="actions" @if(!$active) style="display:none" @endif>
      {{-- Optional photo --}}
      <div class="photo-h">Photo (optional)</div>
      <label class="pick" id="pickLabel">
        <span id="pickText">📷 Add a photo</span>
        <input type="file" id="photo" accept="image/*,application/pdf" onchange="onPick()">
      </label>
      <img class="thumb" id="preview" style="display:none" alt="Preview">
      <button class="btn btn-ghost" id="btnUpload" style="display:none" onclick="doUpload()">Attach this photo</button>

      <textarea id="note" rows="2" placeholder="Add a note (optional)…"></textarea>

      <button class="btn btn-primary" id="btnComplete" onclick="doComplete()">✓ Mark as done</button>
      @if($status !== 'in_progress')
        <button class="btn btn-ghost" id="btnAccept" onclick="doAccept()">Accept / Start</button>
      @endif
      <div class="hint">A photo isn’t required — attach one if it helps (proof of work, a reading, a receipt).</div>
    </div>

    <div id="doneMsg" class="msg ok" @if($active) style="display:none" @endif>This task is complete. Thank you!</div>
    <div id="flash" class="msg"></div>
  </div>
  <div class="foot">{{ config('app.name', 'CloudBSS') }} · Task</div>
</div>

<script>
  var NONCE = @json($nonce);
  var TOKEN = document.querySelector('meta[name=csrf-token]').content;
  var PHOTO = null;        // {data, name} once a file is chosen
  function flash(m, ok){ var f=document.getElementById('flash'); f.textContent=m; f.className='msg '+(ok?'ok':'err'); }

  function onPick(){
    var inp = document.getElementById('photo');
    var f = inp.files && inp.files[0];
    if(!f){ return; }
    if(f.size > 15*1024*1024){ flash('That file is too big (max 15 MB).', false); inp.value=''; return; }
    var reader = new FileReader();
    reader.onload = function(){
      PHOTO = { data: reader.result, name: f.name || 'photo.jpg' };
      document.getElementById('pickText').textContent = '📷 ' + (f.name || 'Photo selected');
      var pv = document.getElementById('preview');
      if(/^image\//.test(f.type)){ pv.src = reader.result; pv.style.display='block'; } else { pv.style.display='none'; }
      document.getElementById('btnUpload').style.display='block';   // allow attaching now, without completing
      flash('', true);
    };
    reader.readAsDataURL(f);
  }

  async function post(path, body){
    var r = await fetch('/task/'+NONCE+'/'+path, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-TOKEN':TOKEN,'Accept':'application/json'},
      body: JSON.stringify(body||{})
    });
    return r.json();
  }

  async function doAccept(){
    var r = await post('accept', {});
    if(r && r.ok){ document.getElementById('badge').textContent='In progress'; document.getElementById('badge').className='badge b-prog';
      var b=document.getElementById('btnAccept'); if(b)b.style.display='none'; flash('Accepted — good luck!', true); }
    else flash((r&&r.error)||'Could not accept', false);
  }

  async function doUpload(){
    if(!PHOTO){ flash('Choose a photo first.', false); return; }
    var btn=document.getElementById('btnUpload'); btn.disabled=true; btn.textContent='Uploading…';
    var r = await post('upload', { attachment_data: PHOTO.data, attachment_name: PHOTO.name });
    btn.disabled=false; btn.textContent='Attach this photo';
    if(r && r.ok){
      flash('Photo attached ✓', true);
      // show it in the "Attached" area
      var cur=document.getElementById('current'); cur.style.display='block';
      showAttached(r.attachment || PHOTO.data);
      PHOTO=null; document.getElementById('preview').style.display='none';
      document.getElementById('pickText').textContent='📷 Add another photo';
      btn.style.display='none';
    } else flash((r&&r.error)||'Could not upload the photo', false);
  }

  function showAttached(url){
    var cur=document.getElementById('current');
    var img=document.getElementById('currentImg');
    if(img){ img.src=url; }
    else if(/\.(jpg|jpeg|png|webp)$/i.test(url) || /^data:image\//.test(url)){
      var i=document.createElement('img'); i.className='thumb'; i.id='currentImg'; i.src=url; cur.appendChild(i);
    }
  }

  async function doComplete(){
    var note = document.getElementById('note').value;
    var body = { note: note };
    if(PHOTO){ body.attachment_data = PHOTO.data; body.attachment_name = PHOTO.name; }   // optional
    var btn=document.getElementById('btnComplete'); btn.disabled=true; btn.textContent='Saving…';
    var r = await post('complete', body);
    btn.disabled=false; btn.textContent='✓ Mark as done';
    if(r && r.ok){
      document.getElementById('actions').style.display='none';
      document.getElementById('doneMsg').style.display='block';
      document.getElementById('badge').textContent='Done';
      document.getElementById('badge').className='badge b-done';
    } else flash((r&&r.error)||'Could not complete', false);
  }
</script>
</body>
</html>
