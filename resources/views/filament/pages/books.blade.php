<x-filament-panels::page>
    @if (! ($enabled ?? false))
        <x-filament::section>
            <div class="text-center py-8">
                <p class="text-lg font-medium">Automatic books are off</p>
                <p class="text-gray-500 mt-1">
                    Turn on <strong>Keep automatic books</strong> in
                    <a href="{{ \App\Filament\Pages\Settings::getUrl() }}" class="text-primary-600 underline">Settings → Accounting / Ledger</a>
                    to see your trial balance, debtor aging and statements here.
                </p>
            </div>
        </x-filament::section>
    @elseif (! empty($error))
        <x-filament::section>
            <p class="text-danger-600">Couldn't load the books: {{ $error }}</p>
            <p class="text-gray-500 text-sm mt-1">If you just enabled books, deliver an order first — there may be nothing posted yet.</p>
        </x-filament::section>
    @else
        {{-- headline numbers --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-filament::section>
                <div class="text-sm text-gray-500">Net profit</div>
                <div class="text-2xl font-bold {{ ($pl['net_raw'] ?? 0) < 0 ? 'text-danger-600' : 'text-success-600' }}">
                    {{ $currency }} {{ $pl['net'] }}
                </div>
                <div class="text-xs text-gray-400 mt-1">income {{ $currency }} {{ $pl['income'] }} − expense {{ $currency }} {{ $pl['expense'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Owed to you (receivables)</div>
                <div class="text-2xl font-bold">{{ $currency }} {{ $receivables }}</div>
                <div class="text-xs text-gray-400 mt-1">you owe suppliers {{ $currency }} {{ $payables }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-sm text-gray-500">Books status</div>
                <div class="text-2xl font-bold {{ ($trial['balanced'] ?? false) ? 'text-success-600' : 'text-danger-600' }}">
                    {{ ($trial['balanced'] ?? false) ? 'Balanced' : 'Out of balance' }}
                </div>
                <div class="text-xs text-gray-400 mt-1">Dr {{ $trial['debit'] }} = Cr {{ $trial['credit'] }}</div>
            </x-filament::section>
        </div>

        {{-- debtors aging --}}
        <x-filament::section>
            <x-slot name="heading">Debtors — who owes you</x-slot>
            @if (count($debtors))
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2 pr-4">Customer</th>
                                <th class="py-2 px-2 text-right">Current</th>
                                <th class="py-2 px-2 text-right">1–30</th>
                                <th class="py-2 px-2 text-right">31–60</th>
                                <th class="py-2 px-2 text-right">61–90</th>
                                <th class="py-2 px-2 text-right">90+</th>
                                <th class="py-2 px-2 text-right font-semibold">Total</th>
                                <th class="py-2 pl-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($debtors as $d)
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 pr-4">{{ $d['name'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $d['current'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $d['d1_30'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $d['d31_60'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $d['d61_90'] }}</td>
                                    <td class="py-2 px-2 text-right text-danger-600">{{ $d['d90_plus'] }}</td>
                                    <td class="py-2 px-2 text-right font-semibold">{{ $d['total'] }}</td>
                                    <td class="py-2 pl-2 text-right">
                                        <x-filament::button
                                            size="xs" color="gray" icon="heroicon-o-paper-airplane"
                                            wire:click="sendStatement('{{ $d['party'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendStatement('{{ $d['party'] }}')">
                                            Statement
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-gray-500">No outstanding receivables — everyone's paid up. 🎉</p>
            @endif
        </x-filament::section>

        {{-- creditors aging --}}
        @if (count($creditors))
            <x-filament::section>
                <x-slot name="heading">Creditors — who you owe</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2 pr-4">Supplier</th>
                                <th class="py-2 px-2 text-right">Current</th>
                                <th class="py-2 px-2 text-right">1–30</th>
                                <th class="py-2 px-2 text-right">31–60</th>
                                <th class="py-2 px-2 text-right">61–90</th>
                                <th class="py-2 px-2 text-right">90+</th>
                                <th class="py-2 pl-2 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($creditors as $c)
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 pr-4">{{ $c['name'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $c['current'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $c['d1_30'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $c['d31_60'] }}</td>
                                    <td class="py-2 px-2 text-right">{{ $c['d61_90'] }}</td>
                                    <td class="py-2 px-2 text-right text-danger-600">{{ $c['d90_plus'] }}</td>
                                    <td class="py-2 pl-2 text-right font-semibold">{{ $c['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- trial balance --}}
        <x-filament::section>
            <x-slot name="heading">Trial balance</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Account</th>
                            <th class="py-2 px-2">Type</th>
                            <th class="py-2 px-2 text-right">Debit</th>
                            <th class="py-2 pl-2 text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($trial['rows'] as $r)
                            <tr class="border-b border-gray-100">
                                <td class="py-2 pr-4">{{ $r['account'] }} · {{ $r['name'] }}</td>
                                <td class="py-2 px-2 text-gray-500">{{ $r['type'] }}</td>
                                <td class="py-2 px-2 text-right">{{ $r['debit'] }}</td>
                                <td class="py-2 pl-2 text-right">{{ $r['credit'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="py-2 pr-4">Totals</td>
                            <td></td>
                            <td class="py-2 px-2 text-right">{{ $trial['debit'] }}</td>
                            <td class="py-2 pl-2 text-right">{{ $trial['credit'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
