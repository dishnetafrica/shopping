<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- LEFT: search + results --}}
        <div class="space-y-4">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search product, SKU or barcode…"
                />
            </x-filament::input.wrapper>

            <div class="space-y-2 overflow-y-auto" style="max-height: 60vh;">
                @forelse ($results as $r)
                    <button type="button" wire:click="add({{ $r['id'] }})"
                        class="flex w-full items-center justify-between rounded-lg border border-gray-200 p-3 text-left hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $r['name'] }}</span>
                        <span class="text-sm font-semibold text-primary-600">UGX {{ number_format($r['price']) }}</span>
                    </button>
                @empty
                    <p class="text-sm text-gray-500">Type at least 2 characters to search the catalogue.</p>
                @endforelse
            </div>
        </div>

        {{-- RIGHT: cart --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">Cart</h3>

            @forelse ($cart as $id => $l)
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 py-2 dark:border-white/5">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $l['name'] }}</div>
                        <div class="text-xs text-gray-500">UGX {{ number_format($l['price']) }}</div>
                    </div>
                    <div class="flex items-center gap-1">
                        <x-filament::icon-button icon="heroicon-m-minus" wire:click="dec({{ $id }})" label="Decrease" size="sm" />
                        <span class="w-6 text-center text-sm font-semibold">{{ $l['qty'] }}</span>
                        <x-filament::icon-button icon="heroicon-m-plus" wire:click="inc({{ $id }})" label="Increase" size="sm" />
                        <x-filament::icon-button icon="heroicon-m-trash" color="danger" wire:click="remove({{ $id }})" label="Remove" size="sm" />
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Cart is empty. Search and tap a product to add it.</p>
            @endforelse

            <div class="mt-4 flex items-center justify-between text-xl font-bold text-gray-900 dark:text-white">
                <span>Total</span>
                <span>UGX {{ number_format($this->total()) }}</span>
            </div>

            <div class="mt-4 space-y-2">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="customerPhone" placeholder="Customer phone (optional)" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="payment">
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="card">Card</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <x-filament::button wire:click="complete" class="mt-4 w-full" size="lg">
                Complete sale
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
