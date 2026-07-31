<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="manifest" href="/v3-manifest.webmanifest">
<meta name="theme-color" content="#1B3A2B">
<!-- iPhone/iPad "Add to Home Screen": launch full-screen (standalone), not a Safari tab.
     viewport-fit=cover above activates the env(safe-area-inset-*) padding already in the CSS. -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="CloudBSS">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>CloudBSS</title>
{{-- V3 assets are static files (V3-FIRST.md §split). Served from public/assets/v3/ when present,
     else the /assets/v3/{file} fallback route streams the resources/v3/ copy — either is enough.
     filemtime (of whichever copy exists) = automatic cache-busting, no manual version bumps. --}}
<link rel="stylesheet" href="/assets/v3/shell.css?v={{ @filemtime(public_path('assets/v3/shell.css')) ?: (@filemtime(resource_path('v3/shell.css')) ?: 1) }}">
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
      <button class="posbtn" id="posTop" onclick="route('pos')" title="Open the till" style="display:none">
        <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 3h16v9H4zM8 12v3M16 12v3M2 15h20v6H2zM6 18h5"/></svg><span>POS</span>
      </button>
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
<div class="ptr" id="ptr" aria-hidden="true"><div class="ptr-ring"></div></div>

<!-- ===== Mobile workspaces bar (role-aware, JS-rendered) + context-aware create (phones only) ===== -->
<nav class="botnav" id="botnav"></nav>
<button class="fab" id="fab" onclick="openQuickAdd()" aria-label="Create">
  <svg class="ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></button>
<div class="qsheet-ov" id="qsheet" onclick="if(event.target===this)closeQuickAdd()">
  <div class="qsheet" role="dialog" aria-label="Create new">
    <div class="grab"></div>
    <h4 id="qsheetTitle">Create</h4>
    <div class="qgrid" id="qgrid"></div>
  </div>
</div>

<script src="/assets/v3/shell.js?v={{ @filemtime(public_path('assets/v3/shell.js')) ?: (@filemtime(resource_path('v3/shell.js')) ?: 1) }}"></script>
</body>
</html>
