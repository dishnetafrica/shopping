<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Tasks</title>
<style>
  :root { --green:#16a34a; --green-ink:#15803d; --ink:#0c1420; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7f9; --amber:#d97706; --danger:#dc2626; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--ink); -webkit-font-smoothing:antialiased; }
  .wrap { max-width:520px; margin:0 auto; padding:22px 16px 40px; }
  h1 { font-size:22px; margin:2px 0 2px; letter-spacing:-.01em; }
  .sub { color:var(--muted); font-size:14px; margin-bottom:14px; }
  .counts { display:flex; gap:8px; margin-bottom:16px; }
  .pill { flex:1; background:#fff; border:1px solid var(--line); border-radius:14px; padding:12px 10px; text-align:center; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .pill .n { font-size:22px; font-weight:800; line-height:1; }
  .pill .l { font-size:11.5px; color:var(--muted); margin-top:4px; text-transform:uppercase; letter-spacing:.04em; }
  .pill.over .n { color:var(--danger); }
  .pill.open .n { color:var(--amber); }
  .card { display:block; text-decoration:none; color:inherit; background:#fff; border:1px solid var(--line); border-radius:16px; padding:15px 16px; box-shadow:0 1px 3px rgba(0,0,0,.05); margin-bottom:11px; }
  .card:active { background:#fafafa; }
  .t { font-weight:700; font-size:15.5px; line-height:1.3; margin-bottom:8px; }
  .row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .badge { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px; }
  .b-over { background:#fdecec; color:var(--danger); }
  .b-open { background:#fdf3e6; color:var(--amber); }
  .b-prog { background:#dbeafe; color:#1e40af; }
  .due { color:var(--muted); font-size:13px; }
  .go { margin-left:auto; color:var(--green-ink); font-weight:700; font-size:13.5px; }
  .empty { text-align:center; background:#fff; border:1px solid var(--line); border-radius:16px; padding:44px 20px; color:var(--muted); }
  .empty .big { font-size:40px; margin-bottom:8px; }
  .foot { text-align:center; color:var(--muted); font-size:12px; margin-top:22px; }
</style>
</head>
<body>
<div class="wrap">
  @php
    $now = now();
    $overdue = 0; $open = 0;
    foreach ($tasks as $t) { if ($t->isOverdue()) { $overdue++; } else { $open++; } }
    $total = $overdue + $open;
  @endphp

  <h1>📋 Your Tasks</h1>
  <div class="sub">{{ $userName }} · {{ $tenantName }}</div>

  <div class="counts">
    <div class="pill over"><div class="n">{{ $overdue }}</div><div class="l">Overdue</div></div>
    <div class="pill open"><div class="n">{{ $open }}</div><div class="l">Open</div></div>
    <div class="pill"><div class="n">{{ $total }}</div><div class="l">Total</div></div>
  </div>

  @forelse($tasks as $t)
    @php
      $over = $t->isOverdue();
      $prog = $t->status === 'in_progress';
      $due  = $t->due_at ? $t->due_at->format('M j, H:i') : null;
    @endphp
    @if($t->link_nonce)
      <a class="card" href="{{ url('/task/'.$t->link_nonce) }}">
    @else
      <div class="card">
    @endif
      <div class="t">{{ $t->title }}</div>
      <div class="row">
        @if($over)
          <span class="badge b-over">⏳ Overdue</span>
        @elseif($prog)
          <span class="badge b-prog">In progress</span>
        @else
          <span class="badge b-open">🟡 Open</span>
        @endif
        @if($due)<span class="due">{{ $over ? 'was due' : 'due' }} {{ $due }}</span>@endif
        @if($t->link_nonce)<span class="go">Open ›</span>@endif
      </div>
    @if($t->link_nonce)</a>@else</div>@endif
  @empty
    <div class="empty"><div class="big">✅</div>No open tasks right now. Nice work!</div>
  @endforelse

  <div class="foot">{{ $tenantName }} · Tasks</div>
</div>
</body>
</html>
