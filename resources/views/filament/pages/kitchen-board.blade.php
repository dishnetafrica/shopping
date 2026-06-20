<x-filament-panels::page>
    <div
        x-data="{
            newCount: @js(count($this->columns['New'] ?? [])),
            ping(n) {
                if (n > this.newCount) {
                    try {
                        const a = new (window.AudioContext || window.webkitAudioContext)();
                        const o = a.createOscillator(); const g = a.createGain();
                        o.connect(g); g.connect(a.destination);
                        o.frequency.value = 880; g.gain.value = 0.08;
                        o.start(); o.stop(a.currentTime + 0.25);
                    } catch (e) {}
                }
                this.newCount = n;
            }
        }"
        x-effect="ping(@js(count($this->columns['New'] ?? [])))"
        wire:poll.15s
    >
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-5">
            @php
                $headColors = [
                    'New' => 'bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-white',
                    'Accepted' => 'bg-blue-100 text-blue-800',
                    'Preparing' => 'bg-amber-100 text-amber-800',
                    'Ready' => 'bg-green-100 text-green-800',
                    'Dispatched' => 'bg-indigo-100 text-indigo-800',
                ];
            @endphp

            @foreach (\App\Filament\Pages\KitchenBoard::BOARD as $col)
                @php $tickets = $this->columns[$col] ?? []; @endphp
                <div class="rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="flex items-center justify-between rounded-t-xl px-3 py-2 text-sm font-bold {{ $headColors[$col] ?? '' }}">
                        <span>{{ $col }}</span>
                        <span class="rounded-full bg-white/70 px-2 text-xs dark:bg-black/30">{{ count($tickets) }}</span>
                    </div>

                    <div class="space-y-3 p-2" style="min-height: 60vh;">
                        @forelse ($tickets as $t)
                            <div @class([
                                'rounded-lg border p-3 shadow-sm',
                                'border-red-300 bg-red-50 dark:bg-red-500/10' => $col === 'New' && $t['mins'] >= 10,
                                'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => !($col === 'New' && $t['mins'] >= 10),
                            ])>
                                <div class="mb-1 flex items-center justify-between">
                                    <span class="text-sm font-bold text-gray-900 dark:text-white">{{ $t['order_no'] }}</span>
                                    <span @class([
                                        'text-xs font-semibold',
                                        'text-red-600' => $t['mins'] >= 15,
                                        'text-gray-500' => $t['mins'] < 15,
                                    ])>{{ $t['mins'] }}m</span>
                                </div>

                                @if ($t['name'] || $t['phone'])
                                    <div class="mb-1 truncate text-xs text-gray-500">
                                        {{ $t['name'] ?: 'Customer' }} @if($t['phone']) · {{ $t['phone'] }} @endif
                                    </div>
                                @endif

                                <ul class="mb-2 space-y-0.5 text-sm text-gray-800 dark:text-gray-100">
                                    @foreach ($t['items'] as $it)
                                        <li>
                                            <span class="font-semibold">{{ $it['qty'] }}×</span> {{ $it['name'] }}
                                            @if ($it['notes'])
                                                <span class="block pl-4 text-xs font-medium text-amber-700 dark:text-amber-400">↳ {{ $it['notes'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($t['notes'])
                                    <div class="mb-2 rounded bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                        📝 {{ $t['notes'] }}
                                    </div>
                                @endif

                                <div class="flex flex-wrap gap-1">
                                    @if ($t['next'])
                                        <x-filament::button size="xs" color="primary" wire:click="advance({{ $t['id'] }})">
                                            {{ $t['next'] }}
                                        </x-filament::button>
                                    @endif
                                    @if ($col === 'New')
                                        <x-filament::button size="xs" color="danger" wire:click="reject({{ $t['id'] }})">
                                            Reject
                                        </x-filament::button>
                                    @else
                                        <x-filament::button size="xs" color="gray" wire:click="cancel({{ $t['id'] }})">
                                            Cancel
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="px-1 py-4 text-center text-xs text-gray-400">—</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
