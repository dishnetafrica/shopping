<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>CloudBSS</title>
<style>
/* ================= DESIGN TOKENS (ported from prototype) ================= */
:root{
  --bg:#f6f7f9;--surface:#fff;--surface-2:#fbfbfc;--line:#e7e9ed;--line-2:#eef0f3;
  --ink:#151b23;--ink-2:#3c4756;--muted:#6b7684;--faint:#98a1ad;
  --brand:#16a34a;--brand-ink:#15803d;--brand-wash:#eafaf0;
  --accent:#4f46e5;--accent-wash:#eef0ff;
  --ok:#16a34a;--ok-wash:#e9f8ef;--warn:#d97706;--warn-wash:#fdf3e6;
  --danger:#dc2626;--danger-wash:#fdecec;--info:#0ea5e9;--info-wash:#e8f6fe;--sky:#0284c7;
  --side:#0e1b16;--side-2:#132821;--side-ink:#aeb9b3;--side-ink-2:#7f8d86;
  --radius:10px;--radius-sm:7px;--radius-lg:14px;
  --sh-sm:0 1px 2px rgba(16,24,40,.05);
  --sh:0 1px 3px rgba(16,24,40,.07),0 1px 2px rgba(16,24,40,.04);
  --sh-md:0 4px 12px rgba(16,24,40,.08),0 2px 4px rgba(16,24,40,.04);
  --sh-lg:0 12px 32px rgba(16,24,40,.14);
  --sidebar-w:248px;--topbar-h:58px;
  --font:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  --mono:"SFMono-Regular",ui-monospace,"JetBrains Mono",Menlo,Consolas,monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--ink);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}
input,select,textarea{font-family:inherit;font-size:14px}
svg{display:block}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:#d5d9e0;border-radius:6px;border:2px solid var(--bg)}
::-webkit-scrollbar-thumb:hover{background:#c2c8d1}
.ic{width:17px;height:17px;stroke:currentColor;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round;flex:none}
.ic-sm{width:14px;height:14px}.ic-lg{width:22px;height:22px}

/* ================= APP SHELL ================= */
.app{display:grid;grid-template-columns:var(--sidebar-w) 1fr;height:100vh}
.side{background:var(--side);color:var(--side-ink);display:flex;flex-direction:column;overflow:hidden;border-right:1px solid #0a1410}
.brand{height:var(--topbar-h);display:flex;align-items:center;gap:10px;padding:0 18px;color:#fff;font-weight:700;letter-spacing:-.02em;font-size:15.5px;flex:none;border-bottom:1px solid rgba(255,255,255,.05)}
.brand .m{width:28px;height:28px;border-radius:8px;background:var(--brand);display:grid;place-items:center;color:#fff;flex:none}
.nav{flex:1;overflow-y:auto;padding:12px 10px 20px}
.nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-color:transparent}
.ngrp{font-size:10.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--side-ink-2);padding:16px 12px 7px}
.ngrp:first-child{padding-top:4px}
.nitem{display:flex;align-items:center;gap:11px;padding:8px 12px;border-radius:8px;font-size:13.5px;font-weight:500;cursor:pointer;color:var(--side-ink);margin-bottom:1px;position:relative;transition:.12s}
.nitem:hover{background:rgba(255,255,255,.055);color:#eef5f0}
.nitem.on{background:rgba(22,163,74,.16);color:#fff}
.nitem.on::before{content:"";position:absolute;left:-10px;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--brand)}
.nitem .ic{opacity:.9}
.nfoot{flex:none;border-top:1px solid rgba(255,255,255,.06);padding:12px}
.nuser{display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:8px}
.nuser .av{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#16a34a,#0d9488);display:grid;place-items:center;color:#fff;font-weight:700;font-size:13px;flex:none}
.nuser .nm{font-size:13px;font-weight:600;color:#eaf2ee;line-height:1.2}
.nuser .rl{font-size:11px;color:var(--side-ink-2);text-transform:capitalize}

.main{display:flex;flex-direction:column;overflow:hidden;min-width:0}
.top{height:var(--topbar-h);flex:none;background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:14px;padding:0 20px}
.sb{flex:1;max-width:440px;position:relative}
.sb svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--faint)}
.sb input{width:100%;height:37px;border:1px solid var(--line);background:var(--surface-2);border-radius:8px;padding:0 12px 0 35px;transition:.15s}
.sb input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-wash);background:#fff}
.sp{flex:1}
.tbtn{width:37px;height:37px;border-radius:8px;display:grid;place-items:center;color:var(--ink-2);transition:.12s}
.tbtn:hover{background:var(--line-2);color:var(--ink)}
.view{flex:1;overflow-y:auto;scroll-behavior:smooth}
.wrap{padding:24px 28px 60px;max-width:1320px;margin:0 auto}

/* ================= PAGE HEADER ================= */
.crumb{font-size:12.5px;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;gap:6px}
.rec-head{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--sh-sm);padding:18px 22px;margin-bottom:16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.rec-av{width:52px;height:52px;border-radius:13px;display:grid;place-items:center;color:#fff;font-weight:700;font-size:18px;flex:none}
.rec-head .idn{min-width:0}
.rec-head h1{font-size:20px;font-weight:700;letter-spacing:-.02em;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rec-head .sub{color:var(--muted);font-size:13px;margin-top:3px}
.rec-hl{margin-left:auto;text-align:right;padding-right:2px}
.rec-hl .v{font-size:21px;font-weight:750;letter-spacing:-.02em;line-height:1.1}
.rec-hl .v.warn{color:var(--warn)}.rec-hl .v.danger{color:var(--danger)}.rec-hl .v.ok{color:var(--brand-ink)}
.rec-hl .l{font-size:12.5px;color:var(--muted);margin-top:2px}
.acts{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:2px;width:100%}
.acts.inl{width:auto;margin-top:0}

/* ================= BUTTONS ================= */
.btn{display:inline-flex;align-items:center;gap:7px;height:37px;padding:0 14px;border-radius:8px;font-weight:600;font-size:13px;border:1px solid var(--line);background:var(--surface);color:var(--ink-2);transition:.12s;white-space:nowrap}
.btn:hover{background:var(--surface-2);border-color:#d8dce3;color:var(--ink)}
.btn.pri{background:var(--brand);border-color:var(--brand);color:#fff;box-shadow:var(--sh-sm)}
.btn.pri:hover{background:var(--brand-ink);border-color:var(--brand-ink)}
.btn.ai{border-style:dashed;border-color:#c9d3f0;color:var(--accent)}
.btn.ai:hover{background:var(--accent-wash);border-color:#b9c4ef}
.btn.sm{height:31px;padding:0 11px;font-size:12.5px}
.btn.danger{color:var(--danger)}.btn.danger:hover{background:var(--danger-wash);border-color:#f3c9c9}

/* ================= CARDS / KPI ================= */
.grid{display:grid;gap:16px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--sh-sm)}
.card .h{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);font-weight:650;font-size:14px}
.card .h .n{margin-left:auto;font-size:12px;font-weight:700;color:#fff;background:var(--brand);min-width:22px;height:22px;padding:0 7px;border-radius:20px;display:grid;place-items:center}
.card .h .n.warn{background:var(--warn)}.card .h .n.danger{background:var(--danger)}
.card .b{padding:18px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:16px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--sh-sm);position:relative;overflow:hidden}
.kpi .top{display:flex;align-items:center;gap:9px;color:var(--muted);font-size:12.5px;font-weight:600}
.kpi .top .ib{width:30px;height:30px;border-radius:8px;display:grid;place-items:center;flex:none;background:var(--line-2);color:var(--ink-2)}
.kpi .top .ib.ok{background:var(--ok-wash);color:var(--brand-ink)}
.kpi .top .ib.warn{background:var(--warn-wash);color:var(--warn)}
.kpi .top .ib.danger{background:var(--danger-wash);color:var(--danger)}
.kpi .val{font-size:25px;font-weight:750;letter-spacing:-.03em;margin:12px 0 4px;line-height:1;font-variant-numeric:tabular-nums}
.kpi .val.warn{color:var(--warn)}.kpi .val.danger{color:var(--danger)}.kpi .val.ok{color:var(--brand-ink)}
.kpi .foot{font-size:12px;color:var(--faint)}

/* ================= BADGES ================= */
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:2.5px 9px;border-radius:20px;white-space:nowrap;line-height:1.35;text-transform:capitalize}
.badge .d{width:6px;height:6px;border-radius:50%}
.b-ok{background:var(--ok-wash);color:var(--brand-ink)}.b-ok .d{background:var(--ok)}
.b-warn{background:var(--warn-wash);color:var(--warn)}.b-warn .d{background:var(--warn)}
.b-danger{background:var(--danger-wash);color:var(--danger)}.b-danger .d{background:var(--danger)}
.b-info{background:var(--info-wash);color:var(--sky)}.b-info .d{background:var(--info)}
.b-accent{background:var(--accent-wash);color:var(--accent)}.b-accent .d{background:var(--accent)}
.b-default{background:var(--line-2);color:var(--muted)}.b-default .d{background:var(--faint)}

/* ================= RECORD 360 ================= */
.rec{display:grid;grid-template-columns:300px 1fr;gap:18px;align-items:start}
.rail{display:flex;flex-direction:column;gap:14px}
.rail-t{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--faint);margin-bottom:12px}
.rail-kpi{display:flex;flex-direction:column;gap:12px}
.rail-kpi .big{font-size:26px;font-weight:750;letter-spacing:-.02em;line-height:1;padding-bottom:2px;font-variant-numeric:tabular-nums}
.rail-kpi .big.warn{color:var(--warn)}.rail-kpi .big.danger{color:var(--danger)}.rail-kpi .big.ok{color:var(--brand-ink)}
.rail-kpi .r{display:flex;align-items:baseline;justify-content:space-between;gap:10px}
.rail-kpi .r .l{font-size:12.5px;color:var(--muted)}
.rail-kpi .r .v{font-weight:700;font-variant-numeric:tabular-nums;font-size:14px}
.rail-kpi .r .v.warn{color:var(--warn)}.rail-kpi .r .v.danger{color:var(--danger)}.rail-kpi .r .v.ok{color:var(--brand-ink)}
.rail-div{height:1px;background:var(--line-2);margin:3px 0}
.qa{display:flex;flex-direction:column;gap:1px}
.qa button{display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13px;font-weight:500;color:var(--ink-2);text-align:left;width:100%;transition:.1s}
.qa button:hover{background:var(--brand-wash);color:var(--brand-ink)}
.qa button .ic{color:var(--muted)}.qa button:hover .ic{color:var(--brand)}
/* ---- record shell (customer 360 & friends) ---- */
.tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin-bottom:18px;flex-wrap:wrap}
.tabs button{padding:9px 14px;font-weight:600;font-size:13.5px;color:var(--muted);border:none;border-bottom:2px solid transparent;margin-bottom:-1px;background:none;cursor:pointer}
.tabs button:hover{color:var(--ink)}
.tabs button.on{color:var(--brand-ink);border-color:var(--brand)}
.tabs button .n{color:var(--faint);font-weight:600;margin-left:3px}
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}
.answers{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:16px}
.ans{background:var(--surface);padding:13px 15px;display:flex;flex-direction:column;gap:3px}
.ans .q{font-size:11px;font-weight:600;color:var(--muted)}
.ans .a{font-size:15px;font-weight:700;letter-spacing:-.01em;display:flex;align-items:center;gap:6px}
.ans .a.g{color:var(--brand-ink)}.ans .a.r{color:var(--danger)}.ans .a.w{color:var(--warn)}
.ans .subq{font-size:11px;font-weight:500;color:var(--faint)}
.nba{background:linear-gradient(135deg,#eafaf0,#e8f6fe);border:1px solid #cfeade;border-radius:var(--radius);padding:14px 15px}
.nba .t{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--brand-ink);margin-bottom:6px;display:flex;align-items:center;gap:6px}
.nba .m{font-size:13px;color:var(--ink-2);margin-bottom:11px;line-height:1.4}
.hgrid{display:flex;align-items:center;gap:16px}
.hring{position:relative;flex:none}
.hring .hs{position:absolute;inset:0;display:grid;place-items:center;font-size:19px;font-weight:750}
.hmeta{display:grid;grid-template-columns:auto 1fr;gap:7px 12px;font-size:12.5px;flex:1;margin:0}
.hmeta dt{color:var(--muted)}.hmeta dd{font-weight:600;text-align:right;margin:0}
.hmeta dd.ok{color:var(--brand-ink)}.hmeta dd.warn{color:var(--warn)}.hmeta dd.danger{color:var(--danger)}
.dl{display:grid;grid-template-columns:auto 1fr;gap:9px 14px;font-size:13px;margin:0}
.dl dt{color:var(--muted)}.dl dd{font-weight:600;text-align:right;margin:0;word-break:break-word}
.stmt{width:100%;border-collapse:collapse;font-size:13px}
.stmt th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);padding:9px 14px;border-bottom:1px solid var(--line)}
.stmt td{padding:10px 14px;border-bottom:1px solid var(--line-2);color:var(--ink-2)}
.stmt td.num,.stmt th.num{text-align:right;font-variant-numeric:tabular-nums}
.stmt tr.bal td{font-weight:700;background:var(--surface-2);color:var(--ink)}
.stmt tr.clk{cursor:pointer}.stmt tr.clk:hover{background:var(--brand-wash)}
.dtbl{width:100%;border-collapse:collapse;font-size:13px}
.dtbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);padding:9px 14px;border-bottom:1px solid var(--line)}
.dtbl td{padding:10px 14px;border-bottom:1px solid var(--line-2);color:var(--ink-2)}
.dtbl td.num,.dtbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.dtbl tr.clk{cursor:pointer}.dtbl tr.clk:hover{background:var(--brand-wash)}
.dtbl tr:last-child td{border-bottom:none}
.linkcard{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;cursor:pointer;transition:.1s;background:var(--surface);margin-bottom:8px}
.linkcard:last-child{margin-bottom:0}
.linkcard:hover{border-color:var(--brand);box-shadow:var(--sh-sm)}
.linkcard .lci{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;flex:none}
.linkcard .lct{min-width:0;flex:1}
.linkcard .lct .t{font-weight:600;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.linkcard .lct .s{color:var(--muted);font-size:12px}
.linkcard .lcr{margin-left:auto;text-align:right;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.linkcard .lcr.ok{color:var(--brand-ink)}.linkcard .lcr.danger{color:var(--danger)}
.moremenu{position:relative}
.btn.btn-ic{padding:0;width:37px;justify-content:center}
.moremenu .menu{position:absolute;right:0;top:42px;background:#fff;border:1px solid var(--line);border-radius:11px;box-shadow:var(--sh-lg);padding:6px;z-index:20;min-width:206px}
.moremenu .menu button{display:flex;align-items:center;gap:11px;width:100%;padding:9px 10px;border-radius:7px;font-size:13px;font-weight:500;color:var(--ink-2);text-align:left;background:none;cursor:pointer}
.moremenu .menu button:hover{background:var(--brand-wash);color:var(--brand-ink)}
.moremenu .menu button.dgr{color:var(--danger)}.moremenu .menu button.dgr:hover{background:var(--danger-wash)}
.card-h{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line)}
.card-h h3{font-size:14px;font-weight:650}
.card-h .sub{font-size:12.5px;color:var(--muted)}
.card-h .r{margin-left:auto}
.pipe{display:flex;align-items:stretch;gap:0;border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;background:var(--surface);margin-bottom:14px}
.pipe .pst{flex:1;padding:13px 14px;display:flex;flex-direction:column;gap:4px;position:relative;border-right:1px solid var(--line)}
.pipe .pst:last-child{border-right:none}
.pipe .pst .pl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);display:flex;align-items:center;gap:6px}
.pipe .pst .pv{font-size:13px;font-weight:600;color:var(--ink-2)}
.pipe .pst.done{background:var(--brand-wash)}
.pipe .pst.done .pl{color:var(--brand-ink)}
.pipe .pst.now{background:#fff;box-shadow:inset 0 -3px 0 var(--brand)}
.pipe .pst.now .pl{color:var(--ink)}
.pipe .pst.wait{opacity:.55}
.pipe .pst .pc{position:absolute;top:12px;right:12px;width:18px;height:18px;border-radius:50%;display:grid;place-items:center}
.pipe .pst.done .pc{background:var(--brand);color:#fff}
.pipe .pst.now .pc{border:2px solid var(--brand)}
.pipe .pst.wait .pc{border:2px solid var(--line)}
.pipe .pst .pc .ic{width:11px;height:11px}
/* ---- quotation builder modal ---- */
.qb-pick{position:relative;margin-bottom:14px}
#qbq{width:100%;height:38px;border:1px solid var(--line);border-radius:8px;padding:0 12px;background:var(--surface-2)}
#qbq:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-wash);background:#fff}
.qb-res{position:absolute;left:0;right:0;top:42px;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:var(--sh-lg);z-index:5;max-height:240px;overflow:auto;display:none}
.qb-res.on{display:block}
.qb-opt{display:flex;justify-content:space-between;gap:10px;padding:9px 12px;cursor:pointer}
.qb-opt:hover{background:var(--brand-wash)}
.qb-on{font-weight:600;font-size:13.5px}.qb-op{color:var(--muted);font-size:12.5px;white-space:nowrap}
.qb-empty{padding:14px;color:var(--muted);font-size:13px;text-align:center}
.qb-none{display:flex;flex-direction:column;align-items:center;gap:8px;padding:26px;color:var(--muted);font-size:13px;border:1px dashed var(--line);border-radius:10px}
.qb-none .ic{color:var(--faint)}
.qb-head,.qb-row{display:grid;grid-template-columns:1fr 66px 104px 100px 28px;gap:8px;align-items:center}
.qb-head{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);padding:0 2px 6px;font-weight:700}
.qb-head span:nth-child(2),.qb-head span:nth-child(3),.qb-head span:nth-child(4){text-align:right}
.qb-row{padding:7px 2px;border-top:1px solid var(--line-2)}
.qb-nm{font-weight:600;font-size:13px;min-width:0;overflow:hidden;text-overflow:ellipsis}
.qb-u{color:var(--muted);font-weight:500;font-size:11.5px;margin-left:6px}
.qb-qty,.qb-price{height:32px;border:1px solid var(--line);border-radius:7px;padding:0 8px;text-align:right;font-variant-numeric:tabular-nums;width:100%}
.qb-qty:focus,.qb-price:focus{outline:none;border-color:var(--brand)}
.qb-amt{text-align:right;font-weight:700;font-variant-numeric:tabular-nums;font-size:13px}
.qb-x{width:26px;height:26px;border-radius:6px;display:grid;place-items:center;color:var(--faint)}
.qb-x:hover{background:var(--danger-wash);color:var(--danger)}
.qb-bar{display:flex;align-items:center;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}
.qb-cur{font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:8px}
.qb-cur select{height:34px;border:1px solid var(--line);border-radius:7px;padding:0 8px;background:#fff}
.qb-tot{font-size:13px;color:var(--muted)}.qb-tot b{font-size:20px;color:var(--ink);margin-left:8px;font-variant-numeric:tabular-nums}
.qb-wa{display:flex;align-items:center;gap:9px;margin-top:14px;font-size:13px;color:var(--ink-2)}
.qb-wa input{width:16px;height:16px}
.qb-custwrap{margin-bottom:14px}
.qb-chip{display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid var(--brand);background:var(--brand-wash);border-radius:9px;font-size:13.5px;color:var(--brand-ink)}
.qb-chip .ic{color:var(--brand)}
.qb-chip b{color:var(--ink)}
.qb-chipx{margin-left:auto;height:26px;padding:0 10px;border:1px solid var(--line);border-radius:6px;background:#fff;font-size:12px;font-weight:600;color:var(--ink-2)}
.qb-chipx:hover{border-color:var(--brand);color:var(--brand-ink)}
/* ---- list: grid totals + pagination + date range ---- */
.chip input[type=date]{border:none;background:none;font:inherit;color:var(--ink);cursor:pointer}
.pager{display:flex;align-items:center;gap:10px;justify-content:flex-end;padding:11px 16px;border-top:1px solid var(--line);font-size:12.5px;color:var(--muted);flex-wrap:wrap}
.pager:empty{display:none;padding:0;border:none}
.pager button{height:30px;padding:0 12px;border:1px solid var(--line);border-radius:7px;background:var(--surface);font-weight:600;font-size:12.5px;color:var(--ink-2)}
.pager button:disabled{opacity:.4;cursor:not-allowed}
.pager button:not(:disabled):hover{background:var(--brand-wash);border-color:var(--brand);color:var(--brand-ink)}
.gridtotals{max-width:340px;margin-top:14px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--sh-sm);padding:14px 16px}
.gridtotals .gt-h{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-bottom:8px}
.gt-row{display:flex;justify-content:space-between;align-items:baseline;padding:7px 0;font-size:13px;border-top:1px solid var(--line-2)}
.gt-row:first-of-type{border-top:none}
.gt-row span{color:var(--muted)}.gt-row b{font-variant-numeric:tabular-nums;font-size:14px}

/* timeline */
.tl{position:relative}
.tl .e{position:relative;padding:0 0 18px 38px}
.tl .e::before{content:"";position:absolute;left:14px;top:28px;bottom:-2px;width:2px;background:var(--line)}
.tl .e:last-child::before{display:none}
.tl .e .node{position:absolute;left:0;top:1px;width:30px;height:30px;border-radius:9px;display:grid;place-items:center;border:2px solid var(--surface);background:var(--brand-wash);color:var(--brand-ink)}
.tl .e .tt{font-weight:600;font-size:13.5px}
.tl .e .ts{font-size:12px;color:var(--muted);margin-top:2px}

/* related / queue rows */
.qrow{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line-2);cursor:pointer;transition:.08s}
.qrow:hover{background:var(--brand-wash)}
.qrow:last-child{border-bottom:none}
.qrow .qi{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex:none;background:var(--line-2);color:var(--ink-2)}
.qrow .m{flex:1;min-width:0}
.qrow .t{font-weight:600;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qrow .s{font-size:12px;color:var(--muted)}
.qrow .r{text-align:right;white-space:nowrap;font-weight:600;font-variant-numeric:tabular-nums;font-size:13px}
.qrow .chev{color:var(--faint)}

/* documents */
.doc{display:flex;align-items:center;gap:12px;padding:12px 13px;border:1px solid var(--line);border-radius:10px;margin-bottom:9px;cursor:pointer;transition:.1s}
.doc:last-child{margin-bottom:0}
.doc:hover{border-color:var(--brand);background:var(--brand-wash)}
.doc .di{width:36px;height:36px;border-radius:9px;background:var(--line-2);display:grid;place-items:center;color:var(--ink-2);flex:none}
.doc .dt{font-weight:600;font-size:13.5px}

/* ================= ASSISTANT BRIEFING ================= */
.assist{display:flex;gap:14px;align-items:flex-start;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--brand);border-radius:var(--radius);box-shadow:var(--sh-sm);padding:15px 18px;margin-bottom:16px}
.assist.high{border-left-color:var(--danger)}.assist.med{border-left-color:var(--warn)}.assist.low{border-left-color:var(--brand)}
.assist .ai{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;flex:none;background:var(--brand-wash);color:var(--brand-ink);font-weight:800;font-size:17px}
.assist.high .ai{background:var(--danger-wash);color:var(--danger)}.assist.med .ai{background:var(--warn-wash);color:var(--warn)}
.assist .body{flex:1;min-width:0}
.assist .sit{font-weight:650;font-size:14.5px;display:flex;align-items:center;gap:9px;flex-wrap:wrap;line-height:1.35}
.assist .riskchip{margin-left:auto}
.assist .why{font-size:13px;color:var(--muted);margin-top:5px;line-height:1.5}
.astat{display:flex;gap:22px;flex-wrap:wrap;margin-top:11px}
.astat .s{display:flex;flex-direction:column;gap:1px}
.astat .s .l{color:var(--faint);font-weight:700;text-transform:uppercase;font-size:9.5px;letter-spacing:.05em}
.astat .s .v{font-weight:700;font-size:14px;font-variant-numeric:tabular-nums}
.astat .s .v.danger{color:var(--danger)}.astat .s .v.warn{color:var(--warn)}.astat .s .v.ok{color:var(--brand-ink)}
.arec{margin-top:12px;font-size:12.5px;color:var(--ink-2);background:var(--surface-2);border:1px solid var(--line);border-radius:9px;padding:10px 12px;line-height:1.5}
.arec .rl{font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--brand-ink);display:flex;align-items:center;gap:6px;margin-bottom:4px}
.aacts{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}

/* ================= EMPTY / LOADING ================= */
.empty{text-align:center;padding:48px 20px;color:var(--muted)}
.empty .eic{width:52px;height:52px;border-radius:13px;background:var(--line-2);display:grid;place-items:center;margin:0 auto 14px;color:var(--faint)}
.empty h4{color:var(--ink);font-size:15px;margin-bottom:4px}
.sk{background:linear-gradient(90deg,var(--line-2) 25%,#e4e7ec 37%,var(--line-2) 63%);background-size:400% 100%;animation:sh 1.3s ease infinite;border-radius:8px}
@keyframes sh{0%{background-position:100% 0}100%{background-position:-100% 0}}
.sk-head{height:90px;margin-bottom:16px}
.sk-kpi{height:104px}
.sk-card{height:220px}

/* ================= MODAL ================= */
.ov{position:fixed;inset:0;background:rgba(17,24,28,.44);backdrop-filter:blur(2px);display:none;align-items:flex-start;justify-content:center;padding:12vh 20px 20px;z-index:60;overflow-y:auto}
.ov.on{display:flex}
.modal{background:var(--surface);border-radius:var(--radius-lg);box-shadow:var(--sh-lg);width:100%;max-width:460px;animation:pop .16s ease}
@keyframes pop{from{opacity:0;transform:translateY(8px) scale(.99)}to{opacity:1;transform:none}}
.modal .h{display:flex;align-items:center;gap:12px;padding:17px 20px;border-bottom:1px solid var(--line);font-weight:700;font-size:16px}
.modal .h .x{margin-left:auto;width:32px;height:32px;border-radius:8px;display:grid;place-items:center;color:var(--muted)}
.modal .h .x:hover{background:var(--line-2);color:var(--ink)}
.modal .b{padding:20px}
.modal .f{display:flex;gap:9px;justify-content:flex-end;padding:15px 20px;border-top:1px solid var(--line);background:var(--surface-2);border-radius:0 0 var(--radius-lg) var(--radius-lg)}
.fld{margin-bottom:14px}.fld:last-child{margin-bottom:0}
.fld label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-2);margin-bottom:6px;text-transform:capitalize}
.fld input,.fld select{width:100%;height:39px;border:1px solid var(--line);border-radius:7px;padding:0 11px;background:var(--surface);transition:.15s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-wash)}
.imgfld{display:flex;gap:12px;align-items:center}
.imgprev{width:56px;height:56px;border-radius:9px;border:1px solid var(--line);background:#f3f4f6 center/cover no-repeat;flex:none}
.imgbtns{display:flex;flex-direction:column;gap:6px;align-items:flex-start}
.gallery{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}
.gthumb{position:relative;width:72px;height:72px;border-radius:10px;border:1px solid var(--line);background:#f3f4f6 center/cover no-repeat;flex:none}
.gthumb.gload{display:grid;place-items:center;color:var(--muted);font-size:18px}
.gthumb .grm{position:absolute;top:-7px;right:-7px;width:20px;height:20px;border-radius:50%;background:var(--danger);color:#fff;font-size:13px;line-height:1;display:grid;place-items:center;border:2px solid #fff;cursor:pointer}
.gthumb .gmain{position:absolute;bottom:0;left:0;right:0;background:rgba(22,163,74,.9);color:#fff;font-size:9px;font-weight:700;text-align:center;border-radius:0 0 9px 9px;padding:1px}
.gadd{width:72px;height:72px;border-radius:10px;border:1.5px dashed var(--line);display:grid;place-items:center;cursor:pointer;color:var(--muted);flex:none;gap:0}
.gadd:hover{border-color:var(--brand);color:var(--brand-ink);background:var(--brand-wash)}
.gadd span{display:block;text-align:center;line-height:1.1}.gadd .gaddt{font-size:9.5px;font-weight:600}
.gstrip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.gsimg{width:96px;height:96px;border-radius:11px;border:1px solid var(--line);background:#f3f4f6 center/cover no-repeat;cursor:pointer;flex:none;box-shadow:var(--sh-sm)}

/* ================= TOAST ================= */
#toasts{position:fixed;bottom:22px;right:22px;z-index:80;display:flex;flex-direction:column;gap:9px}
.toast{display:flex;align-items:center;gap:10px;background:var(--ink);color:#fff;padding:11px 15px;border-radius:9px;box-shadow:var(--sh-lg);font-size:13px;font-weight:500;animation:slidein .2s ease;max-width:340px}
.toast .ic{color:#5fe39a}
.toast.err .ic{color:#ff8f8f}
@keyframes slidein{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}

/* ================= SIDEBAR COUNT ================= */
.nitem .nl{flex:1}
.nitem .cnt{font-size:11px;background:rgba(255,255,255,.1);color:#cfe0d7;padding:1px 8px;border-radius:20px;font-weight:600;min-width:20px;text-align:center}
.nitem.on .cnt{background:var(--brand);color:#fff}

/* ================= LIST / DIRECTORY WORKSPACE ================= */
.lhead{display:flex;align-items:flex-start;gap:16px;margin:2px 0 18px;flex-wrap:wrap}
.lhead .ltitle{font-size:22px;font-weight:700;letter-spacing:-.02em}
.lhead .lsub{color:var(--muted);font-size:13px;margin-top:3px}
.lhead .acts{margin-left:auto}
.toolbar{display:flex;align-items:center;gap:9px;padding:13px 16px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.tsearch{position:relative;flex:1;min-width:200px;max-width:360px}
.tsearch svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--faint)}
.tsearch input{width:100%;height:36px;border:1px solid var(--line);border-radius:8px;padding:0 12px 0 34px;background:var(--surface-2)}
.tsearch input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-wash);background:#fff}
.chip{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 11px;border:1px solid var(--line);border-radius:8px;font-size:12.5px;font-weight:600;color:var(--ink-2);background:var(--surface)}
.chip select{border:none;background:none;font-weight:600;color:inherit;outline:none;cursor:pointer}
.tinfo{font-size:12.5px;color:var(--muted);margin-right:4px}
.tbl-wrap{overflow-x:auto}
table.dt{width:100%;border-collapse:collapse;font-size:13px}
.dt thead th{text-align:left;font-size:11px;font-weight:650;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;padding:11px 16px;border-bottom:1px solid var(--line);white-space:nowrap;background:var(--surface-2);user-select:none}
.dt thead th.srt{cursor:pointer}
.dt thead th.srt:hover{color:var(--ink)}
.dt thead th.num,.dt td.num{text-align:right;font-variant-numeric:tabular-nums}
.dt thead th .thc{display:inline-flex;align-items:center;gap:5px}
.dt thead th.sorted{color:var(--brand-ink)}
.dt thead th .sar{font-size:11px}
.dt tbody td{padding:12px 16px;border-bottom:1px solid var(--line-2);color:var(--ink-2);white-space:nowrap}
.dt tbody tr:last-child td{border-bottom:none}
.dt tbody tr{cursor:pointer;transition:.08s}
.dt tbody tr:hover{background:var(--brand-wash)}
.dt tbody tr:hover td{color:var(--ink)}
.dt td.warn{color:var(--warn);font-weight:600}.dt td.danger{color:var(--danger);font-weight:600}.dt td.ok{color:var(--brand-ink);font-weight:600}
.dt .prim{font-weight:600;color:var(--ink)}
.dt .mut{color:var(--muted);font-size:12px}
.cellflex{display:flex;align-items:center;gap:11px}
.av-s{width:32px;height:32px;border-radius:8px;display:inline-grid;place-items:center;color:#fff;font-weight:700;font-size:11.5px;flex:none}

@media(max-width:880px){
  .app{grid-template-columns:1fr}.side{display:none}
  .rec{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><span class="m">◆</span> CloudBSS</div>
    <div class="nav" id="nav"></div>
    <div class="nfoot"><div class="nuser"><div class="av" id="uav">U</div><div><div class="nm" id="unm">User</div><div class="rl" id="url"></div></div></div></div>
  </aside>
  <div class="main">
    <header class="top">
      <div class="sb">
        <svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="search" placeholder="Open a record…  e.g. customer 5">
      </div>
      <div class="sp"></div>
      <button class="tbtn" onclick="refresh()" title="Refresh"><svg class="ic" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg></button>
    </header>
    <main class="view"><div class="wrap" id="view"></div></main>
  </div>
</div>
<div class="ov" id="ov"><div class="modal" id="modal"></div></div>
<div id="toasts"></div>

<script>
const CFG = { base:'/api/v1/workspace', csrf: document.querySelector('meta[name=csrf-token]').content, user: @json(['name'=>'']) };
const LABELS = { setup:'Get started', briefing:'Morning Briefing', worklist:'My Work', customers:'Customers', collections:'Collections',
  salesOrders:'Sales Orders', quotations:'Quotations', invoices:'Invoices', payments:'Payments', finance:'Finance',
  products:'Products', categories:'Categories', 'purchase.process':'Purchasing', suppliers:'Suppliers', warehouse:'Warehouse', dispatch:'Dispatch',
  customer:'Customer', invoice:'Invoice', payment:'Payment', product:'Product', supplier:'Supplier', quotation:'Quotation',
  salesOrder:'Sales Order', purchaseOrder:'Purchase Order', 'sales.process':'Sales Pipeline', statement:'Statement' };
const GROUPS = [ ['Overview',['setup','briefing','worklist']],
  ['Sales',['customers','salesOrders','quotations','invoices','payments','collections']],
  ['Inventory',['products','categories','purchase.process','suppliers']],
  ['Operations',['warehouse','dispatch']],
  ['Finance',['finance']] ];
// Which nav key reads which count from the catalog's counts map.
const NAVCOUNT = { customers:'customers', salesOrders:'salesOrder', quotations:'quotations', invoices:'invoice', payments:'payment', products:'product', categories:'categories', suppliers:'supplier', 'purchase.process':'purchase.process' };
// Full workspaces that resolve to a real screen (all menu items are now built).
const READY = new Set(['setup','briefing','worklist','customers','salesOrders','quotations','invoices','payments','products','categories','suppliers','purchase.process','collections','finance','warehouse','dispatch']);
// Dedicated list views still pending — none; kept for future additions.
const SOON = {};
function fmtCount(n){ n=Number(n); return n>=1000 ? (n/1000).toFixed(n>=10000?0:1)+'k' : String(n); }

/* ---- inline icon set ---- */
const P = {
  setup:'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  briefing:'M12 3v18M3 12h18', worklist:'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
  collections:'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
  finance:'M3 3v18h18M7 14l4-4 3 3 5-6', 'sales.process':'M3 3v18h18M18 9l-6 6-4-4-3 3',
  'purchase.process':'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  warehouse:'M3 9l9-6 9 6v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM9 21V9h6v12',
  dispatch:'M1 3h15v13H1zM16 8h4l3 3v5h-7M5.5 18.5a2 2 0 1 0 0 .01M18.5 18.5a2 2 0 1 0 0 .01',
  customer:'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
  customers:'M17 21v-2a4 4 0 0 0-3-3.87M9 21v-2a4 4 0 0 1 4-4h0M12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6M21 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  invoice:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h6',
  invoices:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h6',
  quotations:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 15l2 2 4-4',
  quotation:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 15l2 2 4-4',
  payment:'M2 6h20v12H2zM2 10h20', payments:'M2 6h20v12H2zM2 10h20',
  supplier:'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01',
  suppliers:'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01',
  product:'M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
  products:'M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
  categories:'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  salesOrder:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  salesOrders:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  purchaseOrder:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  statement:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  doc:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  clock:'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2', bolt:'M13 2 3 14h9l-1 8 10-12h-9z',
  check:'M20 6 9 17l-5-5', chev:'m9 18 6-6-6-6',
  trend:'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
  book:'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z',
  phone:'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.94.36 1.86.7 2.73a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.35-1.35a2 2 0 0 1 2.11-.45c.87.34 1.79.57 2.73.7A2 2 0 0 1 22 16.92z',
  edit:'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z',
  bell:'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
  card:'M3 4h18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM1 10h22',
  plus:'M12 5v14M5 12h14',
  dots:'M12 5a1 1 0 100 2 1 1 0 100-2M12 11a1 1 0 100 2 1 1 0 100-2M12 17a1 1 0 100 2 1 1 0 100-2',
  tag:'M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z',
  mail:'M3 4h18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 7l-10 6L2 7',
  x:'M18 6L6 18M6 6l12 12',
  cart:'M9 20a1 1 0 100 2 1 1 0 100-2M20 20a1 1 0 100 2 1 1 0 100-2M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6',
  pin:'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0zM12 7a3 3 0 100 6 3 3 0 100-6',
  dollar:'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
  user:'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 100 8 4 4 0 100-8',
  spark:'M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9z',
  send:'M22 2L11 13M22 2l-7 20-4-9-9-4z',
  download:'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
  truck:'M1 3h15v10H1zM16 8h4l3 3v2h-7M3 18a2 2 0 104 0 2 2 0 10-4 0M15 18a2 2 0 104 0 2 2 0 10-4 0' };
function icon(name,cls){ return '<svg class="ic '+(cls||'')+'" viewBox="0 0 24 24"><path d="'+(P[name]||P.doc)+'"/></svg>'; }
const AVC = ['#16a34a','#0d9488','#0284c7','#4f46e5','#d97706','#db2777','#7c3aed','#0891b2'];
function avColor(s){ let h=0; for(const c of (s||'?')) h=(h*31+c.charCodeAt(0))>>>0; return AVC[h%AVC.length]; }
function initials(s){ s=(s||'?').replace(/[^\w ]/g,'').trim(); const p=s.split(/\s+/); return ((p[0]?.[0]||'')+(p[1]?.[0]||'')||s[0]||'?').toUpperCase(); }

let CUR = { key:null, id:null };
let REC_TAB = 'overview';   // active tab in the record shell
let LAST_REC = null;        // last record payload, so tab switches re-render without a refetch

async function api(path){
  const r = await fetch(CFG.base+path, { credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
  if(!r.ok && r.status!==200){ return { _error:r.status }; }
  return r.json();
}
async function post(endpoint, body){
  const r = await fetch(endpoint, { method:'POST', credentials:'include',
    headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':CFG.csrf,'Content-Type':'application/json' },
    body: JSON.stringify(body||{}) });
  return r.json();
}
function toast(m,err){ const t=document.createElement('div'); t.className='toast'+(err?' err':'');
  t.innerHTML=icon(err?'bolt':'check')+'<span>'+m+'</span>'; document.getElementById('toasts').appendChild(t);
  setTimeout(()=>{ t.style.opacity='0'; t.style.transition='.3s'; setTimeout(()=>t.remove(),300); },2600); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toneCls(t){ return ['warn','danger','ok'].includes(t)?t:''; }
function toneBadge(t){ const m={danger:'b-danger',warn:'b-warn',ok:'b-ok',info:'b-info',accent:'b-accent'}; return m[t]||'b-default'; }
function statusBadge(s){ if(!s) return ''; const map={overdue:'b-danger',out:'b-danger',blocked:'b-danger',partial:'b-warn',low:'b-warn',draft:'b-default',pending:'b-warn',active:'b-ok',paid:'b-ok','in':'b-ok',received:'b-ok',delivered:'b-ok',inactive:'b-default',dormant:'b-warn',prospect:'b-info','new':'b-info'};
  return '<span class="badge '+(map[s]||'b-default')+'"><span class="d"></span>'+esc(s)+'</span>'; }
function apiToHash(p){ if(!p) return null; const i=p.indexOf('/workspace/'); if(i<0) return null; const parts=p.slice(i+11).split('/'); return { key:parts[0], id:parts[1]||null, action:parts[2]||null }; }
function fval(f){ let v=f.value; if(f.format==='pct') return v+'%';
  if(f.currency && typeof v==='number'){ const sym=f.currency==='USD'?'$':''; const suf=f.currency==='USD'?'':(' '+f.currency);
    return (sym?sym:'')+Number(v).toLocaleString(undefined,{maximumFractionDigits:0})+(sym?'':suf).replace(/^ /,' '); }
  return v; }
function curFmt(v,cur){ cur=cur||'UGX'; if(cur==='USD') return '$'+Number(v).toLocaleString(); return Number(v).toLocaleString()+' '+cur; }

async function boot(){
  document.getElementById('view').innerHTML = skeleton();
  const cat = await api('/');
  CFG.user = cat.user || CFG.user;
  CFG.counts = cat.counts || {};
  const nm = CFG.user.name||'User';
  document.getElementById('unm').textContent = nm;
  document.getElementById('url').textContent = (CFG.user.role||'') + (CFG.user.org ? ' · ' + CFG.user.org : '');
  document.getElementById('uav').textContent = initials(nm);
  document.getElementById('uav').style.background = 'linear-gradient(135deg,#16a34a,#0d9488)';
  renderNav(cat.capabilities||[]);
  const h = parseHash();
  route(h.key || landingKey(), h.id);
}
// A brand-new shop (nothing sold or stocked yet) lands on the Get-started checklist;
// an established shop lands on the Morning Briefing.
function landingKey(){
  const c = CFG.counts||{};
  const started = ['product','customers','supplier','salesOrder','quotations','invoice','payment']
    .some(k => Number(c[k]||0) > 0);
  return started ? 'briefing' : 'setup';
}
function parseHash(){ const p=location.hash.replace('#/','').split('/'); return { key:p[0]||'', id:p[1]||null }; }
function renderNav(caps){
  const keys = new Set(caps.map(c=>c.key));
  // A SOON item shows if its underlying entity exists (has a count) even without a list capability yet.
  const has = k => keys.has(k) || (SOON[k] && CFG.counts[NAVCOUNT[k]||k] != null);
  let html='';
  GROUPS.forEach(([g,items])=>{
    const avail=items.filter(has);
    if(!avail.length) return;
    html+='<div class="ngrp">'+g+'</div>';
    avail.forEach(k=>{
      const c = CFG.counts[NAVCOUNT[k]||k];
      const cnt = (c!=null && c!=='') ? '<span class="cnt">'+fmtCount(c)+'</span>' : '';
      html+='<div class="nitem" data-key="'+k+'" onclick="route(\''+k+'\')">'+icon(k)+'<span class="nl">'+(LABELS[k]||k)+'</span>'+cnt+'</div>';
    });
  });
  document.getElementById('nav').innerHTML=html;
}
function setActive(key){ document.querySelectorAll('.nitem').forEach(n=>n.classList.toggle('on', n.dataset.key===key)); }

async function route(key, id){
  CUR={key,id}; REC_TAB='overview'; location.hash='#/'+key+(id?'/'+id:''); setActive(key);
  if(SOON[key] && !id){ renderSoon(key); return; }
  document.getElementById('view').innerHTML=skeleton();
  const p = await api('/'+key+(id?'/'+id:''));
  if(p._error){ document.getElementById('view').innerHTML=emptyState('bolt','Could not load','You may not have access to this workspace ('+p._error+').'); return; }
  renderView(p);
}
function renderSoon(key){
  const s=SOON[key]; const c=CFG.counts[NAVCOUNT[key]||key];
  document.getElementById('view').innerHTML=
    '<div class="crumb">'+(LABELS[key]||key)+'</div>'+
    '<div class="card"><div class="b" style="text-align:center;padding:56px 24px">'+
    '<div class="eic" style="width:56px;height:56px;margin:0 auto 16px">'+icon(key,'ic-lg')+'</div>'+
    '<h1 style="font-size:20px;margin-bottom:6px">'+(LABELS[key]||key)+(c!=null?' <span class="mut" style="font-weight:600">('+fmtCount(c)+')</span>':'')+'</h1>'+
    '<div class="mut" style="max-width:420px;margin:0 auto 18px">This dedicated list view is next in the build. In the meantime, open the related workspace to work these records.</div>'+
    '<button class="btn pri" onclick="route(\''+s.to+'\')">Open '+s.toLabel+'</button>'+
    '</div></div>';
}
function refresh(){ if(CUR.key) route(CUR.key, CUR.id); }

function skeleton(){ return '<div class="sk sk-head"></div><div class="kpis"><div class="sk sk-kpi"></div><div class="sk sk-kpi"></div><div class="sk sk-kpi"></div></div><div class="sk sk-card"></div>'; }
function emptyState(ic,h,s){ return '<div class="empty"><div class="eic">'+icon(ic,'ic-lg')+'</div><h4>'+esc(h)+'</h4><div>'+esc(s||'')+'</div></div>'; }

function renderView(p){
  if(p.table){ return renderList(p); }
  if(p.tabs && p.tabs.length){ return renderRecord(p); }
  const o=p.object||{}; const isProcess=(o.type||'').startsWith('process:');
  const cap=(p.meta&&p.meta.capability)||CUR.key;
  let h='';

  /* ---- header ---- */
  h+='<div class="crumb">'+(LABELS[cap]||cap)+(o.title&&!isProcess?' <span style="color:var(--faint)">/</span> '+esc(o.title):'')+'</div>';
  h+='<div class="rec-head">';
  if(!isProcess && o.image){ h+='<div class="rec-av" style="background:#eef0f3;background-image:url(\''+esc(o.image)+'\');background-size:cover;background-position:center"></div>'; }
  else if(!isProcess && o.title){ h+='<div class="rec-av" style="background:'+avColor(o.title)+'">'+initials(o.title)+'</div>'; }
  else { h+='<div class="rec-av" style="background:'+avColor(cap)+'">'+icon(cap,'ic-lg')+'</div>'; }
  h+='<div class="idn"><h1>'+esc(o.title||LABELS[cap]||cap)+' '+statusBadge(o.status)+'</h1>'+(o.subtitle?'<div class="sub">'+esc(o.subtitle)+'</div>':'')+'</div>';
  if(p.headline){ h+='<div class="rec-hl"><div class="v '+toneCls(p.headline.tone)+'">'+esc(p.headline.value)+'</div><div class="l">'+esc(p.headline.label)+'</div></div>'; }
  if((p.actions||[]).length){ h+='<div class="acts inl" style="margin-left:'+(p.headline?'12px':'auto')+'">'+p.actions.map(actBtn).join('')+'</div>'; }
  h+='</div>';

  if(isProcess){
    /* KPI cards */
    if(p.financials&&p.financials.length){
      h+='<div class="kpis">'+p.financials.map(f=>{ const tc=toneCls(f.tone);
        return '<div class="kpi"><div class="top"><span class="ib '+tc+'">'+icon(cap,'ic-sm')+'</span>'+esc(f.label)+'</div><div class="val '+tc+'">'+esc(fval(f))+'</div>'+(f.foot?'<div class="foot">'+esc(f.foot)+'</div>':'')+'</div>';
      }).join('')+'</div>';
    }
    h+=briefHtml(p.brief);
    const n=(p.related||[]).length;
    h+='<div class="card"><div class="h">'+icon(cap,'ic-sm')+(o.title||'Items')+(n?'<span class="n '+(o.tone||'')+'">'+n+'</span>':'')+'</div>'+
      (n? p.related.map(qrow).join('') : emptyState('check','All clear','Nothing pending in this queue.'))+'</div>';
  } else {
    h+=briefHtml(p.brief);
    if(Array.isArray(o.gallery) && o.gallery.length>1){
      h+='<div class="gstrip">'+o.gallery.map(u=>'<div class="gsimg" style="background-image:url(\''+esc(u)+'\')" onclick="window.open(\''+esc(u)+'\')"></div>').join('')+'</div>';
    }
    h+='<div class="rec">';
    /* rail */
    h+='<div class="rail">';
    if(p.financials&&p.financials.length){
      const f0=p.financials[0], rest=p.financials.slice(1);
      h+='<div class="card"><div class="b"><div class="rail-t">Financials</div><div class="rail-kpi">'+
        '<div><div class="big '+toneCls(f0.tone)+'">'+esc(fval(f0))+'</div><div class="r"><span class="l">'+esc(f0.label)+'</span></div></div>'+
        (rest.length?'<div class="rail-div"></div>':'')+
        rest.map(f=>'<div class="r"><span class="l">'+esc(f.label)+'</span><span class="v '+toneCls(f.tone)+'">'+esc(fval(f))+'</span></div>').join('')+
        '</div></div></div>';
    }
    /* quick actions from record actions (secondary copy in rail for reachability) */
    h+='</div>';
    /* main column */
    h+='<div class="grid">';
    if(p.timeline&&p.timeline.length){
      h+='<div class="card"><div class="h">'+icon('clock','ic-sm')+'Timeline</div><div class="b"><div class="tl">'+
        p.timeline.map(e=>'<div class="e"><div class="node">'+icon('bolt','ic-sm')+'</div><div class="tt">'+esc(e.title)+'</div><div class="ts">'+esc(e.at||'')+(e.actor?' · '+esc(e.actor):'')+'</div></div>').join('')+
        '</div></div></div>';
    }
    if(p.related&&p.related.length){
      h+='<div class="card"><div class="h">'+icon('worklist','ic-sm')+'Related</div>'+p.related.map(qrow).join('')+'</div>';
    }
    if(p.documents&&p.documents.length){
      h+='<div class="card"><div class="h">'+icon('doc','ic-sm')+'Documents</div><div class="b">'+
        p.documents.map(d=>'<div class="doc" onclick="window.open(\''+esc(d.url||'#')+'\')"><div class="di">'+icon('doc','ic-sm')+'</div><div><div class="dt">'+esc(d.title)+'</div></div></div>').join('')+
        '</div></div>';
    }
    if(!(p.timeline||[]).length && !(p.related||[]).length && !(p.documents||[]).length){
      h+='<div class="card"><div class="b">'+emptyState('check','No linked records','This record has no timeline or related items yet.')+'</div></div>';
    }
    h+='</div></div>';
  }
  document.getElementById('view').innerHTML=h;
}

function briefHtml(b){ if(!b) return '';
  const lvl=b.level||'low';
  let h='<div class="assist '+lvl+'"><div class="ai">✦</div><div class="body">';
  h+='<div class="sit">'+esc(b.situation)+(b.risk?'<span class="badge '+toneBadge(b.risk.tone)+' riskchip"><span class="d"></span>'+esc(b.risk.label)+'</span>':'')+'</div>';
  if(b.why) h+='<div class="why">'+esc(b.why)+'</div>';
  if(b.metrics&&b.metrics.length){ h+='<div class="astat">'+b.metrics.map(m=>'<div class="s"><div class="l">'+esc(m.label)+'</div><div class="v '+toneCls(m.tone)+'">'+esc(m.value)+'</div></div>').join('')+'</div>'; }
  if(b.recommendation) h+='<div class="arec"><div class="rl">'+icon('bolt','ic-sm')+'Recommendation</div>'+esc(b.recommendation)+'</div>';
  if(b.actions&&b.actions.length) h+='<div class="aacts">'+b.actions.map(actBtn).join('')+'</div>';
  h+='</div></div>';
  return h;
}
function qrow(r){ const link=r.link?(' onclick="openLink(\''+esc(r.link)+'\')"'):'';
  const right = r.sublabel && /[0-9]/.test(r.sublabel) ? r.sublabel : '';
  // Setup-checklist rows carry a boolean `done`: render a green tick (done) or a "Start" call-to-action (todo).
  const isStep = (typeof r.done === 'boolean');
  if(isStep){
    const qi = r.done
      ? '<div class="qi" style="background:var(--ok-wash);color:var(--brand-ink)">'+icon('check','ic-sm')+'</div>'
      : '<div class="qi">'+icon(r.type||'doc','ic-sm')+'</div>';
    const rr = r.done
      ? '<div class="r" style="color:var(--brand-ink)">Done</div>'
      : '<div class="r" style="color:var(--brand)">Start</div>';
    return '<div class="qrow"'+link+'>'+qi+
      '<div class="m"><div class="t"'+(r.done?' style="color:var(--muted)"':'')+'>'+esc(r.label||r.type)+'</div>'+
      (r.sublabel?'<div class="s">'+esc(r.sublabel)+'</div>':'')+'</div>'+
      rr+'<div class="chev">'+icon('chev','ic-sm')+'</div></div>';
  }
  return '<div class="qrow"'+link+'><div class="qi">'+icon(r.type||'doc','ic-sm')+'</div>'+
    '<div class="m"><div class="t">'+esc(r.label||r.type)+(r.count!=null?' <span style="color:var(--muted);font-weight:500">('+r.count+')</span>':'')+'</div>'+
    (r.sublabel&&!right?'<div class="s">'+esc(r.sublabel)+'</div>':'')+'</div>'+
    (right?'<div class="r">'+esc(right)+'</div>':'')+'<div class="chev">'+icon('chev','ic-sm')+'</div></div>'; }
function openLink(apiPath){ const h=apiToHash(apiPath); if(h) route(h.key, h.id); }

/* ============ record shell (tabbed 360: answers · health · KPIs · NBA · tabs) ============ */
const ACTICON={record_payment:'card',log_contact:'phone',log_whatsapp:'mail',edit_customer:'edit',update_customer:'edit',send_reminder:'bell',set_followup:'bell',assign_rep:'user',new_quotation:'quotations',open_invoice:'invoice',open_customer:'user',open_order:'cart',receipt_pdf:'download',send_receipt:'send',mark_delivered:'truck',create_invoice:'invoice'};
function actIcon(a){ return (a&&(a.icon||ACTICON[a.key]))||'chev'; }
function actJson(a){ return JSON.stringify(a).replace(/'/g,'&#39;'); }

function renderRecord(p){ LAST_REC=p; document.getElementById('view').innerHTML=recordHtml(p); }
function setRecTab(k){ REC_TAB=k; if(LAST_REC) document.getElementById('view').innerHTML=recordHtml(LAST_REC); }

function recordHtml(p){
  const o=p.object||{}, cap=(p.meta&&p.meta.capability)||CUR.key;
  const listKey=(p.meta&&p.meta.listKey)||(cap+'s');
  const tabs=p.tabs||[]; const tab=(tabs.find(t=>t.key===REC_TAB)?REC_TAB:(tabs[0]&&tabs[0].key));
  let h='<div class="crumb"><a onclick="route(\''+esc(listKey)+'\')">'+esc(LABELS[listKey]||listKey)+'</a> '+icon('chev','ic-sm')+' <span>'+esc(o.title||'')+'</span></div>';
  /* header */
  h+='<div class="rec-head">';
  if(o.image){ h+='<div class="rec-av" style="background:#eef0f3;background-image:url(\''+esc(o.image)+'\');background-size:cover;background-position:center"></div>'; }
  else { h+='<div class="rec-av" style="background:'+avColor(o.title||cap)+'">'+(o.title?initials(o.title):icon(cap,'ic-lg'))+'</div>'; }
  const segBadge=o.segment?' <span class="badge '+toneBadge('accent')+'"><span class="d"></span>'+esc(o.segment)+'</span>':'';
  h+='<div class="idn"><h1>'+esc(o.title||LABELS[cap]||cap)+' '+statusBadge(o.status)+segBadge+'</h1>'+(o.subtitle?'<div class="sub">'+esc(o.subtitle)+'</div>':'')+'</div>';
  if(p.headline){ h+='<div class="rec-hl"><div class="v '+toneCls(p.headline.tone)+'">'+esc(p.headline.value)+'</div><div class="l">'+esc(p.headline.label)+'</div></div>'; }
  h+='<div class="acts inl" style="margin-left:'+(p.headline?'12px':'auto')+'">'+(p.actions||[]).map(actBtn).join('')+moreHtml(p.more)+'</div>';
  h+='</div>';
  /* brief */
  h+=briefHtml(p.brief);
  /* rail + main */
  h+='<div class="rec"><div class="rail">'+railFinHtml(p.financials)+nbaHtml(p.nba)+qaHtml(p.quickActions)+'</div>';
  h+='<div><div class="tabs">'+tabs.map(t=>'<button class="'+(t.key===tab?'on':'')+'" onclick="setRecTab(\''+esc(t.key)+'\')">'+esc(t.label)+(t.count!=null?' <span class="n">'+t.count+'</span>':'')+'</button>').join('')+'</div>';
  h+='<div>'+tabContent(p,tab)+'</div></div></div>';
  return h;
}

function railFinHtml(fins){ if(!fins||!fins.length) return '';
  const f0=fins[0], rest=fins.slice(1);
  return '<div class="card"><div class="b"><div class="rail-kpi">'+
    '<div class="big '+toneCls(f0.tone)+'">'+esc(fval(f0))+'</div><div class="r" style="margin-bottom:4px"><span class="l">'+esc(f0.label)+'</span></div>'+
    (rest.length?'<div class="rail-div"></div>':'')+
    rest.map(f=>'<div class="r"><span class="l">'+esc(f.label)+'</span><span class="v '+toneCls(f.tone)+'">'+esc(fval(f))+'</span></div>').join('')+
    '</div></div></div>'; }
function nbaHtml(n){ if(!n) return '';
  return '<div class="nba"><div class="t">'+icon('trend','ic-sm')+'Next best action</div><div class="m">'+esc(n.text)+'</div>'+
    (n.action?'<button class="btn pri sm" onclick=\'doAction('+actJson(n.action)+')\'>'+esc(n.action.label)+'</button>':'')+'</div>'; }
function qaHtml(list){ if(!list||!list.length) return '';
  return '<div class="card"><div class="b"><div class="rail-t">Quick actions</div><div class="qa">'+
    list.map(a=>'<button onclick=\'doAction('+actJson(a)+')\'>'+icon(actIcon(a),'ic-sm')+esc(a.label)+'</button>').join('')+'</div></div></div>'; }
function moreHtml(more){ if(!more||!more.length) return '';
  return '<div class="moremenu"><button class="btn btn-ic" onclick="toggleMenu(this)">'+icon('dots','ic-sm')+'</button><div class="menu" style="display:none">'+
    more.map(a=>'<button class="'+(a.tone==='danger'?'dgr':'')+'" onclick=\'closeMenus();doAction('+actJson(a)+')\'>'+icon(actIcon(a),'ic-sm')+esc(a.label)+'</button>').join('')+'</div></div>'; }
function toggleMenu(btn){ const m=btn.parentElement.querySelector('.menu'); const open=m.style.display==='block'; closeMenus(); m.style.display=open?'none':'block'; }
function closeMenus(){ document.querySelectorAll('.moremenu .menu').forEach(m=>m.style.display='none'); }
document.addEventListener('click',e=>{ if(!e.target.closest('.moremenu')) closeMenus(); });

function tabContent(p,tab){
  if(tab==='timeline') return timelineTab(p.timeline);
  if(tab==='financials') return financialsTab(p);
  if(tab==='documents') return docsTab(p.documents);
  if(p.tabSections && p.tabSections[tab]){ return sectionCards(p.tabSections[tab]); }
  /* overview (default) */
  let h='';
  h+=pipelineHtml(p.pipeline);
  if(p.stats&&p.stats.length){ h+='<div class="stat-grid">'+p.stats.map(f=>{ const tc=toneCls(f.tone);
    return '<div class="kpi"><div class="top"><span class="ib '+tc+'">'+icon(f.icon||'bolt','ic-sm')+'</span>'+esc(f.label)+'</div><div class="val '+tc+'">'+esc(f.value)+'</div>'+(f.foot?'<div class="foot">'+esc(f.foot)+'</div>':'')+'</div>';
  }).join('')+'</div>'; }
  if(p.answers&&p.answers.length){ h+='<div class="answers">'+p.answers.map(a=>
    '<div class="ans"><div class="q">'+esc(a.q)+'</div><div class="a '+(a.tone||'')+'">'+esc(a.value)+'</div>'+(a.sub?'<div class="subq">'+esc(a.sub)+'</div>':'')+'</div>').join('')+'</div>'; }
  if(p.health){ h+=healthHtml(p.health); }
  (p.overviewCards||[]).forEach(c=>{ h+='<div style="height:14px"></div>'+overviewCard(c); });
  return h;
}
function sectionCards(cards){
  if(!cards||!cards.length) return '<div class="card"><div class="b">'+emptyState('worklist','Nothing here yet','')+'</div></div>';
  return cards.map((c,i)=>(i?'<div style="height:14px"></div>':'')+overviewCard(c)).join('');
}
function healthHtml(hh){
  return '<div class="card" style="margin-top:14px"><div class="card-h"><h3>'+esc(hh.title||'Health')+'</h3></div><div class="b"><div class="hgrid">'+
    healthRing(hh.score,74)+'<dl class="hmeta">'+(hh.meta||[]).map(m=>'<dt>'+esc(m.label)+'</dt><dd class="'+toneCls(m.tone)+'">'+esc(m.value)+'</dd>').join('')+'</dl></div></div></div>'; }
function healthRing(score,size){ size=size||64; score=Math.max(0,Math.min(100,Number(score)||0)); const r=size/2-6; const C=2*Math.PI*r;
  const col=score>=75?'#16a34a':score>=50?'#d97706':'#dc2626'; const off=C*(1-score/100);
  return '<div class="hring" style="width:'+size+'px;height:'+size+'px"><svg width="'+size+'" height="'+size+'"><circle cx="'+(size/2)+'" cy="'+(size/2)+'" r="'+r+'" fill="none" stroke="#eef0f3" stroke-width="6"/><circle cx="'+(size/2)+'" cy="'+(size/2)+'" r="'+r+'" fill="none" stroke="'+col+'" stroke-width="6" stroke-linecap="round" stroke-dasharray="'+C.toFixed(1)+'" stroke-dashoffset="'+off.toFixed(1)+'" transform="rotate(-90 '+(size/2)+' '+(size/2)+')"/></svg><div class="hs" style="color:'+col+'">'+score+'</div></div>'; }

function overviewCard(c){
  const head='<div class="card-h"><h3>'+esc(c.title||'')+'</h3>'+(c.sub?'<span class="sub">'+esc(c.sub)+'</span>':'')+'</div>';
  if(c.kind==='dl'){
    return '<div class="card">'+head+'<div class="b"><dl class="dl">'+(c.rows||[]).map(r=>'<dt>'+esc(r[0])+'</dt><dd>'+esc(r[1])+'</dd>').join('')+'</dl></div></div>';
  }
  if(c.kind==='table'){
    if(!(c.rows||[]).length) return '<div class="card">'+head+'<div class="b">'+emptyState('doc',c.empty||'Nothing here','')+'</div></div>';
    const cols=c.columns||[];
    const thead='<tr>'+cols.map(col=>'<th'+(col.align==='right'?' class="num"':'')+'>'+esc(col.label)+'</th>').join('')+'</tr>';
    const body=c.rows.map(r=>'<tr class="'+(r.link?'clk':'')+'"'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'>'+
      r.cells.map((cell,i)=>{ const al=cols[i]&&cols[i].align==='right'?' class="num"':''; return '<td'+al+'>'+cellBadge(cell)+'</td>'; }).join('')+'</tr>').join('');
    return '<div class="card">'+head+'<div style="overflow-x:auto"><table class="dtbl"><thead>'+thead+'</thead><tbody>'+body+'</tbody></table></div></div>';
  }
  if(c.kind==='links'){
    if(!(c.items||[]).length) return '<div class="card">'+head+'<div class="b">'+emptyState('check',c.empty||'Nothing here','')+'</div></div>';
    return '<div class="card">'+head+'<div class="b">'+(c.items||[]).map(it=>
      '<div class="linkcard"'+(it.link?' onclick="openLink(\''+esc(it.link)+'\')"':'')+'><div class="lci" style="background:'+esc(it.color||'#16a34a')+'1e;color:'+esc(it.color||'#16a34a')+'">'+icon(it.icon||'doc','ic-sm')+'</div>'+
      '<div class="lct"><div class="t">'+esc(it.title)+'</div>'+(it.sub?'<div class="s">'+esc(it.sub)+'</div>':'')+'</div>'+
      (it.right?'<div class="lcr '+toneCls(it.rightTone)+'">'+esc(it.right)+'</div>':'')+'</div>').join('')+'</div></div>';
  }
  return '';
}
function cellBadge(cell){ if(cell&&typeof cell==='object'){ if(cell.badge) return '<span class="badge '+toneBadge(cell.badge)+'"><span class="d"></span>'+esc(cell.value)+'</span>'; return esc(cell.value); } return esc(cell); }

function timelineTab(events){
  if(!events||!events.length) return '<div class="card"><div class="b">'+emptyState('clock','No activity yet','This customer has no recorded activity.')+'</div></div>';
  return '<div class="card"><div class="b"><div class="tl">'+events.map(e=>
    '<div class="e"><div class="node">'+icon('bolt','ic-sm')+'</div><div class="tt">'+esc(e.title||'')+'</div><div class="ts">'+esc(e.at||'')+(e.actor?' · '+esc(e.actor):'')+'</div></div>').join('')+'</div></div></div>';
}
function stmtHtml(st){
  if(!st||!(st.rows||[]).length) return '<div class="card"><div class="b">'+emptyState('book','No transactions','No invoices or payments recorded yet.')+'</div></div>';
  return '<div class="card"><div class="card-h"><h3>Statement of account</h3></div><div style="overflow-x:auto"><table class="stmt"><thead><tr><th>Date</th><th>Detail</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>'+
    st.rows.map(r=>'<tr class="'+(r.link?'clk':'')+'"'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'><td>'+esc(r.date)+'</td><td>'+esc(r.detail)+'</td><td class="num">'+esc(r.debit||'')+'</td><td class="num">'+esc(r.credit||'')+'</td><td class="num">'+esc(r.balance)+'</td></tr>').join('')+
    '<tr class="bal"><td colspan="4">'+esc(st.balanceLabel||'Balance')+'</td><td class="num">'+esc(st.balanceValue)+'</td></tr>'+
    '</tbody></table></div></div>';
}
function pipelineHtml(stages){ if(!stages||!stages.length) return '';
  return '<div class="pipe">'+stages.map(s=>'<div class="pst '+esc(s.state||'wait')+'"><div class="pc">'+(s.state==='done'?icon('check','ic-sm'):'')+'</div><div class="pl">'+icon(s.icon||'chev','ic-sm')+esc(s.label)+'</div><div class="pv">'+esc(s.value||'')+'</div></div>').join('')+'</div>'; }
function financialsTab(p){
  let h='';
  if(p.statement && p.statement.rows && p.statement.rows.length) h+=stmtHtml(p.statement);
  (p.financialsCards||[]).forEach(c=>{ h+=(h?'<div style="height:14px"></div>':'')+overviewCard(c); });
  if(!h) h=stmtHtml(p.statement);
  return h;
}
function docsTab(docs){
  if(!docs||!docs.length) return '<div class="card"><div class="b">'+emptyState('doc','No documents','Invoices and statements will appear here.')+'</div></div>';
  return '<div class="card"><div class="h">'+icon('doc','ic-sm')+'Documents</div><div class="b">'+docs.map(d=>'<div class="doc" onclick="window.open(\''+esc(d.url||'#')+'\')"><div class="di">'+icon('doc','ic-sm')+'</div><div><div class="dt">'+esc(d.title)+'</div></div></div>').join('')+'</div></div>';
}

function actBtn(a){ const cls=a.actor==='ai'?'btn ai':('btn'+(a.tone==='primary'?' pri':(a.tone==='danger'?' danger':'')));
  return '<button class="'+cls+'" onclick=\'doAction('+JSON.stringify(a).replace(/'/g,"&#39;")+')\'>'+(a.actor==='ai'?'✦ ':'')+esc(a.label)+'</button>'; }
async function doAction(a){
  if((a.method||'POST')==='GET'){ const h=apiToHash(a.endpoint); if(h){ route(h.key,h.id); } return; }
  if(a.builder==='quotation'){ return quoteBuilder(a); }
  if(a.schema){ return formModal(a); }
  if(a.confirm && !window.confirm(a.confirm)) return;
  const res=await post(a.endpoint,{}); toast(res.ok?(res.message||'Done'):(res.message||res.error||'Error'), !res.ok);
  if(res&&res.ok&&res.url){ try{ window.open(res.url,'_blank'); }catch(e){} }
  refresh();
}
window.GAL={};
function formModal(a){
  const pf=a.prefill||{};
  window.GAL={};
  let fields=''; Object.entries(a.schema).forEach(([k,type])=>{
    const lbl=k.replace(/_/g,' '); const val=pf[k]!=null?String(pf[k]):'';
    if(type==='gallery'){
      window.GAL[k]=Array.isArray(pf[k])?pf[k].slice():[];
      fields+='<div class="fld"><label>'+lbl+' <span class="mut" style="font-weight:400">(first photo is the main one)</span></label><div class="gallery" id="gal_'+k+'"></div>'+
        '<input type="file" accept="image/*" id="galf_'+k+'" style="display:none" onchange="galUpload(\''+k+'\')"></div>'; }
    else if(type==='image'){
      fields+='<div class="fld"><label>'+lbl+'</label><div class="imgfld">'+
        '<div class="imgprev" id="prev_'+k+'"'+(val?' style="background-image:url(\''+esc(val)+'\')"':'')+'></div>'+
        '<div class="imgbtns"><input type="hidden" id="f_'+k+'" value="'+esc(val)+'">'+
        '<input type="file" accept="image/*" id="file_'+k+'" style="display:none" onchange="uploadPhoto(\''+k+'\')">'+
        '<button type="button" class="btn sm" onclick="document.getElementById(\'file_'+k+'\').click()">'+icon('doc','ic-sm')+'Choose photo</button>'+
        '<span class="mut" id="up_'+k+'" style="font-size:12px"></span></div></div></div>'; }
    else if(String(type).startsWith('enum:')){ const opts=String(type).slice(5).split(',');
      fields+='<div class="fld"><label>'+lbl+'</label><select id="f_'+k+'">'+opts.map(o=>'<option'+(o===val?' selected':'')+'>'+esc(o)+'</option>').join('')+'</select></div>'; }
    else { const t=(type==='money'||type==='number')?'type="number" step="'+(type==='money'?'0.01':'1')+'"':''; const ph=type==='money'?'placeholder="0.00"':'';
      fields+='<div class="fld"><label>'+lbl+'</label><input id="f_'+k+'" '+t+' '+ph+' value="'+esc(val)+'"></div>'; }
  });
  document.getElementById('modal').innerHTML='<div class="h">'+esc(a.label)+'<button class="x" onclick="closeM()"><svg class="ic" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div><div class="b">'+fields+'</div><div class="f"><button class="btn" onclick="closeM()">Cancel</button><button class="btn pri" id="mok">Save</button></div>';
  document.getElementById('ov').classList.add('on');
  Object.entries(a.schema).forEach(([k,type])=>{ if(type==='gallery') renderGal(k); });
  document.getElementById('mok').onclick=async()=>{ const body={};
    Object.entries(a.schema).forEach(([k,type])=>{ if(type==='gallery'){ body[k]=window.GAL[k]||[]; } else { const el=document.getElementById('f_'+k); if(el) body[k]=el.value; } });
    closeM(); const res=await post(a.endpoint,body); toast(res.ok?(res.message||'Saved'):(res.message||res.error||'Error'), !res.ok); refresh(); };
}
function renderGal(k){
  const el=document.getElementById('gal_'+k); if(!el) return; const urls=window.GAL[k]||[];
  let h=urls.map((u,i)=>'<div class="gthumb" style="background-image:url(\''+esc(u)+'\')">'+(i===0?'<span class="gmain">Main</span>':'')+'<button type="button" class="grm" onclick="galRemove(\''+k+'\','+i+')">×</button></div>').join('');
  if(urls.length<4) h+='<div class="gadd" onclick="document.getElementById(\'galf_'+k+'\').click()"><span>＋</span><span class="gaddt">Add photo</span></div>';
  el.innerHTML=h;
}
function galRemove(k,i){ (window.GAL[k]||[]).splice(i,1); renderGal(k); }
async function galUpload(k){
  const fi=document.getElementById('galf_'+k); const f=fi&&fi.files&&fi.files[0]; if(!f) return; fi.value='';
  if(f.size>6*1024*1024){ toast('Image too large (max 6MB)', true); return; }
  const el=document.getElementById('gal_'+k); if(el) el.insertAdjacentHTML('beforeend','<div class="gthumb gload" id="galload_'+k+'">…</div>');
  const reader=new FileReader();
  reader.onload=async()=>{ try{ const res=await post('/api/v1/workspace/products/_/upload_image',{data:reader.result,name:f.name});
    if(res.ok&&res.url){ (window.GAL[k]=window.GAL[k]||[]).push(res.url); } else { toast(res.message||'Upload failed', true); } }
    catch(e){ toast('Upload failed', true); } renderGal(k); };
  reader.readAsDataURL(f);
}
async function uploadPhoto(k){
  const fi=document.getElementById('file_'+k); const f=fi&&fi.files&&fi.files[0]; if(!f) return;
  const st=document.getElementById('up_'+k); if(st) st.textContent='Uploading…';
  if(f.size>6*1024*1024){ if(st) st.textContent='Image too large (max 6MB)'; return; }
  const reader=new FileReader();
  reader.onload=async()=>{
    try{
      const res=await post('/api/v1/workspace/products/_/upload_image',{data:reader.result,name:f.name});
      if(res.ok&&res.url){ document.getElementById('f_'+k).value=res.url; const pv=document.getElementById('prev_'+k); if(pv) pv.style.backgroundImage='url(\''+res.url+'\')'; if(st) st.textContent='✓ uploaded'; }
      else { if(st) st.textContent=res.message||res.error||'Upload failed'; }
    }catch(e){ if(st) st.textContent='Upload failed'; }
  };
  reader.readAsDataURL(f);
}
function closeM(){ document.getElementById('ov').classList.remove('on'); }
document.getElementById('ov').addEventListener('click',e=>{ if(e.target.id==='ov') closeM(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeM(); });
document.getElementById('search').addEventListener('keydown',e=>{ if(e.key==='Enter'){ const p=e.target.value.trim().split(/\s+/); if(p[0]&&p[1]) route(p[0].toLowerCase(),p[1]); } });
window.addEventListener('hashchange',()=>{ const h=parseHash(); if(h.key && (h.key!==CUR.key||h.id!==CUR.id)) route(h.key,h.id); });

/* ============ quotation builder (multi-line) ============ */
let QBT=null;
function quoteBuilder(a){
  const cur=(a.prefill&&a.prefill.currency)||'UGX';
  window.QB={lines:[], cur:cur, a:a, _res:[], _cres:[], customerId:(a.prefill&&a.prefill.id)||null, customerName:a.customer||'', pick:!!a.pickCustomer};
  const curOpts=(a.currencies||[cur]).map(c=>'<option'+(c===cur?' selected':'')+'>'+esc(c)+'</option>').join('');
  const m=document.getElementById('modal'); m.style.maxWidth='660px';
  const custBlock=a.pickCustomer?'<div class="qb-custwrap" id="qbcustwrap"></div>':'';
  m.innerHTML='<div class="h">New quotation'+(a.customer?' — '+esc(a.customer):'')+'<button class="x" onclick="closeQB()">'+icon('x')+'</button></div>'+
    '<div class="b">'+custBlock+
      '<div class="qb-pick"><input id="qbq" placeholder="Search products to add…" autocomplete="off" oninput="qbSearch()"><div class="qb-res" id="qbres"></div></div>'+
      '<div id="qblines"></div>'+
      '<div class="qb-bar"><label class="qb-cur">Currency <select id="qbcur" onchange="qbCur()">'+curOpts+'</select></label>'+
        '<div class="qb-tot">Total <b id="qbtot">'+esc(curFmt(0,cur))+'</b></div></div>'+
      '<label class="qb-wa"><input type="checkbox" id="qbwa"> Also send the PDF to the customer on WhatsApp</label>'+
    '</div>'+
    '<div class="f"><button class="btn" onclick="closeQB()">Cancel</button><button class="btn pri" id="qbok" onclick="qbSubmit()" disabled>Create quotation</button></div>';
  document.getElementById('ov').classList.add('on'); if(a.pickCustomer) qbRenderCust(); qbRender();
  setTimeout(()=>{ const f=document.getElementById(a.pickCustomer?'qbcq':'qbq'); if(f) f.focus(); },40);
}
function qbRenderCust(){
  const el=document.getElementById('qbcustwrap'); if(!el) return;
  if(window.QB.customerId){
    el.innerHTML='<div class="qb-chip">'+icon('user','ic-sm')+'<b>'+esc(window.QB.customerName)+'</b><button class="qb-chipx" onclick="qbCustClear()">Change</button></div>';
  } else {
    el.innerHTML='<div class="qb-pick"><input id="qbcq" placeholder="Search a customer…" autocomplete="off" oninput="qbCustSearch()"><div class="qb-res" id="qbcres"></div></div>';
  }
}
function qbCustClear(){ window.QB.customerId=null; window.QB.customerName=''; qbRenderCust(); qbTotal(); setTimeout(()=>{const f=document.getElementById('qbcq');if(f)f.focus();},30); }
function qbCustSearch(){
  const q=document.getElementById('qbcq').value.trim(); const box=document.getElementById('qbcres'); clearTimeout(QBT);
  if(q.length<2){ box.innerHTML=''; box.classList.remove('on'); return; }
  QBT=setTimeout(async()=>{
    const res=await post(window.QB.a.customerSearch,{q}); const items=(res&&res.items)||[]; window.QB._cres=items;
    box.innerHTML=items.length? items.map((it,i)=>'<div class="qb-opt" onclick="qbCustPick('+i+')"><div class="qb-on">'+esc(it.name)+'</div><div class="qb-op">'+esc(it.sub||'')+'</div></div>').join('')
      : '<div class="qb-empty">No customer matches “'+esc(q)+'”</div>';
    box.classList.add('on');
  },220);
}
function qbCustPick(i){ const it=(window.QB._cres||[])[i]; if(!it) return; window.QB.customerId=it.id; window.QB.customerName=it.name; qbRenderCust(); qbTotal(); }
function closeQB(){ const m=document.getElementById('modal'); if(m) m.style.maxWidth=''; closeM(); }
function qbSearch(){
  const q=document.getElementById('qbq').value.trim(); const box=document.getElementById('qbres'); clearTimeout(QBT);
  if(q.length<2){ box.innerHTML=''; box.classList.remove('on'); return; }
  QBT=setTimeout(async()=>{
    const res=await post(window.QB.a.productSearch,{q}); const items=(res&&res.items)||[];
    window.QB._res=items;
    box.innerHTML=items.length? items.map((it,i)=>'<div class="qb-opt" onclick="qbAdd('+i+')"><div class="qb-on">'+esc(it.name)+'</div><div class="qb-op">'+esc(curFmt(it.price,window.QB.cur))+(it.unit?' / '+esc(it.unit):'')+'</div></div>').join('')
      : '<div class="qb-empty">No products match “'+esc(q)+'”</div>';
    box.classList.add('on');
  },220);
}
function qbAdd(i){ const it=(window.QB._res||[])[i]; if(!it) return;
  window.QB.lines.push({name:it.name, unit:it.unit||'', qty:1, price:Number(it.price)||0});
  document.getElementById('qbq').value=''; const box=document.getElementById('qbres'); box.innerHTML=''; box.classList.remove('on'); qbRender();
}
function qbDel(i){ window.QB.lines.splice(i,1); qbRender(); }
function qbEdit(i,f,v){ const l=window.QB.lines[i]; if(!l) return; l[f]= f==='qty'?Math.max(1,parseInt(v||'1',10)||1):Math.max(0,parseFloat(v||'0')||0); qbTotal(); }
function qbCur(){ window.QB.cur=document.getElementById('qbcur').value; qbRender(); }
function qbRender(){
  const el=document.getElementById('qblines'); const L=window.QB.lines;
  if(!L.length){ el.innerHTML='<div class="qb-none">'+icon('cart','ic-lg')+'<div>No products yet — search above to add lines.</div></div>'; }
  else{ el.innerHTML='<div class="qb-head"><span>Product</span><span>Qty</span><span>Unit price</span><span>Amount</span><span></span></div>'+
    L.map((l,i)=>'<div class="qb-row"><div class="qb-nm">'+esc(l.name)+(l.unit?'<span class="qb-u">'+esc(l.unit)+'</span>':'')+'</div>'+
      '<input class="qb-qty" type="number" min="1" value="'+l.qty+'" oninput="qbEdit('+i+',&#39;qty&#39;,this.value)">'+
      '<input class="qb-price" type="number" min="0" step="0.01" value="'+l.price+'" oninput="qbEdit('+i+',&#39;price&#39;,this.value)">'+
      '<div class="qb-amt" id="qbamt'+i+'">'+esc(curFmt(l.qty*l.price,window.QB.cur))+'</div>'+
      '<button class="qb-x" onclick="qbDel('+i+')">'+icon('x','ic-sm')+'</button></div>').join(''); }
  qbTotal();
}
function qbTotal(){ let t=0; window.QB.lines.forEach((l,i)=>{ const a=l.qty*l.price; t+=a; const el=document.getElementById('qbamt'+i); if(el) el.textContent=curFmt(a,window.QB.cur); });
  const tot=document.getElementById('qbtot'); if(tot) tot.textContent=curFmt(t,window.QB.cur);
  const ok=document.getElementById('qbok'); if(ok) ok.disabled=window.QB.lines.length===0 || (window.QB.pick && !window.QB.customerId);
}
async function qbSubmit(){
  const a=window.QB.a; const ok=document.getElementById('qbok'); if(!window.QB.lines.length) return;
  if(window.QB.pick && !window.QB.customerId){ toast('Choose a customer first', true); return; }
  const body={lines:window.QB.lines.map(l=>({name:l.name,qty:l.qty,price:l.price})), currency:window.QB.cur, send_whatsapp:document.getElementById('qbwa').checked};
  if(window.QB.customerId) body.customer_id=window.QB.customerId;
  ok.disabled=true; ok.textContent='Creating…';
  const res=await post(a.endpoint,body); closeQB();
  if(res&&res.ok){ toast(res.message||('Quotation '+res.quote_no+' created')); if(res.url){ try{ window.open(res.url,'_blank'); }catch(e){} } refresh(); }
  else { toast((res&&(res.message||res.error))||'Could not create quotation', true); }
}

/* ================= LIST / DIRECTORY WORKSPACE ================= */
let TBL = { cols:[], rows:[], filters:[], fval:{}, sort:null, dir:1, q:'', dateKey:null, dateLabel:'Created', totals:[], from:'', to:'', page:1, pageSize:15, cur:'UGX' };
function cellVal(c){ return (c && typeof c==='object') ? (c.value!=null?c.value:'') : (c!=null?c:''); }
function cellSort(c){ if(c && typeof c==='object') return c.sort!=null?c.sort:(c.value!=null?c.value:''); return c!=null?c:''; }
function renderList(p){
  const o=p.object||{}; const cap=(p.meta&&p.meta.capability)||CUR.key; const tb=p.table||{};
  TBL = { cols:tb.columns||[], rows:tb.rows||[], filters:tb.filters||[], fval:{}, sort:null, dir:1, q:'',
    dateKey:tb.dateKey||null, dateLabel:tb.dateLabel||'Created', totals:tb.totals||[], from:'', to:'', page:1,
    pageSize:tb.pageSize||15, cur:tb.currency||'UGX' };
  let h='<div class="crumb">'+(LABELS[cap]||cap)+'</div>';
  h+='<div class="lhead"><div><h1 class="ltitle">'+esc(o.title||'')+'</h1>'+(o.subtitle?'<div class="lsub">'+esc(o.subtitle)+'</div>':'')+'</div>'+
     '<div class="acts inl">'+(p.actions||[]).map(actBtn).join('')+
     ((p.actions||[]).some(a=>a.tone==='primary')?'':'<button class="btn pri" onclick="toast(\'New — coming soon\')">'+icon('bolt','ic-sm')+'New</button>')+'</div></div>';
  if(p.financials&&p.financials.length){
    h+='<div class="kpis">'+p.financials.map(f=>{ const tc=toneCls(f.tone);
      return '<div class="kpi"><div class="top"><span class="ib '+tc+'">'+icon(cap,'ic-sm')+'</span>'+esc(f.label)+'</div><div class="val '+tc+'">'+esc(fval(f))+'</div>'+(f.foot?'<div class="foot">'+esc(f.foot)+'</div>':'')+'</div>';
    }).join('')+'</div>';
  }
  h+='<div class="card"><div class="toolbar" id="tbar"></div><div class="tbl-wrap" id="tbl"></div><div class="pager" id="pager"></div></div><div id="gtot"></div>';
  document.getElementById('view').innerHTML=h;
  renderToolbar(); renderTable();
}
function setFilter(k,v){ TBL.fval[k]=v; renderTable(); }
function renderToolbar(){
  // Declared filters (payload), else auto-detect a Status column.
  let filts = TBL.filters && TBL.filters.length ? TBL.filters.slice() : (TBL.cols.some(c=>c.key==='status') ? [{key:'status',label:'Status'}] : []);
  let h='<div class="tsearch"><svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'+
    '<input id="tq" placeholder="Search…" oninput="TBL.q=this.value;renderTable()"></div>';
  filts.forEach(f=>{
    const vals=['All',...Array.from(new Set(TBL.rows.map(r=>cellVal(r[f.key])).filter(Boolean)))];
    if(vals.length<=1) return;
    const cur=TBL.fval[f.key]||'All';
    h+='<label class="chip">'+esc(f.label)+' <select onchange="setFilter(\''+f.key+'\',this.value)">'+vals.map(v=>'<option'+(v===cur?' selected':'')+'>'+esc(v)+'</option>').join('')+'</select></label>';
  });
  if(TBL.dateKey){
    h+='<label class="chip">From <input type="date" value="'+esc(TBL.from)+'" onchange="TBL.from=this.value;TBL.page=1;renderTable()"></label>'+
       '<label class="chip">To <input type="date" value="'+esc(TBL.to)+'" onchange="TBL.to=this.value;TBL.page=1;renderTable()"></label>'+
       ((TBL.from||TBL.to)?'<button class="btn sm" onclick="TBL.from=\'\';TBL.to=\'\';TBL.page=1;renderToolbar();renderTable()">Clear</button>':'');
  }
  h+='<div style="flex:1"></div><span class="tinfo" id="tinfo"></span>';
  document.getElementById('tbar').innerHTML=h;
}
function sortBy(k){ if(TBL.sort===k){ TBL.dir=-TBL.dir; } else { TBL.sort=k; TBL.dir=1; } renderTable(); }
function renderTable(){
  let rows=TBL.rows.slice();
  const q=(TBL.q||'').trim().toLowerCase();
  if(q) rows=rows.filter(r=>TBL.cols.some(c=>{const v=r[c.key]; return String(cellVal(v)).toLowerCase().includes(q) || (v&&v.sub&&String(v.sub).toLowerCase().includes(q));}));
  Object.keys(TBL.fval||{}).forEach(k=>{ const v=TBL.fval[k]; if(v && v!=='All') rows=rows.filter(r=>cellVal(r[k])===v); });
  if(TBL.dateKey){
    const f=TBL.from?Math.floor(Date.parse(TBL.from)/1000):null;
    const t=TBL.to?Math.floor(Date.parse(TBL.to)/1000)+86399:null;
    if(f!=null||t!=null) rows=rows.filter(r=>{ const s=Number(cellSort(r[TBL.dateKey]))||0; if(f!=null&&s<f) return false; if(t!=null&&s>t) return false; return true; });
  }
  if(TBL.sort){ rows.sort((a,b)=>{ let x=cellSort(a[TBL.sort]), y=cellSort(b[TBL.sort]);
    if(typeof x==='string'||typeof y==='string'){ x=String(x).toLowerCase(); y=String(y).toLowerCase(); }
    return (x>y?1:x<y?-1:0)*TBL.dir; }); }
  const filtered=rows.length;
  // Grid totals over the full filtered set (not just the visible page).
  if(TBL.totals&&TBL.totals.length){
    const parts=['<div class="gt-row"><span>Count</span><b>'+fmtCount(filtered)+'</b></div>'];
    TBL.totals.forEach(t=>{ const sum=rows.reduce((a,r)=>a+(Number(cellSort(r[t.key]))||0),0);
      parts.push('<div class="gt-row"><span>'+esc(t.label)+'</span><b>'+esc(t.money?curFmt(sum,TBL.cur):fmtCount(sum))+'</b></div>'); });
    document.getElementById('gtot').innerHTML='<div class="gridtotals"><div class="gt-h">Grid totals</div>'+parts.join('')+'</div>';
  } else { const g=document.getElementById('gtot'); if(g) g.innerHTML=''; }
  // Pagination.
  const ps=TBL.pageSize||15, pages=Math.max(1,Math.ceil(filtered/ps));
  if(TBL.page>pages) TBL.page=pages; if(TBL.page<1) TBL.page=1;
  const start=(TBL.page-1)*ps, pageRows=rows.slice(start,start+ps);
  const arrow=k=> TBL.sort===k ? '<span class="sar">'+(TBL.dir>0?'↑':'↓')+'</span>' : '';
  const th=TBL.cols.map(c=>'<th class="'+(c.align==='right'?'num':'')+(c.sortable!==false?' srt':'')+(TBL.sort===c.key?' sorted':'')+'"'+(c.sortable!==false?' onclick="sortBy(\''+c.key+'\')"':'')+'><span class="thc">'+esc(c.label)+arrow(c.key)+'</span></th>').join('');
  const body = pageRows.length ? pageRows.map(r=>'<tr'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'>'+TBL.cols.map(c=>cellHtml(r[c.key],c)).join('')+'</tr>').join('')
    : '<tr><td colspan="'+TBL.cols.length+'"><div class="empty" style="padding:40px">'+icon('worklist','ic-lg')+'<div style="margin-top:8px">No matching records</div></div></td></tr>';
  document.getElementById('tbl').innerHTML='<table class="dt"><thead><tr>'+th+'</tr></thead><tbody>'+body+'</tbody></table>';
  const ti=document.getElementById('tinfo'); if(ti) ti.textContent=fmtCount(filtered)+(filtered!==TBL.rows.length?' of '+fmtCount(TBL.rows.length):'');
  const pg=document.getElementById('pager');
  if(pg){ if(filtered>ps){ const a=start+1, b=Math.min(start+ps,filtered);
      pg.innerHTML='<span>'+a+'–'+b+' of '+fmtCount(filtered)+'</span>'+
        '<button onclick="TBL.page--;renderTable()"'+(TBL.page<=1?' disabled':'')+'>Prev</button>'+
        '<span>Page '+TBL.page+' / '+pages+'</span>'+
        '<button onclick="TBL.page++;renderTable()"'+(TBL.page>=pages?' disabled':'')+'>Next</button>';
    } else { pg.innerHTML=''; } }
}
function cellHtml(c,col){
  const align=col.align==='right'?' num':'';
  if(col.type==='avatar'){ const nm=cellVal(c), sub=(c&&c.sub)||'', img=(c&&c.image)||'';
    const av = img ? '<div class="av-s" style="background:#eef0f3;background-image:url(\''+esc(img)+'\');background-size:cover;background-position:center"></div>' : '<div class="av-s" style="background:'+avColor(nm)+'">'+initials(nm)+'</div>';
    return '<td><div class="cellflex">'+av+'<div><div class="prim">'+esc(nm)+'</div>'+(sub?'<div class="mut">'+esc(sub)+'</div>':'')+'</div></div></td>'; }
  if(col.type==='strong'){ return '<td class="prim'+align+'">'+esc(cellVal(c))+'</td>'; }
  if(col.type==='badge'){ const v=cellVal(c), tone=(c&&c.badge)||'default';
    return '<td>'+(v?'<span class="badge '+toneBadge(tone)+'"><span class="d"></span>'+esc(v)+'</span>':'—')+'</td>'; }
  const tone=(c&&c.tone)?(' '+toneCls(c.tone)):'';
  return '<td class="'+align+tone+'">'+esc(cellVal(c))+'</td>';
}

boot();
</script>
</body>
</html>
