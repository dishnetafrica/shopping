<x-filament-panels::page.simple>
    <style>
        body { background:
            radial-gradient(900px 500px at 50% -10%, rgba(16,132,87,.16), transparent 60%),
            radial-gradient(700px 500px at 110% 110%, rgba(16,132,87,.10), transparent 55%),
            #f6f8f7 !important; }
        .dark body { background:
            radial-gradient(900px 500px at 50% -10%, rgba(16,132,87,.22), transparent 60%),
            #0b1220 !important; }
        .cb-tag { text-align:center; color:#6b7280; font-size:.9rem; margin:-.25rem 0 1.25rem; }
        .cb-field label { display:block; font-size:.85rem; font-weight:600; margin-bottom:.35rem; }
        .cb-input { width:100%; border-radius:12px; border:1px solid #d1d5db; padding:.8rem .85rem;
            font-size:1.05rem; box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .cb-input:focus { outline:none; border-color:#0f8457; box-shadow:0 0 0 3px rgba(16,132,87,.18); }
        .dark .cb-input { background:#1f2937; border-color:#374151; color:#e5e7eb; }
        .cb-help { margin-top:.45rem; font-size:.8rem; color:#6b7280; }
        .cb-err { margin-top:.35rem; font-size:.82rem; color:#dc2626; }
        .cb-code { text-align:center; font-size:1.7rem; letter-spacing:.55em; padding-left:.55em; }
        .cb-links { display:flex; justify-content:space-between; font-size:.85rem; margin-top:.25rem; }
        .cb-links button { background:none; border:0; cursor:pointer; }
        .cb-alt { text-align:center; margin-top:1.1rem; padding-top:1rem; border-top:1px solid rgba(0,0,0,.07); }
        .dark .cb-alt { border-color:rgba(255,255,255,.08); }
        .cb-alt button { background:none; border:0; cursor:pointer; color:#6b7280; font-size:.82rem; }
        .cb-alt button:hover { color:#0f8457; text-decoration:underline; }
    </style>

    @if ($method === 'whatsapp')
        @if (! $sent)
            {{-- Step 1 — phone --}}
            <p class="cb-tag">Sign in with your WhatsApp number. We'll text you a code.</p>
            <form wire:submit="sendCode" class="space-y-4">
                <div class="cb-field">
                    <label for="otp-phone">WhatsApp number</label>
                    <input id="otp-phone" type="tel" inputmode="tel" autocomplete="tel" autofocus
                           wire:model="phone" placeholder="e.g. 256700123456" class="cb-input" required
                           x-data x-init="try{ const p = localStorage.getItem('cb_last_phone'); if (p && ! $wire.phone) $wire.set('phone', p); $el.focus(); }catch(e){}" />
                    @error('phone') <p class="cb-err">{{ $message }}</p> @enderror
                    <p class="cb-help">A 6-digit code will arrive on WhatsApp.</p>
                </div>
                <x-filament::button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="sendCode"
                                    x-on:click="try{ localStorage.setItem('cb_last_phone', $wire.phone || ''); }catch(e){}">
                    <span wire:loading.remove wire:target="sendCode">Send code</span>
                    <span wire:loading wire:target="sendCode">Sending…</span>
                </x-filament::button>
            </form>

            <div class="cb-alt">
                <button type="button" wire:click="useEmail">Trouble with WhatsApp? Sign in with email</button>
            </div>
        @else
            {{-- Step 2 — code (auto-submits at 6 digits) --}}
            <p class="cb-tag">Enter the code we sent to {{ $phone }}.</p>
            <form wire:submit="confirm" class="space-y-4">
                <div class="cb-field">
                    <label for="otp-code">6-digit code</label>
                    <input id="otp-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           wire:model="code" placeholder="● ● ● ● ● ●" class="cb-input cb-code" required
                           x-data x-init="$nextTick(() => $el.focus())"
                           x-on:input="$wire.set('code', $el.value).then(() => { if (($el.value || '').replace(/\D/g,'').length >= 6) $wire.confirm(); })" />
                    @error('code') <p class="cb-err">{{ $message }}</p> @enderror
                    <p class="cb-help">Expires in 5 minutes.</p>
                </div>
                <x-filament::button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="confirm">
                    <span wire:loading.remove wire:target="confirm">Sign in</span>
                    <span wire:loading wire:target="confirm">Verifying…</span>
                </x-filament::button>
                <div class="cb-links">
                    <button type="button" wire:click="startOver" style="color:#6b7280">← Change number</button>
                    <button type="button" wire:click="resend" style="color:#0f8457" wire:loading.attr="disabled" wire:target="resend">Resend code</button>
                </div>
            </form>
        @endif
    @else
        {{-- Email fallback (only if they ask for it) --}}
        <p class="cb-tag">Sign in with your email and password.</p>
        <form wire:submit="loginWithEmail" class="space-y-4">
            <div class="cb-field">
                <label for="em-email">Email</label>
                <input id="em-email" type="email" inputmode="email" autocomplete="username" autofocus
                       wire:model="email" placeholder="you@shop.com" class="cb-input" required />
                @error('email') <p class="cb-err">{{ $message }}</p> @enderror
            </div>
            <div class="cb-field">
                <label for="em-pass">Password</label>
                <input id="em-pass" type="password" autocomplete="current-password"
                       wire:model="password" placeholder="••••••••" class="cb-input" required />
                @error('password') <p class="cb-err">{{ $message }}</p> @enderror
                <p class="cb-help">No password yet? Use WhatsApp — it's easier.</p>
            </div>
            <x-filament::button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="loginWithEmail">
                <span wire:loading.remove wire:target="loginWithEmail">Sign in</span>
                <span wire:loading wire:target="loginWithEmail">Signing in…</span>
            </x-filament::button>
        </form>
        <div class="cb-alt">
            <button type="button" wire:click="useWhatsapp">← Back to WhatsApp sign-in</button>
        </div>
    @endif
</x-filament-panels::page.simple>
