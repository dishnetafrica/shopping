<x-filament-panels::page.simple>
    @if (! $sent)
        {{-- Step 1: phone --}}
        <form wire:submit="sendCode" class="space-y-4">
            <div>
                <label for="otp-phone" class="block text-sm font-medium leading-6">
                    WhatsApp number
                </label>
                <input
                    id="otp-phone"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel"
                    wire:model="phone"
                    placeholder="e.g. 256700123456"
                    class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-gray-800 dark:border-gray-600"
                    required
                />
                @error('phone')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-xs text-gray-500">
                    We'll send a 6-digit login code to this number on WhatsApp.
                </p>
            </div>

            <x-filament::button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="sendCode">
                <span wire:loading.remove wire:target="sendCode">Send code</span>
                <span wire:loading wire:target="sendCode">Sending…</span>
            </x-filament::button>
        </form>
    @else
        {{-- Step 2: code --}}
        <form wire:submit="confirm" class="space-y-4">
            <div>
                <label for="otp-code" class="block text-sm font-medium leading-6">
                    Enter the 6-digit code
                </label>
                <input
                    id="otp-code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    wire:model="code"
                    placeholder="● ● ● ● ● ●"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-center text-2xl tracking-[0.5em] shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-gray-800 dark:border-gray-600"
                    required
                />
                @error('code')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-xs text-gray-500">
                    Sent on WhatsApp to {{ $phone }}. The code expires in 5 minutes.
                </p>
            </div>

            <x-filament::button type="submit" class="w-full" wire:loading.attr="disabled" wire:target="confirm">
                <span wire:loading.remove wire:target="confirm">Sign in</span>
                <span wire:loading wire:target="confirm">Verifying…</span>
            </x-filament::button>

            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="startOver" class="text-gray-500 hover:underline">
                    ← Change number
                </button>
                <button type="button" wire:click="resend" class="text-primary-600 hover:underline"
                        wire:loading.attr="disabled" wire:target="resend">
                    Resend code
                </button>
            </div>
        </form>
    @endif
</x-filament-panels::page.simple>
