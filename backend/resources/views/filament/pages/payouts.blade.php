<x-filament-panels::page>
    @php $rows = $this->rows(); $cell = 'padding:10px 12px;border-bottom:1px solid rgba(127,127,127,.18);text-align:left;vertical-align:top'; @endphp
    <div style="display:flex;gap:8px">
        @foreach (['driver' => 'Drivers', 'fleet' => 'Fleets', 'store' => 'Stores'] as $key => $label)
            <x-filament::button wire:click="$set('tab', '{{ $key }}')" :color="$tab === $key ? 'primary' : 'gray'" size="sm">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    <x-filament::section>
        <x-slot name="heading">Balances</x-slot>
        <x-slot name="description">Positive: Ride Coop owes them (pay by bank transfer, then record it). Negative: they owe Ride Coop (commission on cash rides).</x-slot>
        @if (empty($rows))
            <p style="opacity:.7">Everyone is settled.</p>
        @else
            <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <thead><tr>
                    <th style="{{ $cell }}">Name</th><th style="{{ $cell }}">Bank details</th><th style="{{ $cell }};text-align:right">Balance</th><th style="{{ $cell }}"></th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td style="{{ $cell }}"><strong>{{ $r['name'] }}</strong><br><span style="opacity:.7">{{ $r['phone'] }}</span></td>
                        <td style="{{ $cell }}">{!! $r['bank'] ? e($r['bank']) : '<span style="color:#dc2626">Not given yet</span>' !!}</td>
                        <td style="{{ $cell }};text-align:right;white-space:nowrap;font-weight:600;color:{{ $r['balance'] >= 0 ? '#15803d' : '#dc2626' }}">
                            {{ $r['balance'] >= 0 ? 'Owed' : 'Owes' }} BBD {{ number_format(abs($r['balance']), 2) }}
                        </td>
                        <td style="{{ $cell }};text-align:right">{{ ($this->recordAction)(['type' => $r['type'], 'id' => $r['id'], 'balance' => $r['balance'], 'name' => $r['name']]) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Recent payouts</x-slot>
        @forelse ($this->recent() as $p)
            <div style="padding:6px 0;border-bottom:1px solid rgba(127,127,127,.18);font-size:14px">
                {{ $p->created_at->format('j M Y') }} · <strong>{{ $p->payeeName() }}</strong> ·
                {{ $p->amount >= 0 ? 'paid' : 'received' }} BBD {{ number_format(abs($p->amount), 2) }} · {{ str_replace('_', ' ', $p->method) }}{{ $p->reference ? ' · '.$p->reference : '' }}
                <span style="opacity:.6">by {{ $p->creator?->name }}</span>
            </div>
        @empty
            <p style="opacity:.7">None yet.</p>
        @endforelse
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
