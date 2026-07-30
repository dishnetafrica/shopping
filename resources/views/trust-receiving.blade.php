<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Receive Goods</title>
<style>
  :root { --green:#16a34a; --red:#dc2626; --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --bg:#f9fafb; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink); }
  .wrap { max-width:480px; margin:0 auto; padding:20px 16px 60px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:14px; }
  h1 { font-size:19px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13px; margin-bottom:10px; }
  label { display:block; font-size:12px; color:var(--muted); margin:10px 0 4px; font-weight:600; }
  input { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font-size:15px; }
  .btn { display:block; width:100%; padding:14px; border:0; border-radius:12px; font-size:16px; font-weight:600; cursor:pointer; margin-top:12px; }
  .btn-primary { background:var(--green); color:#fff; }
  .btn:disabled { opacity:.5; }
  .results { border:1px solid var(--line); border-radius:10px; margin-top:6px; overflow:hidden; display:none; }
  .results div { padding:10px 12px; border-bottom:1px solid var(--line); font-size:14px; cursor:pointer; }
  .results div:last-child { border-bottom:0; }
  .line { border:1px solid var(--line); border-radius:12px; padding:12px; margin-top:10px; }
  .line b { font-size:14px; }
  .row { display:flex; gap:8px; margin-top:8px; }
  .row input { flex:1; }
  .tag { font-size:11px; padding:2px 8px; border-radius:999px; background:#f3f4f6; color:var(--muted); margin-left:6px; }
  .tag.ok { background:#dcfce7; color:#166534; }
  .x { float:right; color:var(--red); font-weight:700; cursor:pointer; }
  .photo-btn { font-size:13px; padding:8px 10px; border:1px dashed var(--line); border-radius:10px; background:#fafafa; cursor:pointer; }
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
    <h1>Delivery recorded</h1>
    <div class="sub">{{ $lines->count() }} lines by {{ $s->submitted_by }} — the owner has been notified.</div>
  </div>
@else
  <div class="card">
    <h1>📦 Receive goods — {{ $s->location }}</h1>
    <div class="sub">Count what actually arrived. Weigh sacks &amp; cartons. Photo each pallet or stack.</div>
    <label>Your name</label>
    <input id="who" placeholder="Receiver name" autocomplete="name">
    <label>Supplier</label>
    <input id="supplier" value="{{ $s->supplier_name }}" placeholder="Supplier name">
    <label>Delivery note / invoice no (if on the paper)</label>
    <input id="ref" placeholder="e.g. DN-1042">
  </div>

  <div class="card">
    <label>Add product</label>
    <input id="search" placeholder="Type name / SKU / barcode…" autocomplete="off">
    <div class="results" id="results"></div>
    <div id="lines"></div>
    <button class="btn btn-primary" id="submit" disabled>Submit delivery</button>
    <div class="hint">Weight and photo are optional but every gap is reported to the owner — better to add them now than explain later.</div>
  </div>
@endif
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const nonce = @json($nonce);
let lines = [];

const $ = id => document.getElementById(id);
function esc(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

let t=null;
$('search')?.addEventListener('input', e => {
  clearTimeout(t);
  const q = e.target.value.trim();
  if (q.length < 2) { $('results').style.display='none'; return; }
  t = setTimeout(async () => {
    const r = await fetch(`/receive/${nonce}/products?q=` + encodeURIComponent(q));
    const items = await r.json();
    const box = $('results');
    box.innerHTML = items.map(p =>
      `<div data-id="${p.id}" data-name="${esc(p.name)}" data-w="${p.sold_by_weight?1:0}">${esc(p.name)}${p.sku?` <span class="tag">${esc(p.sku)}</span>`:''}</div>`).join('') || '<div>No match — check spelling</div>';
    box.style.display = 'block';
    box.querySelectorAll('div[data-id]').forEach(d => d.onclick = () => addLine(+d.dataset.id, d.dataset.name, d.dataset.w==='1'));
  }, 250);
});

function addLine(id, name, byWeight){
  $('results').style.display='none'; $('search').value='';
  const ix = lines.length;
  lines.push({product_id:id, name, qty:'', weight_kg:'', photo_data:'', note:''});
  const div = document.createElement('div');
  div.className='line'; div.id='ln'+ix;
  div.innerHTML = `<span class="x" onclick="rmLine(${ix})">✕</span><b>${esc(name)}</b>
    <div class="row">
      <input type="number" step="any" inputmode="decimal" placeholder="Qty counted" oninput="lines[${ix}].qty=this.value; refresh()">
      <input type="number" step="any" inputmode="decimal" placeholder="Weight kg${byWeight?' (weigh it!)':''}" oninput="lines[${ix}].weight_kg=this.value">
    </div>
    <div class="row">
      <label class="photo-btn">📷 Photo <span class="tag" id="pt${ix}">none</span>
        <input type="file" accept="image/*" capture="environment" style="display:none" onchange="snap(${ix}, this)">
      </label>
      <input placeholder="Note (damage, brand…)" oninput="lines[${ix}].note=this.value">
    </div>`;
  $('lines').appendChild(div);
  refresh();
}
function rmLine(ix){ lines[ix]=null; $('ln'+ix).remove(); refresh(); }
function refresh(){
  const live = lines.filter(l => l && parseFloat(l.qty) > 0);
  $('submit').disabled = live.length === 0;
}
function snap(ix, input){
  const f = input.files[0]; if (!f) return;
  const rd = new FileReader();
  rd.onload = () => {
    // downscale to ~1280px to keep uploads mobile-friendly
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas');
      const s = Math.min(1, 1280 / Math.max(img.width, img.height));
      c.width = img.width * s; c.height = img.height * s;
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      lines[ix].photo_data = c.toDataURL('image/jpeg', 0.8);
      document.getElementById('pt'+ix).textContent = 'added ✓';
      document.getElementById('pt'+ix).className = 'tag ok';
    };
    img.src = rd.result;
  };
  rd.readAsDataURL(f);
}

$('submit')?.addEventListener('click', async () => {
  const who = $('who').value.trim();
  if (!who) { alert('Enter your name first.'); return; }
  $('submit').disabled = true; $('submit').textContent = 'Submitting…';
  const body = {
    received_by: who,
    supplier_name: $('supplier').value.trim(),
    expected_ref: $('ref').value.trim(),
    lines: lines.filter(l => l && parseFloat(l.qty) > 0).map(l => ({
      product_id: l.product_id, qty: parseFloat(l.qty),
      weight_kg: l.weight_kg ? parseFloat(l.weight_kg) : null,
      photo_data: l.photo_data || null, note: l.note || null,
    })),
  };
  const r = await fetch(`/receive/${nonce}/submit`, {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
    body: JSON.stringify(body),
  });
  if (r.ok) location.reload();
  else { const e = await r.json().catch(()=>({})); alert(e.error || 'Submit failed — try again.'); $('submit').disabled=false; $('submit').textContent='Submit delivery'; }
});
</script>
</body>
</html>
