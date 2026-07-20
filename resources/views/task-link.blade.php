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
  .wrap { max-width:480px; margin:0 auto; padding:24px 18px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  .badge { display:inline-block; font-size:12px; font-weight:600; padding:4px 10px; border-radius:999px; }
  .b-open { background:#fef9c3; color:#854d0e; }
  .b-prog { background:#dbeafe; color:#1e40af; }
  .b-done { background:#dcfce7; color:#166534; }
  h1 { font-size:20px; margin:14px 0 6px; }
  .due { color:var(--muted); font-size:14px; margin-bottom:18px; }
  .btn { display:block; width:100%; padding:14px; border:0; border-radius:12px; font-size:16px; font-weight:600; cursor:pointer; margin-top:10px; }
  .btn-primary { background:var(--green); color:#fff; }
  .btn-ghost { background:#f3f4f6; color:var(--ink); }
  .btn:disabled { opacity:.5; cursor:not-allowed; }
  textarea { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font-family:inherit; font-size:14px; margin-top:12px; resize:vertical; }
  .msg { text-align:center; margin-top:14px; font-size:14px; }
  .ok { color:var(--green); }
  .err { color:#dc2626; }
  .foot { text-align:center; color:var(--muted); font-size:12px; margin-top:20px; }
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

    <div id="actions" @if(!$active) style="display:none" @endif>
      <button class="btn btn-primary" id="btnComplete" onclick="doComplete()">✓ Mark as done</button>
      @if($status !== 'in_progress')
        <button class="btn btn-ghost" id="btnAccept" onclick="doAccept()">Accept / Start</button>
      @endif
      <textarea id="note" rows="2" placeholder="Add a note (optional)…"></textarea>
    </div>

    <div id="doneMsg" class="msg ok" @if($active) style="display:none" @endif>This task is complete. Thank you!</div>
    <div id="flash" class="msg"></div>
  </div>
  <div class="foot">Family Shoppers · Task</div>
</div>

<script>
  var NONCE = @json($nonce);
  var TOKEN = document.querySelector('meta[name=csrf-token]').content;
  function flash(m, ok){ var f=document.getElementById('flash'); f.textContent=m; f.className='msg '+(ok?'ok':'err'); }
  async function post(path, body){
    var r = await fetch('/t/'+NONCE+'/'+path, {
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
  async function doComplete(){
    var note = document.getElementById('note').value;
    var r = await post('complete', {note:note});
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
