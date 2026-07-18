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
.curpick{display:flex;align-items:center;gap:6px;height:34px;padding:0 4px 0 10px;border:1px solid var(--line);border-radius:8px;background:var(--surface-2);color:var(--ink-2);font-size:12.5px}
.curpick .cl{display:flex;align-items:center;gap:5px;font-weight:600;color:var(--ink)}
.curpick .cl svg{color:var(--brand)}
.curpick select{border:none;background:transparent;font:inherit;font-weight:600;color:var(--ink);cursor:pointer;padding:0 2px;height:32px}
.curpick select:focus{outline:none}
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
.rowacts{display:flex;gap:6px;align-items:center}
.rowbtn{width:30px;height:30px;border-radius:7px;display:grid;place-items:center;border:1px solid var(--line);background:var(--surface);color:var(--ink-2);transition:.12s;cursor:pointer}
.rowbtn:hover{background:var(--line-2);color:var(--ink);border-color:#d8dce3}
.rowbtn.dgr{color:var(--danger)}.rowbtn.dgr:hover{background:var(--danger-wash);border-color:#f3c9c9}
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
.qb-adj{display:flex;gap:16px;margin-top:12px;flex-wrap:wrap}
.qb-adj label{font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:8px}
.qb-adj input{width:120px;height:34px;border:1px solid var(--line);border-radius:7px;padding:0 10px;background:#fff;text-align:right;font-variant-numeric:tabular-nums}
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
.cttool{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--line-2)}
.cttool .tsearch{position:relative;min-width:200px}
.cttool .tsearch .ic{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--faint)}
.cttool .tsearch input{height:34px;border:1px solid var(--line);border-radius:8px;padding:0 10px 0 32px;background:var(--surface-2);width:100%}
.cttool .tsearch input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-wash);background:#fff}

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
.chgrid{display:flex;flex-direction:column;gap:9px}
.chtile{display:flex;align-items:center;gap:13px;width:100%;text-align:left;padding:13px 14px;border:1px solid var(--line);border-radius:11px;background:var(--surface);cursor:pointer;transition:.13s}
.chtile:hover{border-color:var(--brand);background:var(--brand-wash);transform:translateY(-1px)}
.chic{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;flex:none;background:var(--line-2);color:var(--ink-2)}
.chic .ic{width:20px;height:20px}
.chic.chin{background:#dcfce7;color:#16a34a}.chic.chout{background:#fee2e2;color:#dc2626}.chic.chmove{background:#e0e7ff;color:#4f46e5}
.chtx{display:flex;flex-direction:column;min-width:0;flex:1}
.chtx .cht{font-weight:650;font-size:14.5px;color:var(--ink)}
.chtx .chs{font-size:12.5px;color:var(--muted);margin-top:1px}
.chtile .chev{width:18px;height:18px;color:var(--muted);flex:none}
.fpreview{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:4px;padding:12px 14px;border-radius:10px;background:var(--brand-wash);border:1px solid var(--brand-line,#bbf7d0)}
.fpreview span{font-size:13px;color:var(--ink-2);font-weight:600}
.fpreview strong{font-size:17px;color:var(--brand-ink,#15803d);font-weight:750}
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

/* account menu (top-right) + mobile nav toggle */
.hamb{display:none;background:none;border:none;cursor:pointer;color:var(--muted);padding:6px;border-radius:8px;flex:none}
.hamb:hover{background:var(--surface-2)}
.acct{position:relative;flex:none}
.acct-btn{display:flex;align-items:center;gap:8px;background:none;border:1px solid var(--line);border-radius:9px;padding:5px 9px 5px 6px;cursor:pointer;color:var(--ink,#1b211d)}
.acct-btn:hover{background:var(--surface-2)}
.acct-btn .av{width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#16a34a,#0d9488);display:grid;place-items:center;color:#fff;font-weight:700;font-size:12px}
.acct-btn .nm{font-size:13px;font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.acct-menu{position:absolute;top:calc(100% + 6px);right:0;background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:0 10px 30px #0000001f;min-width:210px;padding:6px;z-index:60;display:none}
.acct-menu.on{display:block}
.acct-menu .who{padding:9px 10px 8px;border-bottom:1px solid var(--line);margin-bottom:4px}
.acct-menu .who .n{font-weight:700;font-size:13.5px}
.acct-menu .who .s{font-size:11.5px;color:var(--muted);text-transform:capitalize;margin-top:1px}
.acct-menu button{display:flex;align-items:center;gap:9px;width:100%;background:none;border:none;text-align:left;padding:9px 10px;border-radius:8px;cursor:pointer;font-size:13.5px;color:var(--ink,#1b211d)}
.acct-menu button:hover{background:var(--surface-2)}
.acct-menu button.dgr{color:#b91c1c}
.scrim{display:none;position:fixed;inset:0;background:#0006;z-index:40}
.scrim.on{display:block}
@media(max-width:880px){
  .app{grid-template-columns:1fr}
  .side{display:none;position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);z-index:50}
  .app.navopen .side{display:flex}
  .rec{grid-template-columns:1fr}
  .hamb{display:grid;place-items:center}
  .top{gap:8px;padding:0 12px}
  .sb{max-width:none;min-width:0}
  .curpick{display:none}          /* reporting-currency toggle is desktop-only; base currency still applies */
  .acct-btn .nm{display:none}
  .acct-btn{padding:5px 6px}
}
/* ===== Storefront merchandising editors ===== */
.sfrow{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.sffield{display:flex;flex-direction:column;gap:4px}
.sffield label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.sfin,.sfsel,.sfta{height:36px;border:1px solid var(--line);border-radius:8px;padding:0 11px;font-size:13.5px;color:var(--ink);background:var(--surface);outline:none}
.sfin:focus,.sfsel:focus,.sfta:focus{border-color:var(--brand)}
.sfta{height:auto;padding:8px 11px;resize:vertical}
.sfmini{border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:8px;background:var(--surface-2)}
.sfchk{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:var(--ink-2)}
.sfphoto{width:74px;height:54px;border-radius:8px;object-fit:cover;background:#eef2f5;display:grid;place-items:center;color:var(--faint);font-size:10px}
.sftabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.sftab{height:34px;padding:0 15px;border-radius:9px;font-weight:700;font-size:13px;border:1px solid var(--line);background:var(--surface);color:var(--muted)}
.sftab.on{background:var(--brand);border-color:var(--brand);color:#fff}
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
      <button class="hamb" onclick="toggleNav()" title="Menu" aria-label="Menu"><svg class="ic" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
      <div class="sb">
        <svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="search" placeholder="Open a record…  e.g. customer 5">
      </div>
      <div class="sp"></div>
      <div class="curpick" id="curpick" title="How money is shown across reports"></div>
      <button class="tbtn" onclick="refresh()" title="Refresh"><svg class="ic" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg></button>
      <div class="acct">
        <button class="acct-btn" onclick="toggleAcct(event)" title="Account">
          <span class="av" id="acctav">U</span><span class="nm" id="acctnm">User</span>
          <svg class="ic ic-sm" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="acct-menu" id="acctMenu">
          <div class="who"><div class="n" id="acctwho">User</div><div class="s" id="acctrole"></div></div>
          <button onclick="location.href='/panel?classic=1'"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M4 4h16v12H4zM2 20h20"/></svg>Open classic panel</button>
          <button class="dgr" onclick="signOut()"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>Sign out</button>
        </div>
      </div>
    </header>
    <main class="view"><div class="wrap" id="view"></div></main>
  </div>
</div>
<div class="ov" id="ov"><div class="modal" id="modal"></div></div>
<div class="scrim" id="scrim" onclick="closeNav()"></div>
<div id="toasts"></div>

<script>
const CFG = { base:'/api/v1/workspace', csrf: document.querySelector('meta[name=csrf-token]').content, user: @json(['name'=>'']) };
const LABELS = { setup:'Get started', chats:'Chats', chat:'Chat', communication:'Email Center', guide:'Help & Training', consignments:'Consignments', consignment:'Consignment', scheduled:'Scheduled', briefing:'Morning Briefing', worklist:'My Work', customers:'Customers', leads:'Leads', collections:'Collections',
  salesOrders:'Sales Orders', quotations:'Quotations', invoices:'Invoices', payments:'Payments', finance:'Finance', pnl:'Profit & Loss',
  products:'Products', categories:'Categories', 'purchase.process':'Purchasing', 'procurement.hub':'Procurement Hub', 'demand.plans':'Demand Plans', 'juba.stock':'Juba Stock', suppliers:'Suppliers', warehouse:'Warehouse', dispatch:'Dispatch', transport:'Transport Register', cashbook:'Cashbook',
  customer:'Customer', invoice:'Invoice', payment:'Payment', product:'Product', supplier:'Supplier', quotation:'Quotation',
  salesOrder:'Sales Order', purchaseOrder:'Purchase Order', 'sales.process':'Sales Pipeline', statement:'Statement', settings:'Settings', ads:'Facebook Ads', campaigns:'Campaigns', 'storefront.insights':'Storefront Insights',
  'storefront.collections':'Shop for Every Need', 'storefront.kits':'Ready-made Kits', 'storefront.delivery':'Delivery & Free Shipping' };
const GROUPS = [ ['Overview',['setup','briefing','worklist','guide']],
  ['Sales',['customers','leads','salesOrders','scheduled','quotations','invoices','payments','collections']],
  ['Messaging',['chats','communication']],
  ['Inventory',['products','categories','purchase.process','suppliers','consignments']],
  ['Procurement',['procurement.hub','demand.plans','juba.stock']],
  ['Operations',['warehouse','dispatch','transport']],
  ['Finance',['finance','pnl','cashbook']],
  ['Marketing',['ads','campaigns','storefront.insights','storefront.collections','storefront.kits','storefront.delivery']],
  ['Settings',['settings']] ];
// Which nav key reads which count from the catalog's counts map.
const NAVCOUNT = { chats:'chats', communication:'communication', consignments:'consignments', scheduled:'scheduled', customers:'customers', salesOrders:'salesOrder', quotations:'quotations', invoices:'invoice', payments:'payment', products:'product', categories:'categories', suppliers:'supplier', 'purchase.process':'purchase.process' };
// Full workspaces that resolve to a real screen (all menu items are now built).
const READY = new Set(['setup','briefing','worklist','chats','communication','guide','consignments','scheduled','customers','leads','salesOrders','quotations','invoices','payments','products','categories','suppliers','purchase.process','demand.plans','procurement.hub','juba.stock','collections','finance','pnl','warehouse','dispatch','settings','cashbook','ads','campaigns','storefront.insights','storefront.collections','storefront.kits','storefront.delivery','transport']);
// Storefront config pages are client-rendered (not server workspaces) and always available to the owner.
const SF_KEYS = new Set(['storefront.collections','storefront.kits','storefront.delivery','transport']);
// Dedicated list views still pending — none; kept for future additions.
const SOON = {};
function fmtCount(n){ n=Number(n); return n>=1000 ? (n/1000).toFixed(n>=10000?0:1)+'k' : String(n); }

/* ---- inline icon set ---- */
const P = {
  scheduled:'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
  consignments:'M1 3h15v13H1zM16 8h4l3 3v5h-7zM5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  consignment:'M1 3h15v13H1zM16 8h4l3 3v5h-7z',
  guide:'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z',
  communication:'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
  chats:'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  chat:'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  setup:'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  briefing:'M12 3v18M3 12h18', worklist:'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
  settings:'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
  collections:'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
  finance:'M3 3v18h18M7 14l4-4 3 3 5-6', 'sales.process':'M3 3v18h18M18 9l-6 6-4-4-3 3',
  cashbook:'M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM2 10h20M16 15h2',
  'purchase.process':'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  'demand.plans':'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 14l2 2 4-4',
  'procurement.hub':'M3 3v18h18M8 17V9M13 17V5M18 17v-6',
  'juba.stock':'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.27 6.96 12 12.01l8.73-5.05M12 22.08V12',
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
  ads:'M11 5 6 9H2v6h4l5 4V5zM19 12a7 7 0 0 0-2-4.9M15.5 8.5a3 3 0 0 1 0 5',
  leads:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0 8 4 4 0 0 0 0-8M19 8v6M22 11h-6',
  campaigns:'M22 2 11 13M22 2l-7 20-4-9-9-4z',
  'storefront.insights':'M3 3v18h18M7 15l3-4 3 3 4-6',
  'storefront.collections':'M3 9l1-5h16l1 5M3 9h18v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM9 9a3 3 0 0 0 6 0',
  'storefront.kits':'M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z',
  'storefront.delivery':'M1 3h15v13H1zM16 8h4l3 3v5h-7M5.5 18.5a2 2 0 1 0 0 .01M17.5 18.5a2 2 0 1 0 0 .01',
  'transport':'M1 3h15v13H1zM16 8h4l3 3v5h-7M5.5 18.5a2 2 0 1 0 0 .01M17.5 18.5a2 2 0 1 0 0 .01',
  salesOrder:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  salesOrders:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  purchaseOrder:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  statement:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  doc:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  clock:'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2', bolt:'M13 2 3 14h9l-1 8 10-12h-9z',
  check:'M20 6 9 17l-5-5', chev:'m9 18 6-6-6-6',
  trash:'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6',
  ban:'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM5 5l14 14',
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

// Append the active report-currency override (?cur=) so every workspace + command call is consistent.
function withCur(u){ const c=CFG.cur; if(!c) return u; return u+(u.indexOf('?')<0?'?':'&')+'cur='+encodeURIComponent(c); }
async function api(path){
  const r = await fetch(withCur(CFG.base+path), { credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
  if(!r.ok && r.status!==200){ return { _error:r.status }; }
  return r.json();
}
async function post(endpoint, body){
  const r = await fetch(withCur(endpoint), { method:'POST', credentials:'include',
    headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':CFG.csrf,'Content-Type':'application/json' },
    body: JSON.stringify(body||{}) });
  return r.json();
}
// GET-with-query write, for reusing legacy panel endpoints that read $request->query() (e.g. /papi/lead-save).
// No body/JSON — every field goes on the query string, exactly like the panel does.
async function getQuery(endpoint, body){
  const parts=[]; Object.entries(body||{}).forEach(([k,v])=>{ if(v!=null && v!=='') parts.push(encodeURIComponent(k)+'='+encodeURIComponent(v)); });
  const url = endpoint + (parts.length ? ((endpoint.indexOf('?')<0?'?':'&')+parts.join('&')) : '');
  const r = await fetch(withCur(url), { credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
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
// Minor-unit precision, mirroring \App\Services\Pricing::decimalsForCurrency on the server:
// zero-decimal currencies (UGX/SSP + the East-African/ISO set) stay whole; USD/EUR keep 2 so
// real cent prices ($1.02, $0.50) survive display instead of rounding to "$1"/"$0".
function curDec(cur){ const zero=['UGX','KES','TZS','RWF','BIF','SSP','JPY','KRW','VND','XAF','XOF','GNF','MGA','CLP','PYG','ISK','KMF','XPF'];
  return zero.includes(String(cur||'UGX').toUpperCase())?0:2; }
function fval(f){ let v=f.value; if(f.format==='pct') return v+'%';
  if(f.currency && typeof v==='number'){ const sym=f.currency==='USD'?'$':''; const suf=f.currency==='USD'?'':(' '+f.currency);
    const d=curDec(f.currency);
    return (sym?sym:'')+Number(v).toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:d})+(sym?'':suf).replace(/^ /,' '); }
  return v; }
function curFmt(v,cur){ cur=cur||'UGX'; const d=curDec(cur);
  const n=Number(v).toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:d});
  return cur==='USD' ? '$'+n : n+' '+cur; }

async function boot(){
  document.getElementById('view').innerHTML = skeleton();
  const cat = await api('/');
  CFG.user = cat.user || CFG.user;
  CFG.counts = cat.counts || {};
  CFG.currency = cat.currency || { base:'UGX', mode:'base', options:['base','original','USD','UGX','SSP'] };
  initCur();
  const nm = CFG.user.name||'User';
  document.getElementById('unm').textContent = nm;
  document.getElementById('url').textContent = (CFG.user.role||'') + (CFG.user.org ? ' · ' + CFG.user.org : '');
  document.getElementById('uav').textContent = initials(nm);
  document.getElementById('uav').style.background = 'linear-gradient(135deg,#16a34a,#0d9488)';
  // top-bar account menu (always visible, incl. mobile)
  document.getElementById('acctav').textContent = initials(nm);
  document.getElementById('acctnm').textContent = nm;
  document.getElementById('acctwho').textContent = nm;
  document.getElementById('acctrole').textContent = (CFG.user.role||'') + (CFG.user.org ? ' · ' + CFG.user.org : '');
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
  const has = k => k==='pnl' || k==='settings' || SF_KEYS.has(k) || keys.has(k) || (SOON[k] && CFG.counts[NAVCOUNT[k]||k] != null);
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

/* ---- global report-currency selector ---- */
const CUR_LABELS = { base:'Base currency', original:'Original currency', USD:'USD', UGX:'UGX', SSP:'SSP' };
function initCur(){
  // Session-persistent override; falls back to the tenant's configured mode.
  let saved=null; try{ saved=localStorage.getItem('v3cur'); }catch(e){}
  const c=CFG.currency||{};
  CFG.cur = saved || (c.override || (c.mode==='original'?'original':'base'));
  renderCurpick();
}
function renderCurpick(){
  const el=document.getElementById('curpick'); if(!el) return;
  const c=CFG.currency||{}; const opts=c.options||['base','original','USD','UGX','SSP'];
  const cur=CFG.cur||'base';
  const label=(k)=> k==='base' ? ('Base ('+(c.base||'UGX')+')') : (CUR_LABELS[k]||k);
  el.innerHTML='<span class="cl">'+icon('dollar','ic-sm')+'Reports</span>'+
    '<select id="curSel" onchange="setCur(this.value)">'+
    opts.map(o=>'<option value="'+o+'"'+(o===cur?' selected':'')+'>'+esc(label(o))+'</option>').join('')+
    '</select>';
}
function setCur(v){
  CFG.cur=v; try{ localStorage.setItem('v3cur', v); }catch(e){}
  renderCurpick();   // keep the top-bar selector reflecting the active choice across navigation
  // Sidebar catalog counts are currency-independent; just reload the current view.
  if(CUR && CUR.key){ route(CUR.key, CUR.id); }
}

async function route(key, id){
  // Normalise id to null (not undefined) so the hashchange guard (which compares against parseHash's
  // null id) doesn't fire a spurious second route that re-renders and wipes a just-applied filter.
  id = id != null ? id : null;
  closeNav();
  CUR={key,id}; REC_TAB='overview'; location.hash='#/'+key+(id?'/'+id:''); setActive(key);
  if(key==='transport'){ location.href='/transport'; return; }
  if(key==='payments'){ document.getElementById('view').innerHTML='<div class="crumb">Payments</div><iframe title="Payments" src="/panel/payments-manager" style="width:100%;height:calc(100vh - 150px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='pnl'){ document.getElementById('view').innerHTML='<div class="crumb">Profit & Loss</div><iframe title="Profit and Loss" src="/panel/pnl-report" style="width:100%;height:calc(100vh - 150px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='wa-connect'){ document.getElementById('view').innerHTML='<div class="crumb">Connect WhatsApp</div><iframe title="Connect WhatsApp" src="/panel/setup" style="width:100%;height:calc(100vh - 150px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='settings'){ document.getElementById('view').innerHTML='<iframe title="Settings" src="/panel/settings-manager" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='storefront.collections'){ sfCollectionsPage(); return; }
  if(key==='storefront.kits'){ sfKitsPage(); return; }
  if(key==='storefront.delivery'){ sfDeliveryPage(); return; }
  if(SOON[key] && !id){ renderSoon(key); return; }
  document.getElementById('view').innerHTML=skeleton();
  let path='/'+key+(id?'/'+id:'');
  // A pending server scope (e.g. category → products) is appended to this one fetch, then cleared.
  if(PENDING_SCOPE && PENDING_SCOPE.key===key){ path += (path.indexOf('?')<0?'?':'&')+PENDING_SCOPE.query; PENDING_SCOPE=null; }
  const p = await api(path);
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
/* Mobile nav drawer + top-bar account menu (Sign out / classic panel). */
function toggleNav(){ const a=document.querySelector('.app'); const open=a.classList.toggle('navopen'); document.getElementById('scrim').classList.toggle('on',open); }
function closeNav(){ document.querySelector('.app').classList.remove('navopen'); document.getElementById('scrim').classList.remove('on'); }
function toggleAcct(e){ if(e) e.stopPropagation(); document.getElementById('acctMenu').classList.toggle('on'); }
function signOut(){ if(window.confirm('Sign out of CloudBSS?')) location.href='/panel/logout'; }
document.addEventListener('click',e=>{ if(!e.target.closest('.acct')) document.getElementById('acctMenu').classList.remove('on'); });

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
// A row can navigate to a LIST pre-filtered two ways:
//   client:  "filter:<listKey>:<colKey>:<urlEncodedValue>"  → pre-selects a client-side dropdown.
//   server:  "scope:<listKey>:<param>:<urlEncodedValue>"     → the list is re-fetched with ?param=value
//            so the BACKEND filters (exact, case-insensitive) — used by category → products, like the panel.
let PENDING_FILTER=null, PENDING_SCOPE=null;
function openLink(apiPath){
  if(apiPath && apiPath.indexOf('scope:')===0){
    const p=apiPath.split(':'); PENDING_SCOPE={ key:p[1], query:p[2]+'='+(p[3]||'') }; route(p[1]); return;
  }
  if(apiPath && apiPath.indexOf('filter:')===0){
    const p=apiPath.split(':'); PENDING_FILTER={ key:p[2], val:decodeURIComponent(p[3]||'') }; route(p[1]); return;
  }
  const h=apiToHash(apiPath); if(h) route(h.key, h.id);
}

/* ============ record shell (tabbed 360: answers · health · KPIs · NBA · tabs) ============ */
const ACTICON={record_payment:'card',log_contact:'phone',log_whatsapp:'mail',edit_customer:'edit',update_customer:'edit',send_reminder:'bell',set_followup:'bell',assign_rep:'user',new_quotation:'quotations',open_invoice:'invoice',open_customer:'user',open_order:'cart',receipt_pdf:'download',quotation_pdf:'download',invoice_pdf:'download',send_receipt:'send',mark_delivered:'truck',create_invoice:'invoice'};
function actIcon(a){ return (a&&(a.icon||ACTICON[a.key]))||'chev'; }
function actJson(a){ return JSON.stringify(a).replace(/'/g,'&#39;'); }

function renderRecord(p){ LAST_REC=p; document.getElementById('view').innerHTML=recordHtml(p); hydrateCardTables(); }
function setRecTab(k){ REC_TAB=k; if(LAST_REC){ document.getElementById('view').innerHTML=recordHtml(LAST_REC); hydrateCardTables(); } }

function recordHtml(p){
  window._CT=[];
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
    // Register the rows; the toolbar (search / date range) + pagination render after the card is in the DOM.
    const idx=(window._CT=window._CT||[]).length;
    window._CT.push({cols:c.columns||[], rows:c.rows||[], empty:c.empty||'Nothing here', page:1, ps:c.pageSize||10,
      searchable:c.searchable!==false, dateFilter:!!c.dateFilter, q:'', from:'', to:''});
    return '<div class="card">'+head+'<div id="ct_'+idx+'"></div></div>';
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
// Shared renderer for per-row action buttons (href opens; post fires a mutation then refreshes).
function rowActBtnsHtml(buttons){ return (buttons||[]).map(bn=>{ const ic=icon(bn.icon||'dots','ic-sm'); const tn=bn.tone==='danger'?' dgr':''; const ttl=esc(bn.title||'');
  if(bn.href) return '<button class="rowbtn'+tn+'" title="'+ttl+'" onclick="event.stopPropagation();try{window.open(\''+esc(bn.href)+'\',\'_blank\')}catch(e){location.href=\''+esc(bn.href)+'\'}">'+ic+'</button>';
  if(bn.action) return '<button class="rowbtn'+tn+'" title="'+ttl+'" onclick=\'event.stopPropagation();doAction('+JSON.stringify(bn.action).replace(/'/g,"&#39;")+')\'>'+ic+'</button>';
  return '<button class="rowbtn'+tn+'" title="'+ttl+'" onclick=\'event.stopPropagation();rowPost('+JSON.stringify({endpoint:bn.post,body:bn.body||{},confirm:bn.confirm||""}).replace(/'/g,"&#39;")+')\'>'+ic+'</button>'; }).join(''); }
function cellBadge(cell){ if(cell&&typeof cell==='object'){ if(cell.buttons&&cell.buttons.length) return '<div class="rowacts">'+rowActBtnsHtml(cell.buttons)+'</div>'; if(cell.badge) return '<span class="badge '+toneBadge(cell.badge)+'"><span class="d"></span>'+esc(cell.value)+'</span>'; if(cell.tone){ const col=cell.tone==='danger'?'var(--danger)':(cell.tone==='ok'?'var(--brand-ink)':(cell.tone==='warn'?'var(--warn-ink,#b45309)':'inherit')); return '<span style="color:'+col+';font-weight:600">'+esc(cell.value)+'</span>'; } return esc(cell.value); } return esc(cell); }
function hydrateCardTables(){ (window._CT||[]).forEach((t,i)=>cardTableRender(i)); }
function ctText(cell){ return (cell&&typeof cell==='object')?(cell.value!=null?cell.value:''):(cell!=null?cell:''); }
// Full render: draws the toolbar ONCE (so its inputs keep focus) + a body container the filters re-render into.
function cardTableRender(i){
  const t=(window._CT||[])[i]; const el=document.getElementById('ct_'+i); if(!t||!el) return;
  const showSearch = t.searchable && t.rows.length>8;
  let tb='';
  if(showSearch || t.dateFilter){
    tb='<div class="cttool">';
    if(showSearch) tb+='<div class="tsearch"><svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><input placeholder="Search…" value="'+esc(t.q||'')+'" oninput="cardTableSet('+i+',\'q\',this.value)"></div>';
    if(t.dateFilter) tb+='<label class="chip">From <input type="date" value="'+esc(t.from||'')+'" onchange="cardTableSet('+i+',\'from\',this.value)"></label>'+
      '<label class="chip">To <input type="date" value="'+esc(t.to||'')+'" onchange="cardTableSet('+i+',\'to\',this.value)"></label>'+
      ((t.from||t.to)?'<button class="btn sm" onclick="cardTableClear('+i+')">Clear</button>':'');
    tb+='</div>';
  }
  el.innerHTML=tb+'<div id="ctb_'+i+'"></div>';
  cardTableBody(i);
}
// Light render: filter + paginate into the body container only (keeps toolbar focus).
function cardTableBody(i){
  const t=(window._CT||[])[i]; const el=document.getElementById('ctb_'+i); if(!t||!el) return;
  let rows=t.rows.slice();
  const q=(t.q||'').trim().toLowerCase();
  if(q) rows=rows.filter(r=>(r.cells||[]).some(c=>String(ctText(c)).toLowerCase().includes(q)));
  if(t.dateFilter){ const f=t.from?Math.floor(Date.parse(t.from)/1000):null; const to=t.to?Math.floor(Date.parse(t.to)/1000)+86399:null;
    if(f!=null||to!=null) rows=rows.filter(r=>{ const s=Number(r.ts)||0; if(f!=null&&s<f) return false; if(to!=null&&s>to) return false; return true; }); }
  if(!rows.length){ el.innerHTML='<div class="b">'+emptyState('doc',(q||t.from||t.to)?'No matching rows':t.empty,'')+'</div>'; return; }
  const ps=t.ps, pages=Math.max(1,Math.ceil(rows.length/ps)); if(t.page>pages) t.page=pages; if(t.page<1) t.page=1;
  const start=(t.page-1)*ps, pageRows=rows.slice(start,start+ps);
  const thead='<tr>'+t.cols.map(col=>'<th'+(col.align==='right'?' class="num"':'')+'>'+esc(col.label)+'</th>').join('')+'</tr>';
  const body=pageRows.map(r=>'<tr class="'+(r.link?'clk':'')+'"'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'>'+
    (r.cells||[]).map((cell,ci)=>{ const al=t.cols[ci]&&t.cols[ci].align==='right'?' class="num"':''; return '<td'+al+'>'+cellBadge(cell)+'</td>'; }).join('')+'</tr>').join('');
  let h='<div style="overflow-x:auto"><table class="dtbl"><thead>'+thead+'</thead><tbody>'+body+'</tbody></table></div>';
  if(rows.length>ps){ h+='<div class="pager"><span>'+(start+1)+'–'+Math.min(start+ps,rows.length)+' of '+fmtCount(rows.length)+'</span>'+
    '<button onclick="cardTablePage('+i+',-1)"'+(t.page<=1?' disabled':'')+'>Prev</button><span>Page '+t.page+' / '+pages+'</span>'+
    '<button onclick="cardTablePage('+i+',1)"'+(t.page>=pages?' disabled':'')+'>Next</button></div>'; }
  el.innerHTML=h;
}
function cardTableSet(i,k,v){ const t=(window._CT||[])[i]; if(!t) return; t[k]=v; t.page=1; if(k==='from'||k==='to') cardTableRender(i); else cardTableBody(i); }
function cardTableClear(i){ const t=(window._CT||[])[i]; if(!t) return; t.from=''; t.to=''; t.page=1; cardTableRender(i); }
function cardTablePage(i,d){ const t=(window._CT||[])[i]; if(!t) return; t.page+=d; cardTableBody(i); }

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
/* Append the live table scope (date range + active filter chips) to an export href so a download
   always matches exactly what the user is looking at. exportScope maps TBL filter keys → query params. */
function withScope(a){
  let url=a.href; const es=a.exportScope; if(!es) return url;
  const params=[]; const add=(k,v)=>{ if(v!=null&&v!=='') params.push(encodeURIComponent(k)+'='+encodeURIComponent(v)); };
  if(typeof TBL!=='undefined' && TBL){
    if(es.dateFrom && TBL.from) add(es.dateFrom, TBL.from);
    if(es.dateTo && TBL.to) add(es.dateTo, TBL.to);
    const fv=TBL.fval||{};
    Object.keys(es.filters||{}).forEach(k=>{ const sel=fv[k]; if(!sel||sel==='All') return; const spec=es.filters[k];
      if(typeof spec==='string'){ add(spec, sel); }
      else if(spec&&spec.param){ const v=spec.map?spec.map[sel]:sel; add(spec.param, v); } });
  }
  if(params.length) url += (url.indexOf('?')<0?'?':'&')+params.join('&');
  return url;
}
async function doAction(a){
  if(a.href){ const u=withScope(a); try{ window.open(u,'_blank'); }catch(e){ location.href=u; } return; }
  if(a.chooser){ return chooserModal(a); }
  // queryWrite: reuse a legacy panel GET endpoint. With a schema → open the form (submit builds the
  // query string); without → an optional confirm then a direct GET-query write, then refresh.
  if(a.queryWrite){
    if(a.schema){ return formModal(a); }
    if(a.confirm && !window.confirm(a.confirm)) return;
    const res=await getQuery(a.endpoint, a.body||{});
    toast(res&&res.ok?(res.message||'Done'):((res&&(res.message||res.error))||'Error'), !(res&&res.ok)); refresh(); return;
  }
  if((a.method||'POST')==='GET'){ const h=apiToHash(a.endpoint); if(h){ route(h.key,h.id); } return; }
  if(a.builder==='quotation'){ return quoteBuilder(a); }
  if(a.schema){ return formModal(a); }
  if(a.confirm && !window.confirm(a.confirm)) return;
  const ep=a.endpoint||a.post; if(!ep){ return; }   // accept `post:` (menu/row delete actions) as well as `endpoint:`
  const res=await post(ep,a.body||{}); toast(res.ok?(res.message||'Done'):(res.message||res.error||'Error'), !res.ok);
  if(res&&res.ok){ if(res.pdf_b64){ downloadB64(res.pdf_b64,res.filename||'document.pdf'); } else if(res.url){ try{ window.open(res.url,'_blank'); }catch(e){} } }
  refresh();
}
/* Per-row list action: confirm (optional) → POST → toast → refresh the list. */
async function rowPost(o){
  if(!o||!o.endpoint) return;
  if(o.confirm && !window.confirm(o.confirm)) return;
  const res=await post(o.endpoint,o.body||{});
  toast(res.ok?(res.message||'Done'):(res.message||res.error||'Error'), !res.ok);
  refresh();
}
/* Turn a base64 payload into a real file download — avoids any dependency on the server's public URL/domain. */
function downloadB64(b64,filename){
  try{ const bin=atob(b64); const arr=new Uint8Array(bin.length); for(let i=0;i<bin.length;i++) arr[i]=bin.charCodeAt(i);
    const blob=new Blob([arr],{type:'application/pdf'}); const url=URL.createObjectURL(blob);
    const a=document.createElement('a'); a.href=url; a.download=filename||'document.pdf'; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url),5000);
  }catch(e){ toast('Could not open the PDF', true); }
}
// Guided "What happened?" recorder: a tile picker. Each tile carries its own action (a form),
// so the user taps the event first, then only fills the few fields that event needs.
function chooserModal(a){
  window.__CHOOSER__=(a.chooser||[]).map(t=>t.action);
  const tiles=(a.chooser||[]).map((t,i)=>
    '<button type="button" class="chtile" onclick="chooserPick('+i+')">'+
    '<span class="chic '+(t.tone==='in'?'chin':(t.tone==='out'?'chout':(t.tone==='move'?'chmove':'')))+'">'+icon(t.icon||'dots','')+'</span>'+
    '<span class="chtx"><span class="cht">'+esc(t.title)+'</span>'+(t.sub?'<span class="chs">'+esc(t.sub)+'</span>':'')+'</span>'+
    '<svg class="ic chev" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>').join('');
  document.getElementById('modal').innerHTML='<div class="h">'+esc(a.label||'What happened?')+'<button class="x" onclick="closeM()"><svg class="ic" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>'+
    '<div class="b"><div class="chgrid">'+tiles+'</div></div>';
  document.getElementById('ov').classList.add('on');
}
function chooserPick(i){ const act=(window.__CHOOSER__||[])[i]; closeM(); if(act) setTimeout(()=>doAction(act),60); }
window.GAL={};
function formModal(a){
  const pf=a.prefill||{};
  window.GAL={};
  let fields=''; Object.entries(a.schema).forEach(([k,type])=>{
    const lbl=(a.labels&&a.labels[k]!=null)?a.labels[k]:k.replace(/_/g,' '); const val=pf[k]!=null?String(pf[k]):'';
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
    else if(type==='file' || String(type).startsWith('file:')){
      const acc=String(type).startsWith('file:')?String(type).slice(5):'';
      fields+='<div class="fld"><label>'+lbl+'</label><input type="hidden" id="f_'+k+'" value="">'+
        '<input type="file" '+(acc?('accept="'+esc(acc)+'"'):'')+' id="upl_'+k+'" onchange="uploadFileField(\''+k+'\')" style="font:inherit;font-size:13px">'+
        '<span class="mut" id="upls_'+k+'" style="font-size:12px;display:block;margin-top:5px"></span></div>'; }
    else if(String(type).startsWith('enum:')){ const opts=String(type).slice(5).split(',');
      fields+='<div class="fld"><label>'+lbl+'</label><select id="f_'+k+'">'+opts.map(o=>'<option'+(o===val?' selected':'')+'>'+esc(o)+'</option>').join('')+'</select></div>'; }
    else if(String(type).startsWith('combo:')){ const opts=String(type).slice(6).split('|').filter(Boolean);
      fields+='<div class="fld"><label>'+lbl+' <span class="mut" style="font-weight:400">(type a new one or pick existing)</span></label>'+
        '<input id="f_'+k+'" list="dl_'+k+'" autocomplete="off" value="'+esc(val)+'" placeholder="Type or choose…">'+
        '<datalist id="dl_'+k+'">'+opts.map(o=>'<option value="'+esc(o)+'"></option>').join('')+'</datalist></div>'; }
    else if(type==='textarea'){
      fields+='<div class="fld"><label>'+lbl+'</label><textarea id="f_'+k+'" rows="4" style="width:100%;padding:9px 11px;border:1px solid var(--line,#e4e9e4);border-radius:9px;font:inherit;font-size:14px;resize:vertical">'+esc(val)+'</textarea></div>'; }
    else if(type==='date'){
      fields+='<div class="fld"><label>'+lbl+'</label><input type="date" id="f_'+k+'" value="'+esc(val)+'"></div>'; }
    else { const t=(type==='money'||type==='number')?'type="number" step="'+(type==='money'?'0.01':'1')+'"':''; const ph=type==='money'?'placeholder="0.00"':'';
      fields+='<div class="fld"><label>'+lbl+'</label><input id="f_'+k+'" '+t+' '+ph+' value="'+esc(val)+'"></div>'; }
  });
  document.getElementById('modal').innerHTML='<div class="h">'+esc(a.label)+'<button class="x" onclick="closeM()"><svg class="ic" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div><div class="b">'+fields+'</div><div class="f"><button class="btn" onclick="closeM()">Cancel</button><button class="btn pri" id="mok">Save</button></div>';
  document.getElementById('ov').classList.add('on');
  Object.entries(a.schema).forEach(([k,type])=>{ if(type==='gallery') renderGal(k); });
  // Auto-fill a rate field from a source select (e.g. currency → rate_to_usd) so the user can't mismatch them.
  if(a.autoRate && a.autoRate.field && a.autoRate.source && a.autoRate.map){
    const src=document.getElementById('f_'+a.autoRate.source), tgt=document.getElementById('f_'+a.autoRate.field);
    if(src&&tgt){ const apply=()=>{ const v=a.autoRate.map[src.value]; if(v!=null) tgt.value=v; }; src.addEventListener('change',apply); apply(); }
  }
  // autoNote: auto-write the description from the other fields (editable; stops auto-updating once the user types).
  if(a.autoNote && a.autoNote.template){
    const nf=document.getElementById('f_note');
    if(nf){
      let touched = !!(a.prefill && a.prefill.note!=null && String(a.prefill.note)!=='');
      const render=()=>a.autoNote.template.replace(/\{(\w+)\}/g,(m,k)=>{ const el=document.getElementById('f_'+k); const v=el?el.value:((a.prefill&&a.prefill[k])||''); return (v==null?'':String(v)); }).replace(/\s+/g,' ').replace(/\s+—\s*$/,'').trim();
      if(!touched){ nf.value=render(); }
      nf.addEventListener('input',()=>{ touched=true; });
      (a.autoNote.watch||[]).forEach(k=>{ const el=document.getElementById('f_'+k); if(el){ ['change','input'].forEach(ev=>el.addEventListener(ev,()=>{ if(!touched) nf.value=render(); })); } });
    }
  }
  // Live preview: show a computed result (e.g. amount × rate) that updates as the user types.
  if(a.preview && a.preview.left && a.preview.right){
    const pv=document.createElement('div'); pv.className='fpreview'; pv.id='fpv';
    document.querySelector('#modal .b').appendChild(pv);
    const compute=()=>{ const L=parseFloat((document.getElementById('f_'+a.preview.left)||{}).value)||0; const R=parseFloat((document.getElementById('f_'+a.preview.right)||{}).value)||0;
      const cur=a.preview.curField?((document.getElementById('f_'+a.preview.curField)||{}).value||''):'';
      const val=L*R; pv.innerHTML='<span>'+esc(a.preview.label||'You get')+'</span><strong>'+(val>0?val.toLocaleString(undefined,{maximumFractionDigits:2}):'—')+(cur?' '+esc(cur):'')+'</strong>'; };
    ['left','right','curField'].forEach(k=>{ if(a.preview[k]){ const el=document.getElementById('f_'+a.preview[k]); if(el){ el.addEventListener('input',compute); el.addEventListener('change',compute); } } });
    compute();
  }
  document.getElementById('mok').onclick=async()=>{ const body=Object.assign({}, a.body||{});
    Object.entries(a.schema).forEach(([k,type])=>{ if(type==='gallery'){ body[k]=window.GAL[k]||[]; } else { const el=document.getElementById('f_'+k); if(el) body[k]=el.value; } });
    // pathMap: resolve the target URL from a picked field value (e.g. supplier name → /papi/suppliers/{id}/pay).
    let ep=a.endpoint;
    if(a.pathMap && a.pathMap.field && a.pathMap.map){ const sel=body[a.pathMap.field]; const mapped=a.pathMap.map[sel];
      if(!mapped){ toast(a.pathMap.error||'Please choose a valid option',true); return; }
      ep=mapped; delete body[a.pathMap.field]; }
    // valueMap: translate a picked display value into the real value to send (e.g. "SO-12 · Shop 58 · owes …" → order id).
    if(a.valueMap && a.valueMap.field && a.valueMap.map){ const sel=body[a.valueMap.field]; const mapped=a.valueMap.map[sel];
      if(mapped===undefined || mapped===null){ toast(a.valueMap.error||'Please pick a valid option from the list',true); return; }
      const into=a.valueMap.into||a.valueMap.field; body[into]=mapped; if(into!==a.valueMap.field) delete body[a.valueMap.field]; }
    // submitConvert: the user enters the amount in the DISPLAY currency (e.g. USD); multiply by the
    // factor to the record's NATIVE currency (e.g. UGX) before posting, so the endpoint keeps its
    // native-currency contract while the user thinks in the report currency they see everywhere else.
    if(a.submitConvert && a.submitConvert.field && a.submitConvert.factor){
      const f=a.submitConvert.field; const v=parseFloat(body[f]);
      if(!isNaN(v)) body[f]=Math.round(v*a.submitConvert.factor*100)/100;
    }
    // flow:'convert' — one person's cash is converted; if a different receiver is named, the converted
    // amount is then handed to them (convert under source, then transfer), so both people's balances land right.
    if(a.flow==='convert'){
      closeM();
      const fromAmt=parseFloat(body.from_amount)||0, from=body.from_currency, to=body.to_currency, rate=parseFloat(body.rate)||0, src=(body.holder||'').trim(), rcv=(body.receiver||'').trim();
      if(fromAmt<=0||rate<=0||!src){ toast('Enter the amount, rate and whose cash is converted',true); return; }
      const toAmt=Math.round(fromAmt*rate*100)/100;
      const r1=await post('/papi/trade-cashbook/convert',{from_currency:from,to_currency:to,from_amount:fromAmt,rate:rate,holder:src,note:body.note||''});
      if(!r1||!r1.ok){ toast((r1&&(r1.message||r1.error))||'Conversion failed',true); refresh(); return; }
      if(rcv && rcv.toLowerCase()!==src.toLowerCase()){
        const r2=await post('/papi/trade-cashbook/transfer',{from_person:src,to_person:rcv,currency:to,amount:toAmt,note:'Converted '+from+' → '+to+', handed to '+rcv});
        toast((r2&&r2.ok)?(src+' converted '+from+' '+fromAmt.toLocaleString()+' → '+toAmt.toLocaleString()+' '+to+', handed to '+rcv):('Converted, but handing to '+rcv+' failed — use "Moved cash between people"'), !(r2&&r2.ok));
      } else { toast(src+' converted '+from+' '+fromAmt.toLocaleString()+' → '+toAmt.toLocaleString()+' '+to, false); }
      refresh(); return;
    }
    // queryWrite: submit the form as a GET query-string write to a legacy panel endpoint.
    if(a.queryWrite){ closeM(); const res=await getQuery(ep, body); toast(res&&res.ok?(res.message||'Saved'):((res&&(res.message||res.error))||'Error'), !(res&&res.ok)); refresh(); return; }
    closeM(); const res=await post(ep,body);
    // onResult:'payment' — surface the customer's remaining balance right here instead of a generic "Saved".
    let msg=res.message;
    if(a.onResult==='payment' && res.ok){ msg = (res.state==='paid') ? ('✓ '+(res.order_no||'Order')+' paid in full') : ((res.order_no||'Order')+' — balance left: '+Number(res.balance||0).toLocaleString()); }
    toast(res.ok?(msg||'Saved'):(msg||res.error||'Error'), !res.ok); refresh(); };
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
/* Generic file field (zip/csv/etc) — reads the chosen file to a base64 data URL held in the hidden f_<k>. */
function uploadFileField(k){
  const fi=document.getElementById('upl_'+k); const f=fi&&fi.files&&fi.files[0]; if(!f) return;
  const st=document.getElementById('upls_'+k);
  if(f.size>25*1024*1024){ if(st) st.textContent='File too large (max 25MB)'; document.getElementById('f_'+k).value=''; return; }
  if(st) st.textContent='Reading '+f.name+'…';
  const reader=new FileReader();
  reader.onload=()=>{ document.getElementById('f_'+k).value=String(reader.result||''); if(st) st.textContent='✓ '+f.name+' ready — click Save to import'; };
  reader.onerror=()=>{ if(st) st.textContent='Could not read the file'; document.getElementById('f_'+k).value=''; };
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
  const edit=!!a.edit;
  const invoice=!!a.invoice;
  // Edit mode pre-loads the quotation's existing lines so they can be changed/removed.
  const seed=(a.lines||[]).map(l=>({name:String(l.name||''),unit:String(l.unit||l.unit_label||''),qty:Math.max(1,parseInt(l.qty||1,10)||1),price:Math.max(0,parseFloat(l.price!=null?l.price:l.unit_price)||0)}));
  window.QB={lines:seed, cur:cur, a:a, edit:edit, invoice:invoice, _res:[], _cres:[], customerId:(a.prefill&&a.prefill.id)||null, customerName:a.customer||'', customerPhone:(a.prefill&&a.prefill.phone)||'', pick:!!a.pickCustomer};
  const curOpts=(a.currencies||[cur]).map(c=>'<option'+(c===cur?' selected':'')+'>'+esc(c)+'</option>').join('');
  const m=document.getElementById('modal'); m.style.maxWidth='660px';
  const custBlock=a.pickCustomer?'<div class="qb-custwrap" id="qbcustwrap"></div>':'';
  const noun=invoice?'invoice':'quotation';
  const title=(edit?'Edit ':'New ')+noun+(a.customer?' — '+esc(a.customer):'');
  const okLabel=edit?'Save changes':('Create '+noun);
  const waBlock=(edit||invoice)?'':'<label class="qb-wa"><input type="checkbox" id="qbwa"> Also send the PDF to the customer on WhatsApp</label>';
  m.innerHTML='<div class="h">'+title+'<button class="x" onclick="closeQB()">'+icon('x')+'</button></div>'+
    '<div class="b">'+custBlock+
      '<div class="qb-pick"><input id="qbq" placeholder="Search products to add…" autocomplete="off" oninput="qbSearch()"><div class="qb-res" id="qbres"></div></div>'+
      '<div id="qblines"></div>'+
      (invoice?'<div class="qb-adj"><label>Discount <input id="qbdisc" type="number" min="0" step="0.01" value="0" oninput="qbTotal()"></label>'+
        '<label>Delivery / freight <input id="qbfrt" type="number" min="0" step="0.01" value="0" oninput="qbTotal()"></label></div>':'')+
      '<div class="qb-bar"><label class="qb-cur">Currency <select id="qbcur" onchange="qbCur()">'+curOpts+'</select></label>'+
        '<div class="qb-tot">Total <b id="qbtot">'+esc(curFmt(0,cur))+'</b></div></div>'+
      waBlock+
    '</div>'+
    '<div class="f"><button class="btn" onclick="closeQB()">Cancel</button><button class="btn pri" id="qbok" onclick="qbSubmit()"'+(seed.length?'':' disabled')+'>'+esc(okLabel)+'</button></div>';
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
function qbCustPick(i){ const it=(window.QB._cres||[])[i]; if(!it) return; window.QB.customerId=it.id; window.QB.customerName=it.name; window.QB.customerPhone=it.phone||''; qbRenderCust(); qbTotal(); }
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
function qbNum(id){ const el=document.getElementById(id); return el?(Math.max(0,parseFloat(el.value||'0')||0)):0; }
function qbTotal(){ let t=0; window.QB.lines.forEach((l,i)=>{ const a=l.qty*l.price; t+=a; const el=document.getElementById('qbamt'+i); if(el) el.textContent=curFmt(a,window.QB.cur); });
  let grand=t;
  if(window.QB.invoice){ grand=Math.max(0, t - qbNum('qbdisc') + qbNum('qbfrt')); }
  const tot=document.getElementById('qbtot'); if(tot) tot.textContent=curFmt(grand,window.QB.cur);
  const ok=document.getElementById('qbok'); if(ok) ok.disabled=window.QB.lines.length===0 || (window.QB.pick && !window.QB.customerId);
}
async function qbSubmit(){
  const a=window.QB.a; const ok=document.getElementById('qbok'); if(!window.QB.lines.length) return;
  if(window.QB.pick && !window.QB.customerId){ toast('Choose a customer first', true); return; }
  if(window.QB.invoice){
    // Issue a numbered invoice via the panel's proven /papi/invoice-issue (customer + line items).
    const body={ customer:window.QB.customerName||'', phone:window.QB.customerPhone||'', currency:window.QB.cur,
      discount:qbNum('qbdisc'), delivery:qbNum('qbfrt'),
      lines:JSON.stringify(window.QB.lines.map(l=>({name:l.name,qty:l.qty,unit:l.price,uom:l.unit||''}))) };
    ok.disabled=true; ok.textContent='Issuing…';
    const res=await post(a.endpoint,body); closeQB();
    if(res&&res.ok){ toast('Invoice '+(res.invoice_no||'')+' created'+(res.already?' (already existed)':''));
      if(res.pdf){ try{ window.open(res.pdf,'_blank'); }catch(e){} } refresh(); }
    else { toast((res&&(res.message||res.error))||'Could not create the invoice', true); }
    return;
  }
  if(window.QB.edit){
    // Edit → reuse the panel's proven quotation-update endpoint (rebuilds the PDF, keeps the number).
    const pf=a.prefill||{};
    const body={ id:pf.id, currency:window.QB.cur, name:a.customer||pf.name||'', phone:pf.phone||'',
      items:JSON.stringify(window.QB.lines.map(l=>({name:l.name,qty:l.qty,unit_price:l.price,unit_label:l.unit||''}))) };
    ok.disabled=true; ok.textContent='Saving…';
    const res=await post(a.endpoint,body); closeQB();
    if(res&&res.ok){ toast(res.message||'Quotation updated'); refresh(); }
    else { toast((res&&(res.message||res.error))||'Could not update quotation', true); }
    return;
  }
  const body={lines:window.QB.lines.map(l=>({name:l.name,qty:l.qty,price:l.price})), currency:window.QB.cur, send_whatsapp:document.getElementById('qbwa').checked};
  if(window.QB.customerId) body.customer_id=window.QB.customerId;
  ok.disabled=true; ok.textContent='Creating…';
  const res=await post(a.endpoint,body); closeQB();
  if(res&&res.ok){ toast(res.message||('Quotation '+res.quote_no+' created'));
    if(res.id){ try{ window.open('/papi/quotation-pdf?id='+res.id,'_blank'); }catch(e){} }
    else if(res.pdf_b64){ downloadB64(res.pdf_b64,res.filename||'Quotation.pdf'); }
    refresh(); }
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
  // A drill-through from another screen (e.g. a category card) pre-applies its filter here.
  if(PENDING_FILTER){ TBL.fval[PENDING_FILTER.key]=PENDING_FILTER.val; PENDING_FILTER=null; }
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
  if(col.type==='action'){
    // Multiple per-row icon buttons (href opens, post fires a mutation then refreshes).
    if(c && c.buttons && c.buttons.length){
      return '<td><div class="rowacts">'+rowActBtnsHtml(c.buttons)+'</div></td>';
    }
    const href=(c&&c.href)||''; const label=cellVal(c)||'Open';
    return '<td>'+(href?'<button class="btn sm" onclick="event.stopPropagation();try{window.open(\''+esc(href)+'\',\'_blank\')}catch(e){location.href=\''+esc(href)+'\'}">'+icon((c&&c.icon)||'download','ic-sm')+esc(label)+'</button>':'—')+'</td>'; }
  const tone=(c&&c.tone)?(' '+toneCls(c.tone)):'';
  return '<td class="'+align+tone+'">'+esc(cellVal(c))+'</td>';
}

/* ================= Storefront merchandising editors (native v3) =================
   Client-rendered pages wired to the existing /papi endpoints (session-authed, same as the
   classic panel). No server workspace payload — route() special-cases these two keys. */
var SFCFG={enabled:false,title:'',autoscroll:5,'default':'',promotions:[],businesses:[]};
var SFKITS=[], SFKCOLLS=[], _sfPhoto=null;
var SF_ICONS=['home','hotels','restaurants','retail','supermarkets','wholesale','catering','offices','bakeries','cleaning','shop'];
function sfEl(id){return document.getElementById(id);}
function sfj(s){return JSON.stringify(String(s==null?'':s)).replace(/"/g,'&quot;');}
function sfHiddenFile(){ var f=sfEl('sfPhotoFile'); if(!f){ f=document.createElement('input'); f.type='file'; f.accept='image/*'; f.id='sfPhotoFile'; f.style.display='none'; f.onchange=sfPhotoPicked; document.body.appendChild(f);} return f; }
function sfShrink(file,maxDim,q){ maxDim=maxDim||1400;q=q||0.82; return new Promise(function(res){ var rd=new FileReader(); rd.onerror=function(){res(null);}; rd.onload=function(){ var img=new Image(); img.onload=function(){ var w=img.width,h=img.height,s=Math.min(1,maxDim/Math.max(w,h)); var cw=Math.max(1,Math.round(w*s)),ch=Math.max(1,Math.round(h*s)); try{ var c=document.createElement('canvas'); c.width=cw;c.height=ch; var x=c.getContext('2d'); x.fillStyle='#fff';x.fillRect(0,0,cw,ch);x.drawImage(img,0,0,cw,ch); res(c.toDataURL('image/jpeg',q)); }catch(e){res(rd.result);} }; img.onerror=function(){res(rd.result);}; img.src=rd.result; }; rd.readAsDataURL(file); }); }
function sfTabs(active){
  return '<div class="sftabs">'
    +'<button class="sftab'+(active==='collections'?' on':'')+'" onclick="route(\'storefront.collections\')">Shop for Every Need</button>'
    +'<button class="sftab'+(active==='kits'?' on':'')+'" onclick="route(\'storefront.kits\')">Ready-made Kits</button>'
    +'<button class="sftab'+(active==='delivery'?' on':'')+'" onclick="route(\'storefront.delivery\')">Delivery &amp; Free Shipping</button></div>';
}

/* ---------- Delivery & Free Shipping (per-city free-delivery thresholds) ---------- */
var SFDEL=[], SFDEL_CUR='USD';
function sfDeliveryPage(){
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('delivery')+'<div class="card"><div class="b">Loading…</div></div></div>';
  getQuery('/papi/delivery-rules',{}).then(function(d){
    SFDEL_CUR=(d&&d.currency)||'USD';
    SFDEL=(d&&Array.isArray(d.free_rules))?d.free_rules.slice():[];
    if(!SFDEL.length){
      // Seed a row per branch so the merchant just types amounts (Juba pre-filled at 50).
      var br=(d&&Array.isArray(d.branches)&&d.branches.length)?d.branches:['Juba','Kampala'];
      SFDEL=br.map(function(c){return {city:c, free_over:(/juba/i.test(c)?50:(/kampala/i.test(c)?20:0))};});
    }
    sfDeliveryRender();
  }).catch(function(){ SFDEL=[{city:'Juba',free_over:50}]; sfDeliveryRender(); });
}
function sfDeliveryRender(){
  var head='<div class="card"><div class="h">Delivery &amp; Free Shipping<button class="btn pri" style="margin-left:auto" onclick="sfSaveDelivery()">Save</button></div>'
    +'<div class="b" style="color:var(--muted);font-size:13px">Set a free-delivery threshold per city. When a customer selects that city and their cart reaches the amount, the storefront shows a “FREE delivery” badge and a progress nudge, and the order note tells you delivery is free. Amounts are in '+esc(SFDEL_CUR)+'. Set 0 to disable free delivery for a city.</div></div>';
  var rows=SFDEL.map(function(r,i){
    return '<div class="sfrow" style="margin:8px 0;align-items:flex-end;gap:10px">'
      +'<div class="sffield" style="flex:1;min-width:160px"><label>City</label><input class="sfin" value="'+esc(r.city||'')+'" placeholder="e.g. Juba" onchange="sfDelUpd('+i+',\'city\',this.value)"></div>'
      +'<div class="sffield"><label>Free delivery over ('+esc(SFDEL_CUR)+')</label><input class="sfin" type="number" min="0" step="0.01" style="width:150px" value="'+(Number(r.free_over)||0)+'" onchange="sfDelUpd('+i+',\'free_over\',this.value)"></div>'
      +'<button class="btn sm danger" onclick="sfDelDel('+i+')">Remove</button></div>';
  }).join('');
  var body='<div class="card"><div class="b">'+(rows||'<div style="color:var(--muted)">No cities yet — add one.</div>')+'<button class="btn sm" style="margin-top:10px" onclick="sfDelAdd()">+ Add city</button></div></div>';
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('delivery')+head+'<div style="height:14px"></div>'+body+'</div>';
}
function sfDelUpd(i,k,v){ if(!SFDEL[i])return; SFDEL[i][k]=(k==='free_over')?Math.max(0,parseFloat(v)||0):v; }
function sfDelAdd(){ SFDEL.push({city:'',free_over:0}); sfDeliveryRender(); }
function sfDelDel(i){ SFDEL.splice(i,1); sfDeliveryRender(); }
async function sfSaveDelivery(){
  var rules=SFDEL.map(function(r){return {city:String(r.city||'').trim(),free_over:Math.max(0,parseFloat(r.free_over)||0)};}).filter(function(r){return r.city&&r.free_over>0;});
  var d=await post('/papi/delivery-rules-save',{free_rules:rules});
  if(d&&d.ok){ if(Array.isArray(d.free_rules))SFDEL=d.free_rules.length?d.free_rules:SFDEL; sfDeliveryRender(); toast('Delivery rules saved'); } else toast((d&&d.error)||'Could not save',true);
}

/* ---------- Shop for Every Need ---------- */
function sfCollectionsPage(){
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('collections')+'<div class="card"><div class="b">Loading…</div></div></div>';
  getQuery('/papi/business-banners',{}).then(function(d){
    var c=(d&&d.config)||{};
    SFCFG={enabled:!!c.enabled,title:c.title||'',autoscroll:(c.autoscroll==null?5:c.autoscroll),'default':c['default']||'',promotions:Array.isArray(c.promotions)?c.promotions:[],businesses:Array.isArray(c.businesses)?c.businesses:[]};
    sfCollectionsRender();
  }).catch(function(){ SFCFG={enabled:false,title:'',autoscroll:5,'default':'',promotions:[],businesses:[]}; sfCollectionsRender(); });
}
function sfCollectionsRender(){
  var defOpts='<option value="">First / most-used</option>'+SFCFG.businesses.map(function(b){return '<option value="'+sfj(b.id)+'"'+(SFCFG['default']===b.id?' selected':'')+'>'+esc(b.label||b.id)+'</option>';}).join('');
  var head='<div class="card"><div class="h">Shop for Every Need'
    +'<label class="sfchk" style="margin-left:auto"><input type="checkbox" '+(SFCFG.enabled?'checked':'')+' onchange="sfSet(\'enabled\',this.checked)"> Show on storefront</label></div>'
    +'<div class="b"><div class="sfrow">'
      +'<div class="sffield" style="flex:1;min-width:200px"><label>Section title</label><input class="sfin" value="'+esc(SFCFG.title||'')+'" placeholder="Shop for Every Need" onchange="sfSet(\'title\',this.value)"></div>'
      +'<div class="sffield"><label>Auto-scroll (sec, 0=off)</label><input class="sfin" type="number" min="0" max="30" style="width:120px" value="'+(SFCFG.autoscroll==null?5:SFCFG.autoscroll)+'" onchange="sfSet(\'autoscroll\',this.value)"></div>'
      +'<div class="sffield"><label>Default for new visitors</label><select class="sfsel" onchange="sfSet(\'default\',this.value)">'+defOpts+'</select></div>'
      +'<button class="btn" onclick="sfLoadStarter()">Load starter template</button>'
      +'<button class="btn pri" style="margin-left:auto" onclick="sfSaveCollections()">Save</button>'
    +'</div>'
    +'<div class="sffield" style="margin-top:12px"><label>Current promotions — product names, comma-separated (shown as a homepage row)</label><input class="sfin" value="'+esc((SFCFG.promotions||[]).join(', '))+'" placeholder="Aluminium Foil 150m, Cling Film 300m" onchange="sfSetProm(this.value)"></div>'
    +'</div></div>';
  var body=SFCFG.businesses.length?SFCFG.businesses.map(function(b,bi){return sfBizCard(b,bi);}).join(''):'<div class="card"><div class="b" style="color:var(--muted)">No collections yet. Add one, or load the starter template.</div></div>';
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('collections')+head+'<div style="height:14px"></div>'+body
    +'<button class="btn" onclick="sfAddBiz()" style="margin-top:12px">+ Add collection</button></div>';
}
function sfBizCard(b,bi){
  var icons=SF_ICONS.map(function(k){return '<option value="'+k+'"'+((b.icon||'')===k?' selected':'')+'>'+k+'</option>';}).join('');
  var slides=(b.slides||[]).map(function(s,si){return sfSlideRow(s,bi,si);}).join('');
  var colls=(b.collections||[]).map(function(c,ci){return sfCollRow(c,bi,ci);}).join('');
  var best=Array.isArray(b.bestsellers)?b.bestsellers.join(', '):(b.bestsellers||'auto');
  var card=b.card_image?('<img class="sfphoto" src="'+esc(b.card_image)+'">'):('<div class="sfphoto">card</div>');
  return '<div class="card" style="margin-bottom:14px"><div class="h">'
    +'<input class="sfin" value="'+esc(b.label||'')+'" placeholder="Collection label" style="font-weight:700;max-width:230px" onchange="sfUpd('+bi+',\'label\',this.value)">'
    +'<label class="sfchk" style="margin-left:8px"><input type="checkbox" '+(b.active!==false?'checked':'')+' onchange="sfUpd('+bi+',\'active\',this.checked)"> Active</label>'
    +'<div style="margin-left:auto;display:flex;gap:6px"><button class="btn sm" onclick="sfMoveBiz('+bi+',-1)" title="Move up">↑</button><button class="btn sm" onclick="sfMoveBiz('+bi+',1)" title="Move down">↓</button><button class="btn sm danger" onclick="sfDelBiz('+bi+')">Remove</button></div></div>'
    +'<div class="b">'
      +'<div class="sfrow" style="margin-bottom:12px">'
        +'<div style="text-align:center">'+card+'<div style="margin-top:5px"><button class="btn sm" onclick="sfPickPhoto(\'card\','+bi+')">Card photo</button>'+(b.card_image?' <button class="btn sm danger" onclick="sfClearCard('+bi+')">✕</button>':'')+'</div></div>'
        +'<div class="sffield" style="flex:1;min-width:160px"><label>One-line description</label><input class="sfin" value="'+esc(b.sub||'')+'" placeholder="Everyday basics" onchange="sfUpd('+bi+',\'sub\',this.value)"></div>'
        +'<div class="sffield"><label>Icon</label><select class="sfsel" onchange="sfUpd('+bi+',\'icon\',this.value)">'+icons+'</select></div>'
        +'<div class="sffield"><label>ID</label><input class="sfin" value="'+esc(b.id||'')+'" style="width:110px" onchange="sfUpd('+bi+',\'id\',this.value)"></div>'
      +'</div>'
      +'<div class="sfrow" style="margin-bottom:6px">'
        +'<div class="sffield" style="flex:1;min-width:200px"><label>Match categories (comma)</label><input class="sfin" value="'+esc((b.match&&b.match.categories||[]).join(', '))+'" onchange="sfMatch('+bi+',\'categories\',this.value)"></div>'
        +'<div class="sffield" style="flex:1;min-width:200px"><label>Match keywords (comma)</label><input class="sfin" value="'+esc((b.match&&b.match.keywords||[]).join(', '))+'" onchange="sfMatch('+bi+',\'keywords\',this.value)"></div>'
      +'</div>'
      +'<label class="sfchk" style="margin-bottom:12px"><input type="checkbox" '+(b.match&&b.match.bulk?'checked':'')+' onchange="sfMatchBulk('+bi+',this.checked)"> Also include all bulk / carton items (pack or MOQ &gt; 1)</label>'
      +'<div style="font-weight:700;font-size:13px;margin:2px 0 8px">Hero slides (auto-scroll)</div>'+slides
      +'<button class="btn sm" style="margin-top:4px" onclick="sfAddSlide('+bi+')">+ Add slide</button>'
      +'<div style="font-weight:700;font-size:13px;margin:14px 0 8px">Collections</div>'+colls
      +'<button class="btn sm" style="margin-top:4px" onclick="sfAddColl('+bi+')">+ Add collection</button>'
      +'<div class="sffield" style="margin-top:12px"><label>Best sellers (product names, comma — or "auto")</label><input class="sfin" value="'+esc(best)+'" placeholder="auto" onchange="sfUpd('+bi+',\'bestsellers\',this.value)"></div>'
    +'</div></div>';
}
function sfSlideRow(s,bi,si){
  var img=s.image_url?('<img class="sfphoto" src="'+esc(s.image_url)+'">'):('<div class="sfphoto" style="background:'+(s.theme||'#123a2a')+';color:#fff">hero</div>');
  return '<div class="sfmini"><div class="sfrow">'
    +'<div style="text-align:center">'+img+'<div style="margin-top:5px"><button class="btn sm" onclick="sfPickPhoto(\'slide\','+bi+','+si+')">Upload</button>'+(s.image_url?' <button class="btn sm danger" onclick="sfClearSlide('+bi+','+si+')">✕</button>':'')+'</div></div>'
    +'<div style="flex:1;min-width:220px">'
      +'<div class="sfrow"><input class="sfin" style="flex:1" value="'+esc(s.kick||'')+'" placeholder="Kicker (Hotel Supplies)" onchange="sfSlide('+bi+','+si+',\'kick\',this.value)"><input class="sfin" style="flex:1" value="'+esc(s.cta_label||'')+'" placeholder="Button (Shop Collection)" onchange="sfSlide('+bi+','+si+',\'cta_label\',this.value)"></div>'
      +'<input class="sfin" style="width:100%;margin-top:6px" value="'+esc(s.title||'')+'" placeholder="Headline — wrap a word in *stars* to highlight" onchange="sfSlide('+bi+','+si+',\'title\',this.value)">'
      +'<input class="sfin" style="width:100%;margin-top:6px" value="'+esc((s.points||[]).join(' | '))+'" placeholder="Bullets separated by |" onchange="sfPoints('+bi+','+si+',this.value)">'
      +'<div class="sfrow" style="margin-top:6px"><input class="sfin" style="flex:1" value="'+esc(s.theme||'')+'" placeholder="CSS background (if no photo)" onchange="sfSlide('+bi+','+si+',\'theme\',this.value)"><input class="sfin" style="flex:1" value="'+esc(s.cta||'')+'" placeholder="Button target (blank=shop this)" onchange="sfSlide('+bi+','+si+',\'cta\',this.value)"><label class="sfchk"><input type="checkbox" '+(s.gold?'checked':'')+' onchange="sfSlide('+bi+','+si+',\'gold\',this.checked)"> Gold</label><button class="btn sm danger" onclick="sfDelSlide('+bi+','+si+')">Remove</button></div>'
    +'</div></div></div>';
}
function sfCollRow(c,bi,ci){
  return '<div class="sfrow" style="margin-bottom:6px"><input class="sfin" style="flex:1" value="'+esc(c.label||'')+'" placeholder="Collection label" onchange="sfColl('+bi+','+ci+',\'label\',this.value)"><input class="sfin" style="flex:1" value="'+esc(c.cat||'')+'" placeholder="Jump to category" onchange="sfColl('+bi+','+ci+',\'cat\',this.value)"><input class="sfin" style="flex:1" value="'+esc(c.keywords||'')+'" placeholder="or keyword" onchange="sfColl('+bi+','+ci+',\'keywords\',this.value)"><button class="btn sm danger" onclick="sfDelColl('+bi+','+ci+')">✕</button></div>';
}
function sfB(bi){return SFCFG.businesses[bi];}
function sfSet(k,v){ if(k==='autoscroll')v=Math.max(0,Math.min(30,parseInt(v,10)||0)); SFCFG[k]=v; }
function sfSetProm(v){ SFCFG.promotions=String(v).split(',').map(function(x){return x.trim();}).filter(Boolean); }
function sfUpd(bi,k,v){var b=sfB(bi);if(!b)return; if(k==='bestsellers'){v=String(v).trim();b.bestsellers=(v===''||v.toLowerCase()==='auto')?'auto':v.split(',').map(function(x){return x.trim();}).filter(Boolean);} else b[k]=v; if(k==='label'||k==='id')sfCollectionsRender();}
function sfMatch(bi,k,v){var b=sfB(bi);if(!b)return;b.match=b.match||{};b.match[k]=v.split(',').map(function(x){return x.trim();}).filter(Boolean);}
function sfMatchBulk(bi,on){var b=sfB(bi);if(!b)return;b.match=b.match||{};b.match.bulk=!!on;}
function sfSlide(bi,si,k,v){var b=sfB(bi);if(!b||!b.slides||!b.slides[si])return;b.slides[si][k]=v;}
function sfPoints(bi,si,v){var b=sfB(bi);if(!b||!b.slides||!b.slides[si])return;b.slides[si].points=v.split('|').map(function(x){return x.trim();}).filter(Boolean);}
function sfColl(bi,ci,k,v){var b=sfB(bi);if(!b||!b.collections||!b.collections[ci])return;b.collections[ci][k]=v;}
function sfAddBiz(){SFCFG.businesses.push({id:'biz'+(SFCFG.businesses.length+1),label:'New Collection',sub:'',icon:'shop',active:true,card_image:'',match:{categories:[],keywords:[],bulk:false},slides:[{kick:'',title:'New headline',points:[],cta_label:'Shop now',cta:'',gold:false,theme:'linear-gradient(135deg,#123a2a,#0b5c46)',image_url:''}],collections:[],bestsellers:'auto'});sfCollectionsRender();}
function sfDelBiz(bi){if(!confirm('Remove this collection?'))return;SFCFG.businesses.splice(bi,1);sfCollectionsRender();}
function sfMoveBiz(bi,dir){var j=bi+dir;if(j<0||j>=SFCFG.businesses.length)return;var a=SFCFG.businesses,t=a[bi];a[bi]=a[j];a[j]=t;sfCollectionsRender();}
function sfAddSlide(bi){var b=sfB(bi);if(!b)return;b.slides=b.slides||[];b.slides.push({kick:'',title:'New headline',points:[],cta_label:'Shop now',cta:'',gold:false,theme:'linear-gradient(135deg,#123a2a,#0b5c46)',image_url:''});sfCollectionsRender();}
function sfDelSlide(bi,si){var b=sfB(bi);if(!b||!b.slides)return;b.slides.splice(si,1);sfCollectionsRender();}
function sfAddColl(bi){var b=sfB(bi);if(!b)return;b.collections=b.collections||[];b.collections.push({label:'',cat:'',keywords:'',icon:'shop',image_url:''});sfCollectionsRender();}
function sfDelColl(bi,ci){var b=sfB(bi);if(!b||!b.collections)return;b.collections.splice(ci,1);sfCollectionsRender();}
function sfClearCard(bi){var b=sfB(bi);if(b){b.card_image='';sfCollectionsRender();}}
function sfClearSlide(bi,si){var b=sfB(bi);if(b&&b.slides&&b.slides[si]){b.slides[si].image_url='';sfCollectionsRender();}}
function sfPickPhoto(type,bi,si){_sfPhoto={type:type,bi:bi,si:si};var f=sfHiddenFile();f.value='';f.click();}
async function sfPhotoPicked(){
  var f=sfEl('sfPhotoFile');var file=f&&f.files&&f.files[0];if(!file||!_sfPhoto)return;
  toast('Uploading photo…');
  var data=await sfShrink(file,1600,.82); if(!data){toast('Could not read image',true);return;}
  var d=await post('/papi/upload-image',{name:(file.name||'img')+'.jpg',data:data});
  if(d&&d.ok&&d.url){
    if(_sfPhoto.type==='kit'){ var k=SFKITS[_sfPhoto.bi]; if(k){k.image_url=d.url;sfKitsRender();} }
    else { var b=sfB(_sfPhoto.bi); if(b){ if(_sfPhoto.type==='card')b.card_image=d.url; else if(b.slides&&b.slides[_sfPhoto.si])b.slides[_sfPhoto.si].image_url=d.url; sfCollectionsRender(); } }
    toast('Photo added');
  } else toast('Upload failed — try a smaller image',true);
}
async function sfSaveCollections(){
  var d=await post('/papi/business-banners-save',{config:SFCFG});
  if(d&&d.ok){ if(d.config)SFCFG=d.config; sfCollectionsRender(); toast('Saved'); } else toast((d&&d.error)||'Could not save',true);
}
function sfLoadStarter(){
  if(SFCFG.businesses.length && !confirm('Replace the current collections with the starter template?'))return;
  var g={home:'linear-gradient(135deg,#0b5c46,#0b7a3b)',hotels:'linear-gradient(135deg,#123a6b,#1e5aa8)',restaurants:'linear-gradient(135deg,#5a2c12,#9a4a12)',retail:'linear-gradient(135deg,#123018,#0b7a3b)',wholesale:'linear-gradient(135deg,#2a1a4a,#5a3a9a)',catering:'linear-gradient(135deg,#0d4a50,#12959f)',offices:'linear-gradient(135deg,#334155,#556579)'};
  var m=function(cats,kws,bulk){return {categories:cats,keywords:kws,bulk:!!bulk};};
  var sl=function(kick,title,pts,cta,gold,theme){return {kick:kick,title:title,points:pts,cta_label:cta,cta:'',gold:!!gold,theme:theme,image_url:''};};
  var col=function(label,cat){return {label:label,cat:cat,keywords:'',icon:'shop',image_url:''};};
  SFCFG.enabled=true;SFCFG.title='Shop for Every Need';SFCFG.autoscroll=5;SFCFG['default']='wholesale';
  SFCFG.businesses=[
    {id:'wholesale',label:'Wholesale Deals',sub:'Bulk & carton pricing',icon:'wholesale',active:true,card_image:'',match:m([],['carton','bulk','pack'],true),slides:[sl('Wholesale Deals','Bulk Buying Made *Easy*',['Carton & bulk packs','Lowest MOQ, best margins','Fast quotations'],'View Wholesale Deals',true,g.wholesale)],collections:[],bestsellers:'auto'},
    {id:'restaurants',label:'Restaurant Supplies',sub:'Kitchen & packaging',icon:'restaurants',active:true,card_image:'',match:m(['Disposables, Foil & Wraps','Recipe Mixes & Masalas','Ground Spices (Powders)','Whole Spices','Sauces & Condiments','Cleaning & Hygiene'],['foil','cling','container','glove','oil','masala','spice','sauce','packaging','clean']),slides:[sl('Restaurant Supplies','Kitchen & Packaging *Essentials*',['Foil, cling & containers','Disposable gloves & kitchen cleaning','Packaging that sells'],'Shop Restaurant Collection',false,g.restaurants)],collections:[col('Foil & Wraps','Disposables, Foil & Wraps'),col('Recipe Mixes & Masalas','Recipe Mixes & Masalas')],bestsellers:'auto'},
    {id:'hotels',label:'Hotel Supplies',sub:'Housekeeping & guest',icon:'hotels',active:true,card_image:'',match:m(['Cleaning & Hygiene','Disposables, Foil & Wraps'],['towel','tissue','soap','shampoo','clean','foil','sanitiser','detergent','amenity']),slides:[sl('Hotel Supplies','Everything Your *Hotel* Needs',['Housekeeping & guest amenities','Foil, cling & kitchen','Cleaning chemicals'],'Shop Hotel Collection',false,g.hotels)],collections:[col('Cleaning & Hygiene','Cleaning & Hygiene'),col('Foil & Disposables','Disposables, Foil & Wraps')],bestsellers:'auto'},
    {id:'retail',label:'Retail Store Essentials',sub:'Fast-moving stock',icon:'retail',active:true,card_image:'',match:m(['Snacks & Namkeen','Sauces & Condiments','Recipe Mixes & Masalas','Dal & Pulses','Rice, Biryani & Ready Mixes','Cleaning & Hygiene'],['snack','sauce','rice','dal','masala','tea','sugar','clean','tissue']),slides:[sl('Retail Store Essentials','Stock Your *Shelves* Faster',['Fast-moving consumer goods','Best-selling grocery & household','Cleaning & snacks'],'Browse Retail Best Sellers',false,g.retail)],collections:[col('Snacks & Namkeen','Snacks & Namkeen'),col('Rice & Ready Mixes','Rice, Biryani & Ready Mixes'),col('Cleaning','Cleaning & Hygiene')],bestsellers:'auto'},
    {id:'home',label:'Home Essentials',sub:'Daily groceries & household',icon:'home',active:true,card_image:'',match:m(['Cleaning & Hygiene','Snacks & Namkeen','Rice, Biryani & Ready Mixes','Dal & Pulses','Sauces & Condiments'],['oil','rice','sugar','tea','tissue','clean','snack','soap','detergent','flour','salt']),slides:[sl('Home Essentials','Everything Your *Home* Needs',['Cooking, cleaning & everyday basics','Great prices, fast delivery','Buy one, or buy in bulk'],'Shop Home Essentials',false,g.home)],collections:[col('Cooking & Staples','Rice, Biryani & Ready Mixes'),col('Cleaning & Hygiene','Cleaning & Hygiene'),col('Snacks','Snacks & Namkeen')],bestsellers:'auto'},
    {id:'catering',label:'Catering Supplies',sub:'Events & food service',icon:'catering',active:true,card_image:'',match:m(['Disposables, Foil & Wraps','Recipe Mixes & Masalas','Ground Spices (Powders)'],['plate','cup','cutlery','foil','cling','container','napkin','straw','masala']),slides:[sl('Catering & Events','Everything for *Catering & Events*',['Disposable plates, cups & cutlery','Containers, foil & cling','Serve hundreds, easily'],'Shop Catering Supplies',false,g.catering)],collections:[col('Disposables & Foil','Disposables, Foil & Wraps')],bestsellers:'auto'},
    {id:'offices',label:'Office Supplies',sub:'Pantry & cleaning',icon:'offices',active:true,card_image:'',match:m(['Cleaning & Hygiene','Snacks & Namkeen','Sauces & Condiments'],['tea','coffee','tissue','clean','sugar','water','pantry','biscuit']),slides:[sl('Office Supplies','Keep Your *Office* Running Smoothly',['Tea, coffee & pantry','Tissue & cleaning','Stationery & essentials'],'Shop Office Supplies',false,g.offices)],collections:[col('Pantry & Snacks','Snacks & Namkeen'),col('Cleaning & Tissue','Cleaning & Hygiene')],bestsellers:'auto'}
  ];
  sfCollectionsRender();toast('Starter loaded — review, add photos, then Save');
}

/* ---------- Ready-made Kits ---------- */
function sfKitsPage(){
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('kits')+'<div class="card"><div class="b">Loading…</div></div></div>';
  // collection options come from the banners config; products from the panel feed (for the datalist)
  getQuery('/papi/business-banners',{}).then(function(d){ var bs=(d&&d.config&&d.config.businesses)||[]; SFKCOLLS=bs.map(function(b){return {id:b.id,label:b.label||b.id};}); }).catch(function(){SFKCOLLS=[];})
  .finally(function(){
    getQuery('/papi/products',{}).then(function(d){ var rows=(d&&d.products)||[]; window.__SFPROD=rows.map(function(p){return p['Product Name']||'';}).filter(Boolean); }).catch(function(){window.__SFPROD=[];})
    .finally(function(){
      getQuery('/papi/kits',{}).then(function(d){ SFKITS=(d&&Array.isArray(d.kits))?d.kits:[]; sfKitsRender(); }).catch(function(){SFKITS=[];sfKitsRender();});
    });
  });
}
function sfKitsRender(){
  var dl='<datalist id="sfProdNames">'+((window.__SFPROD)||[]).map(function(n){return '<option value="'+esc(n)+'">';}).join('')+'</datalist>';
  var head='<div class="card"><div class="h">Ready-made Kits<button class="btn pri" style="margin-left:auto" onclick="sfSaveKits()">Save kits</button></div>'
    +'<div class="b" style="color:var(--muted);font-size:13px">A kit is a curated bundle a customer adds to a quotation in one tap. Add products by name (each line respects that product\'s MOQ). Assign a kit to a collection, or leave blank to show it in every collection.</div></div>';
  var body=SFKITS.length?SFKITS.map(function(k,ki){return sfKitCard(k,ki);}).join(''):'<div class="card"><div class="b" style="color:var(--muted)">No kits yet. Add one — e.g. a "Restaurant Starter Kit".</div></div>';
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('kits')+dl+head+'<div style="height:14px"></div>'+body+'<button class="btn" onclick="sfKitAdd()" style="margin-top:12px">+ Add kit</button></div>';
}
function sfKitColOpts(sel){return '<option value="">Every collection</option>'+SFKCOLLS.map(function(c){return '<option value="'+sfj(c.id)+'"'+(sel===c.id?' selected':'')+'>'+esc(c.label)+'</option>';}).join('');}
function sfKitCard(k,ki){
  var lines=(k.lines||[]).map(function(l,li){return sfKitLine(l,ki,li);}).join('');
  var img=k.image_url?('<img class="sfphoto" src="'+esc(k.image_url)+'">'):('<div class="sfphoto">photo</div>');
  return '<div class="card" style="margin-bottom:14px"><div class="h">'
    +'<input class="sfin" value="'+esc(k.label||'')+'" placeholder="Kit name (Restaurant Starter Kit)" style="font-weight:700;max-width:250px" onchange="sfKitUpd('+ki+',\'label\',this.value)">'
    +'<label class="sfchk" style="margin-left:8px"><input type="checkbox" '+(k.active!==false?'checked':'')+' onchange="sfKitUpd('+ki+',\'active\',this.checked)"> Active</label>'
    +'<button class="btn sm danger" style="margin-left:auto" onclick="sfKitDel('+ki+')">Remove</button></div>'
    +'<div class="b">'
      +'<div class="sfrow" style="margin-bottom:10px">'
        +'<div style="text-align:center">'+img+'<div style="margin-top:5px"><button class="btn sm" onclick="sfKitPhoto('+ki+')">Photo</button>'+(k.image_url?' <button class="btn sm danger" onclick="sfKitClearPhoto('+ki+')">✕</button>':'')+'</div></div>'
        +'<div class="sffield" style="flex:1;min-width:180px"><label>Description</label><input class="sfin" value="'+esc(k.desc||'')+'" placeholder="Everything to get started" onchange="sfKitUpd('+ki+',\'desc\',this.value)"></div>'
        +'<div class="sffield"><label>Show in collection</label><select class="sfsel" onchange="sfKitUpd('+ki+',\'collection\',this.value)">'+sfKitColOpts(k.collection||'')+'</select></div>'
        +'<div class="sffield"><label>Button label</label><input class="sfin" style="width:150px" value="'+esc(k.cta_label||'')+'" placeholder="Add kit to quote" onchange="sfKitUpd('+ki+',\'cta_label\',this.value)"></div>'
      +'</div>'
      +'<div style="font-weight:700;font-size:13px;margin:2px 0 8px">Products in this kit</div>'+lines
      +'<button class="btn sm" style="margin-top:4px" onclick="sfKitAddLine('+ki+')">+ Add product</button>'
    +'</div></div>';
}
function sfKitLine(l,ki,li){
  return '<div class="sfrow" style="margin-bottom:6px"><input class="sfin" list="sfProdNames" style="flex:1;min-width:200px" value="'+esc(l.name||'')+'" placeholder="Product name (must match catalogue)" onchange="sfKitLineEd('+ki+','+li+',\'name\',this.value)"><input class="sfin" type="number" min="1" style="width:90px" value="'+(parseInt(l.qty,10)||1)+'" onchange="sfKitLineEd('+ki+','+li+',\'qty\',this.value)"><button class="btn sm danger" onclick="sfKitDelLine('+ki+','+li+')">✕</button></div>';
}
function sfK(ki){return SFKITS[ki];}
function sfKitUpd(ki,k,v){var it=sfK(ki);if(it)it[k]=v;}
function sfKitLineEd(ki,li,k,v){var it=sfK(ki);if(!it||!it.lines||!it.lines[li])return;it.lines[li][k]=(k==='qty')?Math.max(1,parseInt(v,10)||1):v;}
function sfKitAdd(){SFKITS.push({id:'kit'+(SFKITS.length+1)+'_'+Math.random().toString(36).slice(2,6),label:'New Kit',desc:'',image_url:'',active:true,collection:'',cta_label:'Add kit to quote',lines:[{name:'',qty:1}]});sfKitsRender();}
function sfKitDel(ki){if(!confirm('Remove this kit?'))return;SFKITS.splice(ki,1);sfKitsRender();}
function sfKitAddLine(ki){var it=sfK(ki);if(!it)return;it.lines=it.lines||[];it.lines.push({name:'',qty:1});sfKitsRender();}
function sfKitDelLine(ki,li){var it=sfK(ki);if(!it||!it.lines)return;it.lines.splice(li,1);sfKitsRender();}
function sfKitPhoto(ki){_sfPhoto={type:'kit',bi:ki};var f=sfHiddenFile();f.value='';f.click();}
function sfKitClearPhoto(ki){var it=sfK(ki);if(it){it.image_url='';sfKitsRender();}}
async function sfSaveKits(){
  SFKITS.forEach(function(k){k.lines=(k.lines||[]).filter(function(l){return l.name&&String(l.name).trim();});});
  var d=await post('/papi/kits-save',{kits:SFKITS});
  if(d&&d.ok){ if(Array.isArray(d.kits))SFKITS=d.kits; sfKitsRender(); toast('Kits saved'); } else toast((d&&d.error)||'Could not save',true);
}

boot();
</script>
</body>
</html>
