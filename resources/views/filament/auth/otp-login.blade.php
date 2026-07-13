<x-filament-panels::page.simple>
{{--
    CloudBSS v3 sign-in — full-bleed split screen (brand left, form right), matching the v3 prototype.
    Rendered inside Filament's simple page, but a fixed inset:0 overlay covers the default chrome so the
    layout is edge-to-edge. All auth wiring is UNCHANGED: the same Livewire actions the OtpLogin page
    already exposes (loginWithEmail / sendCode / confirm / resend / useWhatsapp / useEmail).
--}}
<style>
#cb-login{--bg:#f6f7f9;--surface:#fff;--line:#e7e9ed;--ink:#151b23;--ink-2:#3c4756;--muted:#6b7684;--faint:#98a1ad;
  --brand:#16a34a;--brand-ink:#15803d;--brand-wash:#eafaf0;--radius-sm:7px;
  --font:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  position:fixed;inset:0;z-index:99999;display:flex;background:var(--bg);font-family:var(--font);color:var(--ink);
  font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
#cb-login *{box-sizing:border-box}
#cb-login .ic{width:18px;height:18px;stroke:currentColor;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round;flex:none}
#cb-login .ic-sm{width:15px;height:15px}
#cb-login .brandside{flex:1;background:linear-gradient(150deg,#0e1b16,#13342a 60%,#16543f);color:#fff;display:flex;flex-direction:column;justify-content:space-between;padding:52px}
#cb-login .brandside .logo{display:flex;align-items:center;gap:11px;font-weight:700;font-size:19px;letter-spacing:-.02em}
#cb-login .brandside .logo .mark{width:34px;height:34px;border-radius:9px;background:var(--brand);display:grid;place-items:center;color:#fff}
#cb-login .brandside h1{font-size:38px;line-height:1.15;font-weight:700;letter-spacing:-.03em;max-width:16ch;margin-bottom:16px}
#cb-login .brandside p{color:#a9c4b7;max-width:40ch;font-size:15px}
#cb-login .brandside .feats{display:flex;flex-direction:column;gap:14px}
#cb-login .brandside .feats div{display:flex;align-items:center;gap:11px;color:#cde4d9;font-size:14px}
#cb-login .brandside .feats .dot{width:26px;height:26px;border-radius:7px;background:rgba(255,255,255,.09);display:grid;place-items:center;color:#7fe0aa}
#cb-login .formside{width:min(46%,520px);background:var(--surface);display:flex;flex-direction:column;justify-content:center;padding:0 clamp(32px,6vw,88px);overflow-y:auto}
#cb-login .formside h2{font-size:23px;font-weight:700;letter-spacing:-.02em}
#cb-login .formside .sub{color:var(--muted);margin:6px 0 26px}
#cb-login .field{margin-bottom:16px}
#cb-login .field label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-2);margin-bottom:6px}
#cb-login .field .inp{position:relative}
#cb-login .field .inp>svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--faint)}
#cb-login .field input{width:100%;height:42px;border:1px solid var(--line);border-radius:var(--radius-sm);padding:0 12px 0 38px;background:var(--surface);transition:.15s;color:var(--ink);font-size:14px}
#cb-login .field input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-wash)}
#cb-login .field input.code{letter-spacing:.5em;text-align:center;font-size:18px;padding-left:12px;font-variant-numeric:tabular-nums}
#cb-login .hint{background:var(--brand-wash);border:1px solid #c8ecd6;color:var(--brand-ink);border-radius:var(--radius-sm);padding:10px 12px;font-size:12.5px;margin-bottom:20px;display:flex;gap:8px;align-items:center}
#cb-login .hint b{font-weight:700}
#cb-login .hint a{color:var(--brand-ink);font-weight:700;text-decoration:underline;cursor:pointer}
#cb-login .cbtn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;height:44px;border-radius:8px;font-weight:600;font-size:14.5px;border:1px solid var(--brand);background:var(--brand);color:#fff;transition:.12s;cursor:pointer}
#cb-login .cbtn:hover{background:var(--brand-ink);border-color:var(--brand-ink)}
#cb-login .cbtn[disabled]{opacity:.65;cursor:default}
#cb-login .err{margin-top:6px;font-size:12px;color:#dc2626}
#cb-login .help{margin-top:7px;font-size:12px;color:var(--muted)}
#cb-login .links{display:flex;justify-content:space-between;margin-top:12px;font-size:12.5px}
#cb-login .links button{background:none;border:0;cursor:pointer;font-weight:600}
#cb-login .alt{margin-top:20px;padding-top:16px;border-top:1px solid var(--line);text-align:center}
#cb-login .alt button{background:none;border:0;cursor:pointer;color:var(--muted);font-size:12.5px}
#cb-login .alt button:hover{color:var(--brand-ink);text-decoration:underline}
#cb-login .foot{margin-top:22px;font-size:12.5px;text-align:center;color:var(--muted)}
@media(max-width:820px){
  #cb-login{flex-direction:column}
  #cb-login .brandside{padding:32px 28px;flex:none}
  #cb-login .brandside h1{font-size:28px}
  #cb-login .brandside .feats{display:none}
  #cb-login .formside{width:100%;flex:1;padding:32px 28px;justify-content:flex-start}
}
</style>

<div id="cb-login">
  <div class="brandside">
    <div class="logo">
      <span class="mark"><svg class="ic" viewBox="0 0 24 24"><path d="M3 9l9-6 9 6v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 21V12h6v9"/></svg></span> CloudBSS
    </div>
    <div>
      <h1>The operating system for trading businesses.</h1>
      <p>Orders, inventory, invoicing, payments and cash — one place, one workflow, built for wholesale trade across East Africa.</p>
    </div>
    <div class="feats">
      <div><span class="dot"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg></span> Real-time cash position in USD, UGX &amp; SSP</div>
      <div><span class="dot"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg></span> Quotation → Order → Invoice → Payment in one flow</div>
      <div><span class="dot"><svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg></span> Live stock &amp; margin on every sale</div>
    </div>
  </div>

  <div class="formside">
    <h2>Sign in to CloudBSS</h2>
    <div class="sub">Welcome back. Enter your details to continue.</div>

    @if ($method !== 'whatsapp')
      {{-- ===== Email + password (default, matches the design) ===== --}}
      <div class="hint">
        <svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        <span>No password yet? <a wire:click.prevent="useWhatsapp">Sign in with WhatsApp</a> — no password needed.</span>
      </div>
      <form wire:submit="loginWithEmail">
        <div class="field">
          <label for="cb-email">Work email</label>
          <div class="inp">
            <svg class="ic ic-sm" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
            <input id="cb-email" type="email" inputmode="email" autocomplete="username" autofocus
                   wire:model="email" placeholder="you@company.com" required>
          </div>
          @error('email') <div class="err">{{ $message }}</div> @enderror
        </div>
        <div class="field">
          <label for="cb-pass">Password</label>
          <div class="inp">
            <svg class="ic ic-sm" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input id="cb-pass" type="password" autocomplete="current-password"
                   wire:model="password" placeholder="••••••••" required>
          </div>
          @error('password') <div class="err">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="cbtn" wire:loading.attr="disabled" wire:target="loginWithEmail">
          <span wire:loading.remove wire:target="loginWithEmail">Sign in
            <svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
          <span wire:loading wire:target="loginWithEmail">Signing in…</span>
        </button>
      </form>
      <div class="foot">CloudBSS v3 · secure sign-in</div>

    @elseif (! $sent)
      {{-- ===== WhatsApp — step 1: phone ===== --}}
      <div class="hint">
        <svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        <span>We'll text a <b>6-digit code</b> to your WhatsApp — no password needed.</span>
      </div>
      <form wire:submit="sendCode">
        <div class="field">
          <label for="cb-phone">WhatsApp number</label>
          <div class="inp">
            <svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M21 15.5a2 2 0 0 1-2 2A16 16 0 0 1 4 4a2 2 0 0 1 2-2h2l1.5 4.5L8 8a11 11 0 0 0 5 5l1.5-1.5L19 13v2.5z"/></svg>
            <input id="cb-phone" type="tel" inputmode="tel" autocomplete="tel" autofocus
                   wire:model="phone" placeholder="e.g. 256700123456" required
                   x-data x-init="try{ const p=localStorage.getItem('cb_last_phone'); if(p && !$wire.phone) $wire.set('phone',p); $el.focus(); }catch(e){}">
          </div>
          @error('phone') <div class="err">{{ $message }}</div> @enderror
          <div class="help">A 6-digit code will arrive on WhatsApp.</div>
        </div>
        <button type="submit" class="cbtn" wire:loading.attr="disabled" wire:target="sendCode"
                x-on:click="try{ localStorage.setItem('cb_last_phone', $wire.phone||''); }catch(e){}">
          <span wire:loading.remove wire:target="sendCode">Send code</span>
          <span wire:loading wire:target="sendCode">Sending…</span>
        </button>
      </form>
      <div class="alt"><button type="button" wire:click="useEmail">← Sign in with email and password</button></div>

    @else
      {{-- ===== WhatsApp — step 2: code (auto-submits at 6 digits) ===== --}}
      <div class="hint">
        <svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        <span>Enter the code we sent to <b>{{ $phone }}</b>.</span>
      </div>
      <form wire:submit="confirm">
        <div class="field">
          <label for="cb-code">6-digit code</label>
          <div class="inp">
            <input id="cb-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   wire:model="code" placeholder="● ● ● ● ● ●" class="code" required style="padding-left:12px"
                   x-data x-init="$nextTick(() => $el.focus())"
                   x-on:input="$wire.set('code', $el.value).then(() => { if(($el.value||'').replace(/\D/g,'').length >= 6) $wire.confirm(); })">
          </div>
          @error('code') <div class="err">{{ $message }}</div> @enderror
          <div class="help">Expires in 5 minutes.</div>
        </div>
        <button type="submit" class="cbtn" wire:loading.attr="disabled" wire:target="confirm">
          <span wire:loading.remove wire:target="confirm">Sign in
            <svg class="ic ic-sm" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
          <span wire:loading wire:target="confirm">Verifying…</span>
        </button>
        <div class="links">
          <button type="button" wire:click="startOver" style="color:var(--muted)">← Change number</button>
          <button type="button" wire:click="resend" style="color:var(--brand-ink)" wire:loading.attr="disabled" wire:target="resend">Resend code</button>
        </div>
      </form>
    @endif
  </div>
</div>
</x-filament-panels::page.simple>
