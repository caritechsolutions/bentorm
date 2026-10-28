<x-filament-panels::page>
    @php $b = $this->balance(); $fleet = $this->fleet(); $cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.18)'; @endphp
    <x-filament::section>
        <div style="font-size:14px;opacity:.7">{{ $b >= 0 ? 'Ride Coop owes you' : 'You owe Ride Coop' }}</div>
        <div style="font-size:32px;font-weight:700">BBD {{ number_format(abs($b), 2) }}</div>
        <div style="font-size:14px;opacity:.7">What your cars earned (card trips, fees, tips) less Ride Coop's commission on cash trips, less payouts.
            {{ $fleet->bank_account_number ? 'Paid to '.$fleet->bank_name.' ••••'.substr(preg_replace('/\D/', '', $fleet->bank_account_number), -4).'.' : 'Add your bank account in Fleet settings → Payouts.' }}</div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Cash your drivers are holding</x-slot>
        <x-slot name="description">Cash trips are paid to the driver. Record it here when they hand it over to you.</x-slot>
        @php $cash = $this->cash(); @endphp
        @if (empty($cash))
            <p style="opacity:.7">No cash trips yet.</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                @foreach ($cash as $c)
                    <tr>
                        <td style="{{ $cell }}">{{ $c['name'] }}</td>
                        <td style="{{ $cell }};text-align:right;font-weight:600;color:{{ $c['held'] > 0 ? '#b45309' : 'inherit' }}">{{ number_format($c['held'], 2) }}</td>
                        <td style="{{ $cell }};text-align:right">
                            @if ($c['held'] > 0)
                                {{ ($this->receivedAction)(['driver_id' => $c['driver_id'], 'name' => $c['name'], 'held' => $c['held']]) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">Activity</x-slot>
        @php $entries = $this->entries(); @endphp
        @if ($entries->isEmpty())
            <p style="opacity:.7">Trips in your cars show here.</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                @foreach ($entries as $e)
                    <tr>
                        <td style="{{ $cell }};white-space:nowrap">{{ $e->created_at?->format('j M Y, g:i a') }}</td>
                        <td style="{{ $cell }}">{{ $e->description }}</td>
                        <td style="{{ $cell }};text-align:right;font-weight:600;color:{{ $e->amount >= 0 ? '#15803d' : '#dc2626' }}">{{ $e->amount >= 0 ? '+' : '−' }}{{ number_format(abs($e->amount), 2) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Cash log</x-slot>
        <table style="width:100%;border-collapse:collapse;font-size:14px">
            @forelse ($this->cashEntries() as $e)
                <tr>
                    <td style="{{ $cell }};white-space:nowrap">{{ $e->created_at?->format('j M Y, g:i a') }}</td>
                    <td style="{{ $cell }}">{{ $e->driver?->name }}</td>
                    <td style="{{ $cell }}">{{ $e->description }}</td>
                    <td style="{{ $cell }};text-align:right">{{ $e->amount >= 0 ? '+' : '−' }}{{ number_format(abs($e->amount), 2) }}</td>
                </tr>
            @empty
                <tr><td style="opacity:.7">Nothing yet.</td></tr>
            @endforelse
        </table>
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
