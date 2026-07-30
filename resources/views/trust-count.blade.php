<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Stock Count</title>
<style>
  :root { --green:#16a34a; --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --bg:#f9fafb; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink); }
  .wrap { max-width:480px; margin:0 auto; padding:20px 16px 60px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:14px; }
  h1 { font-size:19px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13px; margin-bottom:8px; }
  label { display:block; font-size:12px; color:var(--muted); margin:10px 0 4px; font-weight:600; }
  input { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font-size:15px; }
  .item { display:flex; align-items:center; gap:10px; border-top:1px solid var(--line); padding:12px 0; }
  .item:first-of-type { border-top:0; }
  .item .n { flex:1; font-size:14px; }
  .item .n small { display:block; color:var(--muted); }
  .item input { width:110px; text-align:center; }
  .btn { display:block; width:100%; padding:14px; border:0; border-radius:12px; font-size:16px; font-weight:600; cursor:pointer; margin-top:12px; background:var(--green); color:#fff; }
  .btn:disabled { opacity:.5; }
  .done { text-align:center; padding:30px 10px; }
  .done .big { font-size:44px; }
  .hint { font-size:12px; color:var(--muted); margin-top:8px; }
</style>
</head>
<body>
<div class="wrap">
@if($s->status === 'submitted')
  <div class="card done">
    <div class="big">✅</div>
    <h1>Count recorded</h1>
    <div class="sub">{{ count($results) }} items by {{ $s->submitted_by }} — the owner has the variance report.</div>
  </div>
@else
  <div class="card">
    <h1>🔢 Today's stock count</h1>
    <div class="sub">Count what is PHYSICALLY there — shelf + backroom for that location. Don't check the system number first; that's the whole point.</div>
    <label>Your name</label>
    <input id="who" placeholder="Counter name" autocomplete="name">
  </div>
  <div class="card">
    @foreach($picks as $i => $p)
      <div class="item">
        <div class="n">{{ $p['name'] }}<small>{{ $p['location'] }}</small></div>
        <input type="number" step="any" inputmode="decimal" placeholder="Counted"
               data-pid="{{ $p['product_id'] }}" data-loc="{{ $p['location'] }}" oninput="refresh()">
      </div>
    @endforeach
    <button class="btn" id="submit" disabled>Submit count</button>
    <div class="hint">These {{ count($picks) }} items were picked by the system — lowest confidence × highest value. Ten minutes now saves a full-day stocktake later.</div>
  </div>
@endif
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const nonce = @json($nonce);
const $ = id => document.getElementById(id);

function refresh(){
  const filled = [...document.querySelectorAll('input[data-pid]')].filter(i => i.value !== '');
  $('submit').disabled = filled.length === 0;
}

$('submit')?.addEventListener('click', async () => {
  const who = $('who').value.trim();
  if (!who) { alert('Enter your name first.'); return; }
  const results = [...document.querySelectorAll('input[data-pid]')]
    .filter(i => i.value !== '')
    .map(i => ({ product_id:+i.dataset.pid, location:i.dataset.loc, counted:parseFloat(i.value) }));
  $('submit').disabled = true; $('submit').textContent = 'Submitting…';
  const r = await fetch(`/count/${nonce}/submit`, {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
    body: JSON.stringify({ counted_by: who, results }),
  });
  if (r.ok) location.reload();
  else { const e = await r.json().catch(()=>({})); alert(e.error || 'Submit failed — try again.'); $('submit').disabled=false; $('submit').textContent='Submit count'; }
});
</script>
</body>
</html>
